<?php

use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\guard_min_version;
use function Castor\import;
use function Castor\io;
use function Castor\notify;
use function Castor\variable;
use function docker\about;
use function docker\build;
use function docker\docker_compose_run;
use function docker\up;
use function docker\workers_start;

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', true);

guard_min_version('1.9.0');

import(__DIR__ . '/.castor');

/**
 * @return array{project_name: string, root_domain: string, extra_domains: string[], php_version: string}
 */
function create_default_variables(): array
{
    $projectName = 'lairdubois';
    $tld = 'test';

    return [
        'project_name' => $projectName,
        'root_domain' => "{$projectName}.{$tld}",
        'extra_domains' => [],
        'php_version' => '8.5',
    ];
}

#[AsTask(description: 'Builds and starts the infrastructure, then installs the application (composer, assets, database, fixtures if empty, index, workers)')]
function start(): void
{
    io()->title('Starting the stack');

    build();
    install();
    assets_build();
    up(profiles: ['default']); // We can't start worker now, they are not installed
    db_schema();
    // A new copy gets the fixtures, to be browsable at once: a database with accounts is kept as is
    if (db_has_users()) {
        elasticsearch_populate();
    } else {
        db_fixtures();
    }
    workers_start();

    notify('The stack is now up and running.');
    io()->success('The stack is now up and running.');

    about();
}

#[AsTask(description: 'Installs the application (composer, throwaway DKIM and VAPID keys)', namespace: 'app', aliases: ['install'])]
function install(): void
{
    io()->title('Installing the application');

    // Most of the locked packages only declare PHP 7 ("php": "^7.1"...), the code
    // runs in PHP 8.5 anyway: "php+" ignores their upper bound, not their lower one.
    // The composer scripts also write app/config/parameters.yml, install the
    // bundles assets and clear the cache.
    docker_compose_run(['composer', 'install', '-n', '--prefer-dist', '--optimize-autoloader', '--ignore-platform-req=php+']);

    // MailerUtils signs every email with the DKIM key of the production server (keys/private.pem,
    // out of git): without a key, sending an email fails. A throwaway one for dev and test.
    docker_compose_run(['sh', '-c', 'test -f keys/private.pem || (mkdir -p keys && openssl genrsa -out keys/private.pem 2048)']);

    // The web push notifications are signed with the VAPID keys of the production server
    // (parameters.yml, out of git): with the placeholders of parameters.yml.dist, the
    // webpush_notification consumer stops on its first message. A throwaway pair for dev and
    // test, that the next composer install keeps.
    docker_compose_run(['php', '-r', <<<'PHP'
        require 'vendor/autoload.php';
        $file = 'app/config/parameters.yml';
        $keys = Minishlink\WebPush\VAPID::createVapidKeys();
        file_put_contents($file, strtr(file_get_contents($file), [
            'YOUR_WEBPUSH_PUBLIC_KEY' => $keys['publicKey'],
            'YOUR_WEBPUSH_PRIVATE_KEY' => $keys['privateKey'],
        ]));
        PHP]);
}

#[AsTask(description: 'Creates the database, or updates its schema, as on the production server', namespace: 'app:db', name: 'schema')]
function db_schema(): void
{
    io()->title('Updating the database schema');

    docker_compose_run(['bin/console', 'doctrine:database:create', '--if-not-exists']);
    docker_compose_run(['bin/console', 'doctrine:schema:update', '--force']);
    // The sessions live in the database (PdoSessionHandler), in a table Doctrine does not know
    docker_compose_run(['bin/console', 'doctrine:query:sql', '--', file_get_contents(variable('root_dir') . '/docs/database/schema-sessions.sql')]);
}

#[AsTask(description: 'Recreates the database with the fixtures (dev and test data), then fills the Elasticsearch index', namespace: 'app:db', name: 'fixtures')]
function db_fixtures(): void
{
    io()->title('Loading the fixtures');

    // A new database restarts the AUTO_INCREMENT counters: the fixtures get the same ids on every load
    docker_compose_run(['bin/console', 'doctrine:database:drop', '--force', '--if-exists']);
    db_schema();
    docker_compose_run(['bin/console', 'doctrine:fixtures:load', '--append', '--no-interaction']);
    // Turns the activities of the fixtures (likes, comments, follows...) into notifications, as the cron does
    docker_compose_run(['bin/console', 'ladb:cron:notification:populate', '--force']);

    elasticsearch_populate();
}

function db_has_users(): bool
{
    // The column name only shows in the output when a row is found. The query fails when the
    // database does not exist yet (the one of the tests, on a new copy).
    $output = docker_compose_run(['bin/console', 'doctrine:query:sql', 'SELECT 1 AS has_users FROM tbl_core_user LIMIT 1'], c: context()->withQuiet()->withAllowFailure())->getOutput();

    return str_contains($output, 'has_users');
}

#[AsTask(description: 'Creates the Elasticsearch index and fills it from the database', namespace: 'app:elasticsearch', name: 'populate')]
function elasticsearch_populate(): void
{
    io()->title('Populating the Elasticsearch index');

    docker_compose_run(['bin/console', 'fos:elastica:populate']);
}

#[AsTask(description: 'Builds the CSS and JS in web/build/, minified as on the production server (Vite, vite.config.js)', namespace: 'app:assets', name: 'build')]
function assets_build(): void
{
    io()->title('Building the assets');

    assets_install();
    docker_compose_run(['npx', 'vite', 'build']);
}

#[AsTask(description: 'Builds the CSS and JS in web/build/ again on each change of their files, not minified (Vite)', namespace: 'app:assets', name: 'watch')]
function assets_watch(): void
{
    assets_install();

    io()->title('Watching the assets');

    docker_compose_run(['npx', 'vite', 'build', '--watch', '--mode', 'development'], c: context()->toInteractive()->withTrappedSignals());
}

/**
 * The template helper asset() fails without the manifest of the build (web/build/manifest.json):
 * every page, the tests included.
 */
function assets_build_if_missing(): void
{
    if (!is_file(variable('root_dir') . '/web/build/manifest.json')) {
        assets_build();
    }
}

function assets_install(): void
{
    $rootDir = variable('root_dir');

    // The sources of the assets: the bundles and the components (jQuery...) of vendor/
    if (!is_dir($rootDir . '/vendor')) {
        install();
    }

    // npm writes node_modules/.package-lock.json on each install: when it is older than the
    // lock, the dependencies changed since
    $installed = $rootDir . '/node_modules/.package-lock.json';
    if (is_file($installed) && filemtime($installed) >= filemtime($rootDir . '/package-lock.json')) {
        return;
    }

    io()->section('Installing the Node packages');

    docker_compose_run(['npm', 'ci', '--no-audit', '--no-fund']);
}
