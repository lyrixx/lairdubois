<?php

namespace context;

use Castor\Attribute\AsContext;
use Castor\Context;
use Symfony\Component\Process\Process;

use function Castor\log;

#[AsContext(default: true)]
function create_default_context(): Context
{
    $data = create_default_variables() + [
        'extra_domains' => [],
        'docker_compose_files' => [
            'docker-compose.yml',
        ],
        'docker_compose_run_environment' => [],
        // Service in which `docker_compose_run()` executes commands (composer, bin/console...)
        'docker_compose_run_service' => 'builder',
        'macos' => false,
        'power_shell' => false,
        // check if posix_geteuid is available, if not, use getmyuid (windows)
        'user_id' => \function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
        'root_dir' => \dirname(__DIR__),
    ];

    if (file_exists($data['root_dir'] . '/infrastructure/docker/docker-compose.override.yml')) {
        $data['docker_compose_files'][] = 'docker-compose.override.yml';
    }

    $platform = strtolower(php_uname('s'));
    if (str_contains($platform, 'darwin')) {
        $data['macos'] = true;
    } elseif (\in_array($platform, ['win32', 'win64', 'windows nt'], true)) {
        $data['power_shell'] = true;
    }

    //                                                   2³² - 1
    if (false === $data['user_id'] || $data['user_id'] > 4294967295) {
        $data['user_id'] = 1000;
    }

    if (0 === $data['user_id']) {
        log('Running as root? Fallback to fake user id.', 'warning');
        $data['user_id'] = 1000;
    }

    // Only when someone can answer the prompts of the commands: stdin is a terminal, outside of
    // a CI (as for Castor). Otherwise (CI, pipes, AI agents...), a PTY would give them a terminal
    // nobody types in, and a prompt (composer, bin/console...) would wait forever.
    $canAnswerPrompts = false === getenv('CI') && stream_isatty(STDIN);

    return new Context(
        $data,
        pty: Process::isPtySupported() && $canAnswerPrompts,
        environment: [
            'BUILDKIT_PROGRESS' => 'plain',
        ]
    );
}

#[AsContext(name: 'test')]
function create_test_context(): Context
{
    $c = create_default_context();

    return $c
        ->withEnvironment([
            'SYMFONY_ENV' => 'test',
        ])
    ;
}

#[AsContext(name: 'ci')]
function create_ci_context(): Context
{
    $c = create_test_context();

    return $c
        ->withEnvironment([
            'COMPOSE_ANSI' => 'never',
        ])
    ;
}
