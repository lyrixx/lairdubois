<?php

namespace docker;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

use function Castor\capture;
use function Castor\context;
use function Castor\finder;
use function Castor\fs;
use function Castor\http_client;
use function Castor\io;
use function Castor\run;
use function Castor\variable;
use function worktree\get_worktree_name;
use function worktree\get_worktree_ports;

/** @return array<string, array{env: string, default: int, label: string}> */
function get_port_specs(): array
{
    return [
        'http' => ['env' => 'PROJECT_HTTP_PORT',  'default' => 80,   'label' => 'HTTP'],
        'https' => ['env' => 'PROJECT_HTTPS_PORT', 'default' => 443,  'label' => 'HTTPS'],
        'admin' => ['env' => 'PROJECT_ADMIN_PORT', 'default' => 8080, 'label' => 'Traefik admin'],
    ];
}

#[AsTask(description: 'Displays some help and available urls for the current project', namespace: '')]
function about(): void
{
    io()->title('About this project');

    io()->comment('Run <comment>castor</comment> to display all available commands.');
    io()->comment('Run <comment>castor about</comment> to display this project help.');
    io()->comment('Run <comment>castor help [command]</comment> to display Castor help.');

    io()->section('Available URLs for this project:');

    if (!has_router()) {
        io()->listing([\sprintf('http://127.0.0.1:%s', getenv('HTTP_PORT') ?: '8000')]);

        return;
    }

    $urls = [variable('root_domain'), ...variable('extra_domains')];

    $worktreeName = get_worktree_name();
    $adminPort = get_worktree_ports($worktreeName)['admin'];

    try {
        $routers = http_client()
            ->request('GET', \sprintf('http://%s:%d/api/http/routers', variable('root_domain'), $adminPort))
            ->toArray()
        ;
        $projectName = variable('project_name');
        foreach ($routers as $router) {
            if (!preg_match("{^{$projectName}-(.*)@docker$}", $router['name'])) {
                continue;
            }
            if ("frontend-{$projectName}" === $router['service']) {
                continue;
            }
            // The Mercure router also matches a path (/.well-known/mercure): it adds no URL
            if (str_contains($router['rule'], '&&')) {
                continue;
            }
            if (!preg_match('{^Host\(`(?P<hosts>.*)`\)$}', $router['rule'], $matches)) {
                continue;
            }
            $hosts = explode('`) || Host(`', $matches['hosts']);
            $urls = [...$urls, ...$hosts];
        }
    } catch (HttpExceptionInterface) {
    }

    $httpsPort = null !== $worktreeName ? get_worktree_ports($worktreeName)['https'] : null;
    $formatUrl = static function (string $host) use ($httpsPort): string {
        return null !== $httpsPort ? "https://{$host}:{$httpsPort}" : "https://{$host}";
    };

    io()->listing(array_map($formatUrl, array_unique($urls)));
}

#[AsTask(description: 'Builds the infrastructure', aliases: ['build'])]
function build(
    #[AsOption(description: 'The service to build (default: all services)', autocomplete: 'docker\complete_service_names')]
    ?string $service = null,
): void {
    generate_certificates(force: false);

    io()->title('Building infrastructure');

    $command = [
        'build',
        '--build-arg', 'PHP_VERSION=' . variable('php_version'),
        '--build-arg', 'PROJECT_NAME=' . variable('project_name'),
    ];

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, profiles: ['*']);
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Starts the infrastructure', aliases: ['up'])]
function up(
    #[AsOption(description: 'The service to start (default: all services)', autocomplete: 'docker\complete_service_names')]
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    if (!$service && !$profiles) {
        io()->title('Starting infrastructure');
    }

    $command = ['up', '--detach', '--wait', '--no-build'];

    if ($service) {
        $command[] = $service;
    }

    try {
        docker_compose($command, profiles: $profiles);
    } catch (ExceptionInterface $e) {
        io()->error('An error occurred while starting the infrastructure.');
        io()->note('Did you forget to run "castor docker:build"?');

        throw $e;
    }
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Stops the infrastructure', aliases: ['stop'])]
function stop(
    #[AsOption(description: 'The service to stop (default: all services)', autocomplete: 'docker\complete_service_names')]
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    if (!$service || !$profiles) {
        io()->title('Stopping infrastructure');
    }

    $command = ['stop'];

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, profiles: $profiles ?: ['*']);
}

/**
 * @param list<string> $params
 */
