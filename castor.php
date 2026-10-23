<?php

use Castor\Attribute\AsTask;

use function Castor\guard_min_version;
use function Castor\import;
use function Castor\io;
use function Castor\notify;
use function Castor\slug;
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
    // Each copy of the repository (clone, git worktree) runs its own stack, named
    // after its directory: compose project (containers, volumes, network) and domain
    $projectName = 'lairdubois';
    $rootDomain = 'lairdubois.test';

    // Docker Compose rejects dots and most symbols in a project name
    $copyName = strtolower(slug(basename(__DIR__)));
    if ($copyName !== $projectName) {
        $projectName .= "-{$copyName}";
        $rootDomain = "{$copyName}.{$rootDomain}";
    }

    return [
        'project_name' => $projectName,
        'root_domain' => $rootDomain,
        'extra_domains' => [],
        'php_version' => '8.5',
    ];
}

#[AsTask(description: 'Builds and starts the infrastructure, then installs the application (composer, database, index, workers)')]
function start(): void
{
    io()->title('Starting the stack');

    build();
    install();
    up(profiles: ['default']); // We can't start worker now, they are not installed
    db_schema();
    elasticsearch_populate();
    workers_start();

    notify('The stack is now up and running.');
    io()->success('The stack is now up and running.');

    about();
}

#[AsTask(description: 'Installs the application (composer)', namespace: 'app', aliases: ['install'])]
function install(): void
{
    io()->title('Installing the application');

    // Most of the locked packages only declare PHP 7 ("php": "^7.1"...), the code
    // runs in PHP 8.5 anyway: "php+" ignores their upper bound, not their lower one.
    // The composer scripts also write app/config/parameters.yml, install the
    // bundles assets and clear the cache.
    docker_compose_run(['composer', 'install', '-n', '--prefer-dist', '--optimize-autoloader', '--ignore-platform-req=php+']);
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

#[AsTask(description: 'Creates the Elasticsearch index and fills it from the database', namespace: 'app:elasticsearch', name: 'populate')]
function elasticsearch_populate(): void
{
    io()->title('Populating the Elasticsearch index');

    docker_compose_run(['bin/console', 'fos:elastica:populate']);
}

#[AsTask(description: 'Builds the CSS and JS as on the production server, in web/css and web/js (Assetic, with Java)', namespace: 'app:assets', name: 'dump')]
function assets_dump(): void
{
    io()->title('Dumping the assets');

    docker_compose_run(['bin/console', 'assetic:dump', '--env=prod', '--no-debug']);
}
