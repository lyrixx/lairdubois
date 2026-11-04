<?php

namespace prod;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\http_client;
use function Castor\io;
use function Castor\run;
use function Castor\variable;
use function Castor\with;
use function context\create_default_context;
use function context\create_prod_context;
use function db_has_users;
use function db_schema;
use function docker\about;
use function docker\build;
use function docker\docker_compose;
use function docker\docker_compose_run;
use function docker\docker_exit_code;
use function docker\get_services;
use function docker\up;
use function docker\workers_start;
use function elasticsearch_populate;
use function install;
use function qa\e2e_install_if_missing;
use function worktree\get_worktree_ports;

// The throwaway secrets of the stack (docker-compose.prod.yml), relative to the root directory
const SECRETS_DIR = 'var/prod';

// The p256dh and auth keys of a browser (tests/Webpush/SubscriptionTest.php): the subscriptions
// of the fixtures accounts, to the double "push"
const PUSH_SUBSCRIPTION_KEYS = [
    'p256dh' => 'BHRokM_VvfQj55aPbnoh2J8uvpHQs4SNdajH5XUTqOjf_rsMfUHHy5iAQOpnkcOJaUGdqc8EhgNvnsu3I2DNXcM',
    'auth' => 'PbPBY0VewTVZ-S6wnkqrOA',
];

/**
 * castor -c prod start: the production images, built, and started with the fixtures.
 */
function start(): void
{
    up_stack();
    db_schema();
    // A new stack gets the fixtures: one with accounts is kept as is
    if (db_has_users()) {
        elasticsearch_populate();
    } else {
        fixtures();
    }
    workers_start();

    io()->success('The production stack is up and running.');

    about();
}

/**
 * The images, the secrets and the services, without the workers and the cron: they need the
 * database.
 */
function up_stack(): void
{
    io()->title('Starting the production stack');

    build();
    secrets();
    up(profiles: ['default']);
}

#[AsTask(description: 'Recreates the database of the production stack with the fixtures, then fills its index', namespace: 'app:prod', name: 'fixtures')]
function fixtures(): void
{
    with(static function (): void {
        io()->title('Loading the fixtures in the production stack');

        // The fixtures are dev packages: the builder of the dev loads them, with the vendor/ of the
        // repository
        builder_install_if_missing();

        // A new database restarts the AUTO_INCREMENT counters: the same ids as in dev
        docker_compose_run(['bin/console', 'doctrine:database:drop', '--force', '--if-exists']);
        db_schema();
        docker_compose_run(['bin/console', 'doctrine:fixtures:load', '--append', '--no-interaction'], service: 'builder');
        // The rest by the commands of the image
        docker_compose_run(['bin/console', 'ladb:cron:notification:populate', '--force']);
        elasticsearch_populate();
    }, context: create_prod_context());
}

/**
 * Throwaway secrets for the stack, as the preprod gives its own: the variables of
 * var/prod/secrets.env, and the DKIM key in var/prod/keys/ (mounted on keys/).
 */