#[AsTask(description: 'Opens a shell (bash) or proxy any command to the builder container', aliases: ['builder'])]
function builder(#[AsArgsAfterOptionEnd] array $params = []): int
{
    $context = context()->withEnvironment($_ENV + $_SERVER);

    // Behave like the command run by hand: a TTY in a terminal (colors,
    // prompts), the forwarded stdin otherwise (CI, pipes, redirections)
    $context = $context->supportsInteraction()
        ? $context->toInteractive()
        : $context->withInput(STDIN)->withAllowFailure();

    return (int) docker_compose_run($params, $context)->getExitCode();
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Displays infrastructure logs', aliases: ['logs'])]
function logs(
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    $command = ['logs', '-f', '--tail', '150'];

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, c: context()->withTty(), profiles: $profiles ?: ['*']);
}

#[AsTask(description: 'Lists containers status', aliases: ['ps'])]
function ps(): void
{
    docker_compose([
        'ps',
        '--format', 'table {{.Name}}\t{{.Image}}\t{{.Status}}\t{{.RunningFor}}\t{{.Command}}',
        '--no-trunc',
    ], profiles: ['*']);
}

#[AsTask(description: 'Cleans the infrastructure (remove container, volume, networks)', aliases: ['destroy'])]
function destroy(
    #[AsOption(description: 'Force the destruction without confirmation', shortcut: 'f')]
    bool $force = false,
): void {
    io()->title('Destroying infrastructure');

    if (!$force) {
        io()->warning('This will permanently remove all containers, volumes, networks... created for this project.');
        io()->note('You can use the --force option to avoid this confirmation.');
        if (!io()->confirm('Are you sure?', false)) {
            io()->comment('Aborted.');

            return;
        }
    }

    docker_compose(['down', '--remove-orphans', '--volumes', '--rmi=local'], profiles: ['*']);

    if (!has_router()) {
        return;
    }

    $files = finder()
        ->in(variable('root_dir') . '/infrastructure/docker/services/router/certs/')
        ->name('*.pem')
        ->files()
    ;
    fs()->remove($files);
}

#[AsTask(description: 'Generates SSL certificates (with mkcert if available or self-signed if not)')]
function generate_certificates(
    #[AsOption(description: 'Force the certificates re-generation without confirmation', shortcut: 'f')]
    bool $force = false,
): void {
    if (!has_router()) {
        io()->comment('No router in this stack, no SSL certificates to generate.');

        return;
    }

    $sslDir = variable('root_dir') . '/infrastructure/docker/services/router/certs';

    if (file_exists("{$sslDir}/cert.pem") && !$force) {
        io()->comment('SSL certificates already exists.');
        io()->note('Run "castor docker:generate-certificates --force" to generate new certificates.');

        return;
    }

    io()->title('Generating SSL certificates');

    if ($force) {
        if (file_exists($f = "{$sslDir}/cert.pem")) {
            io()->comment('Removing existing certificates in infrastructure/docker/services/router/certs/*.pem.');
            unlink($f);
        }

        if (file_exists($f = "{$sslDir}/key.pem")) {
            unlink($f);
        }
    }

    $finder = new ExecutableFinder();
    $mkcert = $finder->find('mkcert');

    if ($mkcert) {
        $pathCaRoot = capture(['mkcert', '-CAROOT']);

        if (!is_dir($pathCaRoot)) {
            io()->warning('You must have mkcert CA Root installed on your host with "mkcert -install" command.');

            return;
        }

        $rootDomain = variable('root_domain');

        run([
            'mkcert',
            '-cert-file', "{$sslDir}/cert.pem",
            '-key-file', "{$sslDir}/key.pem",
            $rootDomain,
            "*.{$rootDomain}",
            ...variable('extra_domains'),
        ]);

        io()->success('Successfully generated SSL certificates with mkcert.');

        if ($force) {
            io()->note('Please restart the infrastructure to use the new certificates with "castor up" or "castor start".');
        }

        return;
    }

    run(['infrastructure/docker/services/router/generate-ssl.sh'], context: context()->withQuiet());

    io()->success('Successfully generated self-signed SSL certificates in infrastructure/docker/services/router/certs/*.pem.');
    io()->comment('Consider installing mkcert to generate locally trusted SSL certificates and run "castor docker:generate-certificates --force".');

    if ($force) {
        io()->note('Please restart the infrastructure to use the new certificates with "castor up" or "castor start".');
    }
}

#[AsTask(description: 'Starts the workers', namespace: 'docker:worker', name: 'start', aliases: ['start-workers'])]
function workers_start(): void
{
    io()->title('Starting workers');

    docker_compose(['up', '--detach', '--wait', '--no-build'], profiles: ['worker', 'default']);
}

#[AsTask(description: 'Stops the workers', namespace: 'docker:worker', name: 'stop', aliases: ['stop-workers'])]
function workers_stop(): void
{
    io()->title('Stopping workers');

    docker_compose(['stop', ...get_service_names(profile: 'worker')], profiles: ['*']);
}

/**
 * @return array<string, string>
 */
function get_compose_environment(Context $c): array
{
    $domains = [$c['root_domain'], ...$c['extra_domains']];
    $domains = '`' . implode('`) || Host(`', $domains) . '`';

    return [
        'PROJECT_NAME' => $c['project_name'],
        'PROJECT_ROOT_DOMAIN' => $c['root_domain'],
        'PROJECT_DOMAINS' => $domains,
        'USER_ID' => $c['user_id'],
        'PHP_VERSION' => $c['php_version'],
    ];
}

/**
 * @param list<string> $subCommand
 * @param list<string> $profiles
 */
function docker_compose(array $subCommand, ?Context $c = null, array $profiles = []): Process
{
    $c ??= context();
    $profiles = $profiles ?: ['default'];

    $c = $c->withEnvironment(get_compose_environment($c));

    $worktreeName = get_worktree_name();
    if ($worktreeName) {
        $ports = get_worktree_ports($worktreeName);
        $portsEnv = [];
        foreach (get_port_specs() as $key => $spec) {
            $portsEnv[$spec['env']] = (string) $ports[$key];
        }
        $c = $c->withEnvironment($portsEnv);
    }

    $command = [
        'docker',
        'compose',
        '-p', $c['project_name'],
    ];
    foreach ($profiles as $profile) {
        $command[] = '--profile';
        $command[] = $profile;
    }

    foreach ($c['docker_compose_files'] as $file) {
        $command[] = '-f';
        $command[] = $c['root_dir'] . '/infrastructure/docker/' . $file;
    }

    $command = array_merge($command, $subCommand);

    return run($command, context: $c);
}

/**
 * @param list<string> $params
 */
function docker_compose_run(
    array $params,
    ?Context $c = null,
    ?string $service = null,
    bool $noDeps = true,
    ?string $workDir = null,
): Process {
    $c ??= context();
    $service ??= $c['docker_compose_run_service'];

    $command = [
        'run',
        '--rm',
        '--quiet',
    ];

    if ($noDeps) {
        $command[] = '--no-deps';
    }

    if (null !== $workDir) {
        $command[] = '-w';
        $command[] = $workDir;
    }

    foreach ($c['docker_compose_run_environment'] as $key => $value) {
        $command[] = '-e';
        $command[] = "{$key}={$value}";
    }

    if (0 === \count($params)) {
        $params = ['bash'];
        // A CTRL+C belongs to the shell, and must not stop the task: even when the shell exits with
        // the code of its last command, 130 after a CTRL+C at the prompt
        $c = $c->toInteractive()->withTrappedSignals();
    } else {
        $params = array_map(escapeshellarg(...), $params);
    }

    $command[] = $service;
    $command[] = '/bin/bash';
    $command[] = '-c';
    $command[] = implode(' ', $params);

    return docker_compose($command, c: $c, profiles: ['*']);
}

/**
 * Same as docker_compose_run(), but a failure of the command returns its exit code instead of
 * stopping the task.
 *
 * @param list<string> $runCommand
 */
function docker_exit_code(
    array $runCommand,
    ?Context $c = null,
    ?string $service = null,
    bool $noDeps = true,
    ?string $workDir = null,
): int {
    $c = ($c ?? context())->withAllowFailure();

    $process = docker_compose_run(
        params: $runCommand,
        c: $c,
        service: $service,
        noDeps: $noDeps,
        workDir: $workDir,
    );

    return $process->getExitCode() ?? 0;
}

/**
 * Whether the current stack includes the traefik router (dev), which needs SSL certificates
 * and gives the project its URLs.
 */
function has_router(): bool
{
    return isset(get_services()['router']);
}

/**
 * @return array<string, array{image?: string, profiles?: list<string>, build?: array{context: string, target?: string}}>
 */
function get_services(?string $profile = null): array
{
    $services = json_decode(
        docker_compose(
            ['config', '--format', 'json'],
            context()->withQuiet(),
            profiles: ['*'],
        )->getOutput(),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['services'];

    if (null === $profile) {
        return $services;
    }

    // Docker compose cannot get the services config for a given profile if one of
    // these services depends on another service in another profile.
    // So we find all services, in all profiles, and manually filter the one
    // that has the given profile
    return array_filter($services, static fn ($service) => \in_array($profile, $service['profiles'] ?? [], true));
}

/**
 * @return list<string>
 */
function get_service_names(?string $profile = null): array
{
    return array_keys(get_services($profile));
}

/**
 * Autocompletion of the --service options.
 *
 * @return list<string>
 */
function complete_service_names(CompletionInput $input): array
{
    return get_service_names();
}

#[AsTask(description: 'Displays the ports allocated for the current project', namespace: 'docker')]
function ports(): void
{
    $project = variable('project_name');
    $domain = variable('root_domain');
    $name = get_worktree_name();
    $ports = get_worktree_ports($name);

    io()->title('Ports');
    $list = [['Project' => $project]];
    foreach (get_port_specs() as $key => $spec) {
        $scheme = 'admin' === $key ? 'http' : $key;
        $host = 'admin' === $key ? 'localhost' : $domain;
        $list[] = [$spec['label'] => "{$scheme}://{$host}:{$ports[$key]}"];
    }

    if ($name) {
        array_unshift($list, ['Worktree' => $name]);
    }

    io()->definitionList(...$list);
}
