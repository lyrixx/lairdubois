<?php

namespace docker;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function Castor\context;
use function Castor\io;
use function Castor\run;
use function Castor\variable;

#[AsTask(description: 'Displays some help and available urls for the current project', namespace: '')]
function about(): void
{
    io()->title('About this project');

    io()->comment('Run <comment>castor</comment> to display all available commands.');
    io()->comment('Run <comment>castor about</comment> to display this project help.');
    io()->comment('Run <comment>castor help [command]</comment> to display Castor help.');

    io()->section('Available URLs for this project:');
    io()->listing(array_map(static fn (string $domain) => "https://{$domain}", get_domains()));
}

#[AsTask(description: 'Builds the infrastructure', aliases: ['build'])]
function build(
    #[AsOption(description: 'The service to build (default: all services)', autocomplete: 'docker\complete_service_names')]
    ?string $service = null,
): void {
    io()->title('Building infrastructure');

    $command = [
        'build',
        '--build-arg', 'PHP_VERSION=' . variable('php_version'),
    ];

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, profiles: ['*']);
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Starts the infrastructure, and registers it on the global router', aliases: ['up'])]
function up(
    #[AsOption(description: 'The service to start (default: all services)', autocomplete: 'docker\complete_service_names')]
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    if (!$service && !$profiles) {
        io()->title('Starting infrastructure');
    }

    \router\start();

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

    \router\connect(get_network_name(), get_domains());
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

    // Docker refuses to remove a network that still has an endpoint
    \router\disconnect(get_network_name());

    docker_compose(['down', '--remove-orphans', '--volumes', '--rmi=local'], profiles: ['*']);
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
 * @return list<string>
 */
function get_domains(?Context $c = null): array
{
    $c ??= context();

    return [$c['root_domain'], ...$c['extra_domains']];
}

function get_network_name(?Context $c = null): string
{
    $c ??= context();

    return $c['project_name'] . '_default';
}

/**
 * @return array<string, string>
 */
function get_compose_environment(Context $c): array
{
    return [
        'PROJECT_NAME' => $c['project_name'],
        'PROJECT_ROOT_DOMAIN' => $c['root_domain'],
        // Site addresses of the "caddy" labels
        'PROJECT_DOMAINS' => implode(' ', get_domains($c)),
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