function secrets(): void
{
    $dir = variable('root_dir') . '/' . SECRETS_DIR;
    fs()->mkdir($dir . '/keys');

    $dkimKey = $dir . '/keys/private.pem';
    if (!is_file($dkimKey)) {
        run(['openssl', 'genrsa', '-out', $dkimKey, '2048'], context: context()->withQuiet());
        // Read by the user of the image, which may not be the one of the host (the CI)
        fs()->chmod($dkimKey, 0o644);
    }

    $secretsFile = $dir . '/secrets.env';
    if (is_file($secretsFile)) {
        return;
    }

    $vapid = json_decode(docker_compose_run(['php', '-r', 'require "vendor/autoload.php"; echo json_encode(Minishlink\WebPush\VAPID::createVapidKeys());'], c: context()->withQuiet())->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
    fs()->dumpFile($secretsFile, implode("\n", [
        '# Throwaway secrets of the production stack (castor -c prod start)',
        'SECRET=' . bin2hex(random_bytes(20)),
        'WEBPUSH_PUBLIC_KEY=' . $vapid['publicKey'],
        'WEBPUSH_PRIVATE_KEY=' . $vapid['privateKey'],
        '',
    ]));
}

/**
 * The vendor/ and the parameters.yml of the dev, for the builder of the stack (fixtures).
 */
function builder_install_if_missing(): void
{
    $rootDir = variable('root_dir');
    if (is_dir($rootDir . '/vendor/doctrine/doctrine-fixtures-bundle') && is_file($rootDir . '/app/config/parameters.yml')) {
        return;
    }

    with(static fn () => install(), context: create_default_context());
}

#[AsTask(description: 'Starts the production stack with the fixtures, then checks the site in it: pages, login, workers, crons, Mercure, Elasticsearch, images, Chromium, mails', namespace: 'qa', name: 'prod-smoke')]
function smoke(): int
{
    return with(static function (): int {
        up_stack();
        // The checks change the data: they start from the fixtures
        fixtures();
        workers_start();

        // The end-to-end tests first, on the data of the fixtures: the crons change them
        $checks = [
            'image' => check_image(...),
            'crontab' => check_crontab(...),
            'chromium' => check_chromium(...),
            'images (Liip)' => check_liip(...),
            'view (worker view)' => check_view(...),
            'pages, login, uploads, Mercure (Playwright)' => check_e2e(...),
            'crons' => check_crons(...),
            'mails (worker mailer, Mailpit)' => check_mails(...),
            'web push (worker webpush_notification)' => check_webpush(...),
        ];

        $results = [];
        foreach ($checks as $name => $check) {
            io()->section($name);
            try {
                $check();
                $results[] = [$name, '<info>OK</info>', ''];
            } catch (\Throwable $e) {
                io()->error($e->getMessage());
                $results[] = [$name, '<error>failed</error>', mb_strimwidth(strtok($e->getMessage(), "\n"), 0, 100, '…')];
            }
        }

        io()->section('Summary');
        io()->table(['Check', 'Result', ''], $results);

        return \in_array('<error>failed</error>', array_column($results, 1), true) ? 1 : 0;
    }, context: create_prod_context());
}

#[AsTask(description: 'Describes the production images in Markdown: size, layers, what the code weighs (the summary of the CI)', namespace: 'app:prod', name: 'describe')]
function describe(): void
{
    with(static function (): void {
        $services = get_services();
        $markdown = "## Production images\n\n| Image | Size |\n| --- | ---: |\n";
        foreach (['php', 'nginx'] as $service) {
            $size = (int) trim(capture(['docker', 'image', 'inspect', '--format', '{{.Size}}', $services[$service]['image']]));
            $markdown .= \sprintf("| %s | %d MB |\n", $service, $size / 1_000_000);
        }

        $markdown .= "\nLayers of php (the largest):\n\n```\n";
        $layers = explode("\n", trim(capture(['docker', 'history', '--format', '{{.Size}}\t{{.CreatedBy}}', $services['php']['image']])));
        $markdown .= implode("\n", array_map(static fn (string $layer) => mb_strimwidth($layer, 0, 120, '…'), \array_slice(array_filter($layers, static fn (string $layer) => !str_starts_with($layer, '0B')), 0, 10)));
        $markdown .= "\n```\n\nThe code in php (/var/www):\n\n```\n";
        $markdown .= trim(capture_php(['sh', '-c', 'du -sh $(ls -d /var/www/* /var/www/vendor/* | grep -v "^/var/www/vendor$") 2>/dev/null | sort -rh | head -15']));
        $markdown .= "\n```\n\nweb/ in nginx:\n\n```\n";
        $markdown .= trim(capture(['docker', 'run', '--rm', '--entrypoint', 'sh', $services['nginx']['image'], '-c', 'du -sh /var/www/web /var/www/web/* | sort -rh | head -8']));
        $markdown .= "\n```\n";

        io()->write($markdown);
    }, context: create_prod_context());
}

/**
 * The image "php": SYMFONY_ENV=prod, the user 1000, the code without dev packages, tests nor
 * secrets.
 */
function check_image(): void
{
    $output = capture_php([
        'sh', '-c', implode(' && ', [
            'echo "user=$(id -u) env=$SYMFONY_ENV"',
            // None of them is in the image
            'for path in .git node_modules tests tools var/cache/dev vendor/symfony/web-profiler-bundle vendor/doctrine/doctrine-fixtures-bundle vendor/dama src/Ladb/CoreBundle/DataFixtures web/app_dev.php; do test ! -e "$path" || echo "found=$path"; done',
            // PHP only writes in var/ and in the volumes
            'test -w var -a -w downloads -a -w sitemaps -a -w uploads -a -w web/media || echo "not-writable"',
            'find . \\( -path ./var -o -path ./downloads -o -path ./sitemaps -o -path ./uploads -o -path ./web/media \\) -prune -o -writable -print | sed "s/^/writable=/"',
            'grep -c "%env(" app/config/parameters.yml | sed "s/^/env_parameters=/"',
            'bin/console about --no-ansi | grep -E "Environment|Debug" | tr -s " " | tr "\n" " "',
        ]),
    ]);
    io()->text($output);

    expect(str_contains($output, 'user=1000 env=prod'), 'The image does not run as the user 1000 in prod.');
    expect(!str_contains($output, 'found='), 'The image has files it should not have.');
    expect(!str_contains($output, 'not-writable'), 'PHP cannot write in var/ or in a volume.');
    expect(!str_contains($output, 'writable='), 'PHP can write in the code.');
    expect(1 === preg_match('{Environment\s+prod\s+Debug\s+false}', $output), 'The kernel is not in prod, without debug.');
}

/**
 * The crontab of the image is the one of docs/01-installation.md, and supercronic reads it.
 */
function check_crontab(): void
{
    $expected = crontab_of_documentation();
    $crontab = file_get_contents(variable('root_dir') . '/infrastructure/docker/services/php/prod/crontab');
    preg_match_all('{^((?:\S+ ){5})bin/console (.+)$}m', $crontab, $matches, \PREG_SET_ORDER);
    $actual = array_map(static fn (array $m) => $m[1] . $m[2], $matches);

    expect($expected === $actual, "The crontab of the image is not the one of docs/01-installation.md:\n" . implode("\n", $expected));

    docker_compose_run(['supercronic', '-test', '/etc/lairdubois/crontab']);
}

/**
 * Chromium in the image, with the options of WebScreenshotUtils: a screenshot of a page that nginx
 * serves.
 */
function check_chromium(): void
{
    $output = capture_php(['php', '-r', <<<'PHP'
        require 'vendor/autoload.php';
        $browser = (new HeadlessChromium\BrowserFactory('/usr/bin/chromium'))->createBrowser(['windowSize' => [800, 600], 'customFlags' => ['--no-sandbox']]);
        try {
            $page = $browser->createPage();
            $page->setViewport(800, 600)->await();
            $page->navigate('http://nginx:8080/unsupported.html')->waitForNavigation();
            $file = sys_get_temp_dir().'/screenshot.png';
            $page->screenshot()->saveToFile($file);
            echo json_encode(['title' => $page->evaluate('document.title')->getReturnValue(), 'size' => getimagesize($file)]);
        } finally {
            $browser->close();
        }
        PHP]);
    io()->text($output);

    $result = json_decode(trim($output), true, flags: \JSON_THROW_ON_ERROR);
    expect([800, 600] === \array_slice($result['size'], 0, 2), 'The screenshot is not 800×600.');
    expect('' !== $result['title'], 'Chromium did not load the page.');
}

/**
 * Each command of the crontab, then what it leaves: the sitemaps served by nginx, the thumbnail of
 * a workflow captured by Chromium from the site.
 */
function check_crons(): void
{
    // A workflow without thumbnail (CrontabTest)
    sql('UPDATE tbl_workflow SET main_picture_id = NULL WHERE id = 1');

    foreach (crontab_of_documentation() as $line) {
        $command = explode(' ', explode(' ', $line, 6)[5]);
        io()->text('bin/console ' . implode(' ', $command));
        docker_compose_run(['bin/console', ...$command]);
    }

    $index = request('GET', '/sitemap-index.xml');
    expect(200 === $index->getStatusCode() && str_contains($index->getContent(false), '/sitemap-wonder-creations.xml'), 'nginx does not serve the index of the sitemaps.');
    $sitemap = request('GET', '/sitemap-wonder-creations.xml');
    expect(200 === $sitemap->getStatusCode() && str_contains($sitemap->getContent(false), '/creations/1-'), 'nginx does not serve the sitemap of the creations.');

    expect('1' !== sql_value('SELECT main_picture_id IS NULL FROM tbl_workflow WHERE id = 1'), 'The workflow did not get a thumbnail.');
    $logs = capture_compose(['logs', '--no-log-prefix', 'nginx']);
    expect(1 === preg_match('{"GET /processus/1/internal/diagram HTTP/[^"]+" 200 .*HeadlessChrome}', $logs), 'Chromium did not load the diagram of the workflow from the site.');
}

/**
 * Liip Imagine: a JPEG and a PNG of the pages, resolved by PHP (jpegoptim, pngquant), then served by
 * nginx from the volume.
 */
function check_liip(): void
{
    $urls = [];
    foreach (['/creations/1.html', '/@user'] as $page) {
        preg_match_all('{/media/cache/resolve/[^"\'\s?]+\.(?:jpe?g|png)}i', request('GET', $page)->getContent(), $matches);
        $urls = [...$urls, ...$matches[0]];
    }
    $urls = array_unique($urls);
    $jpeg = current(preg_grep('{\.jpe?g$}i', $urls));
    $png = current(preg_grep('{\.png$}i', $urls));
    expect(false !== $jpeg && false !== $png, 'No JPEG or PNG to resolve in the pages: ' . implode(', ', $urls));

    foreach ([$jpeg => 'image/jpeg', $png => 'image/png'] as $url => $type) {
        $resolve = request('GET', $url);
        expect(301 === $resolve->getStatusCode(), "{$url} answers {$resolve->getStatusCode()}, not 301.");
        $location = parse_url($resolve->getHeaders(false)['location'][0], \PHP_URL_PATH);
        $image = request('GET', $location);
        $headers = $image->getHeaders(false);
        io()->text("{$url} → {$location}: {$image->getStatusCode()}, {$headers['content-type'][0]}, " . \strlen($image->getContent(false)) . ' bytes');
        expect(200 === $image->getStatusCode() && $type === $headers['content-type'][0], "{$location} is not a {$type}.");
        // The location of nginx for the files of the volume, not PHP
        expect(\in_array('public', $headers['cache-control'] ?? [], true), "nginx does not serve {$location} from the volume.");
    }
}

/**
 * The view of a creation, counted by the worker "view".
 */
function check_view(): void
{
    $before = (int) sql_value('SELECT view_count FROM tbl_wonder_creation WHERE id = 1');
    // A browser, not a crawler (CrawlerDetect)
    request('GET', '/creations/1.html', ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0']);

    wait_for(static fn () => (int) sql_value('SELECT view_count FROM tbl_wonder_creation WHERE id = 1') === $before + 1, 'The view is not counted.');
}

/**
 * The end-to-end tests of tests/e2e on the stack (E2E_PROD: pages.spec.ts too): the pages,
 * anonymous, as user and admin, the login form, the JS, the uploads, Mercure.
 */
function check_e2e(): void
{
    // Each account of the fixtures is subscribed to the web push notifications: the interactions
    // of the tests send some to the double "push" (check_webpush())
    foreach (['admin' => 1, 'user' => 2, 'user2' => 3] as $username => $id) {
        $endpoint = "http://push/{$username}";
        $subscription = json_encode(['endpoint' => $endpoint, 'expirationTime' => null, 'keys' => PUSH_SUBSCRIPTION_KEYS]);
        sql(\sprintf("INSERT INTO tbl_webpush_user_subscription (user_id, subscription_hash, subscription) VALUES (%d, '%s', '%s')", $id, md5($endpoint), $subscription));
    }

    expect(0 === e2e(), 'The end-to-end tests failed (var/e2e/test-results/).');
}

/**
 * @param list<string> $rawTokens
 */
#[AsTask(description: 'Runs the end-to-end tests (Playwright, tests/e2e) on the production stack, on its data as they are', namespace: 'qa', name: 'prod-e2e')]
function e2e(#[AsRawTokens] array $rawTokens = []): int
{
    return with(static function () use ($rawTokens): int {
        e2e_install_if_missing();

        return docker_exit_code(['npx', 'playwright', 'test', ...$rawTokens], service: 'playwright');
    }, context: create_prod_context());
}

/**
 * The mails of the crons and of the tests, sent by the worker "mailer" to Mailpit, signed with DKIM.
 */
function check_mails(): void
{
    $messages = request('GET', '/mailpit/api/v1/messages?limit=50')->toArray();
    io()->text(\sprintf('%d mails in Mailpit: %s', $messages['total'], implode(', ', array_unique(array_column($messages['messages'], 'Subject')))));
    expect($messages['total'] > 0, 'No mail in Mailpit.');

    $headers = array_change_key_case(request('GET', '/mailpit/api/v1/message/' . $messages['messages'][0]['ID'] . '/headers')->toArray());
    expect(str_contains($headers['dkim-signature'][0] ?? '', 'd=lairdubois.fr'), 'The mail is not signed with DKIM.');
}

/**
 * The web push notifications of the interactions of the tests, sent by the worker to the double
 * "push", signed with the VAPID keys.
 */
function check_webpush(): void
{
    wait_for(static fn () => str_contains(capture_compose(['logs', '--no-log-prefix', 'push']), 'POST /'), 'The double "push" got no notification.');

    $logs = capture_compose(['logs', '--no-log-prefix', 'push']);
    preg_match_all('{^POST (/\S+) ttl=(\S+) encoding=(\S+) authorization=(vapid t=\S+, k=\S+|WebPush \S+)}m', $logs, $matches, \PREG_SET_ORDER);
    io()->text(\sprintf('%d notifications: %s', \count($matches), implode(', ', array_unique(array_column($matches, 1)))));
    expect([] !== $matches, "No notification signed with VAPID:\n{$logs}");
}

/**
 * The command lines of the crontab of docs/01-installation.md (step 13), with their schedule,
 * without "php …/bin/console --env=prod" (tests/Command/CrontabTest.php).
 *
 * @return list<string>
 */
function crontab_of_documentation(): array
{
    preg_match_all('~^((?:\S+ ){5})php /var/www/www\.lairdubois\.fr/bin/console --env=prod (.+) &> /dev/null$~m', file_get_contents(variable('root_dir') . '/docs/01-installation.md'), $matches, \PREG_SET_ORDER);

    return array_map(static fn (array $m) => $m[1] . $m[2], $matches);
}

/**
 * A request to the site, through the router of the stack.
 *
 * @param array<string, string> $headers
 */
function request(string $method, string $path, array $headers = []): ResponseInterface
{
    $domain = variable('root_domain');
    $port = get_worktree_ports(variable('ports_name'))['https'];

    return http_client()->request($method, "https://{$domain}:{$port}{$path}", [
        'resolve' => [$domain => '127.0.0.1'],
        'verify_peer' => false,
        'verify_host' => false,
        'max_redirects' => 0,
        'headers' => $headers,
        'timeout' => 30,
    ]);
}

function sql(string $sql): string
{
    return capture_php(['bin/console', 'dbal:run-sql', '--', $sql]);
}

/**
 * The first column of the first row.
 */
function sql_value(string $sql): ?string
{
    // The table of the console: the header, then the values, between lines of dashes
    $lines = array_values(array_filter(
        array_map(trim(...), explode("\n", capture_php(['bin/console', 'dbal:run-sql', '--', $sql]))),
        static fn (string $line) => '' !== $line && !preg_match('{^[- ]+$}', $line),
    ));

    return $lines[1] ?? null;
}

/**
 * @param list<string> $command
 */
function capture_php(array $command): string
{
    return docker_compose_run($command, c: context()->withQuiet())->getOutput();
}

/**
 * @param list<string> $command
 */
function capture_compose(array $command): string
{
    return docker_compose($command, c: context()->withQuiet(), profiles: ['*'])->getOutput();
}

function wait_for(callable $condition, string $message, int $timeout = 60): void
{
    $end = time() + $timeout;
    while (!$condition()) {
        if (time() > $end) {
            throw new \RuntimeException($message);
        }
        sleep(2);
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}
