<?php

namespace context;

use Castor\Attribute\AsContext;
use Castor\Context;
use Symfony\Component\Process\Process;

use function Castor\log;
use function worktree\get_worktree_name;

#[AsContext(default: true)]
function create_default_context(): Context
{
    $data = create_default_variables() + [
        'extra_domains' => [],
        'docker_compose_files' => [
            'docker-compose.yml',
            'docker-compose.dev.yml',
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

    $worktreeName = get_worktree_name($data['root_dir']);
    if (null !== $worktreeName) {
        $data['project_name'] .= '-wt-' . $worktreeName;
    }
    // The ports of the router derive from this name (worktree\get_worktree_ports()): those of the
    // worktree, the default ones (80, 443, 8080) for the main copy
    $data['ports_name'] = $worktreeName;

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

/**
 * The production images (the "Production stages" of the Dockerfile) in a stack of their own,
 * docker-compose.prod.yml: castor -c prod docker:build, castor -c prod start, castor qa:prod-smoke.
 * Its project and its ports are not those of the dev stack of the copy: both can run at once.
 */
#[AsContext(name: 'prod')]
function create_prod_context(): Context
{
    $c = create_default_context();

    $files = ['docker-compose.prod.yml'];
    // The cache of the CI (.github/workflows/ci.yml): the override of the dev names its services
    if (file_exists($c['root_dir'] . '/infrastructure/docker/docker-compose.prod.override.yml')) {
        $files[] = 'docker-compose.prod.override.yml';
    }

    return $c->withData(
        [
            'project_name' => $c['project_name'] . '-prod',
            'ports_name' => ($c['ports_name'] ?? 'main') . '-prod',
            'docker_compose_files' => $files,
            // No builder: the commands run in the image "php", the fixtures excepted (.castor/prod.php)
            'docker_compose_run_service' => 'php',
            // Where castor docker:push pushes the images: GHCR wants it in lowercase, which
            // github.repository may not be
            'registry' => false !== getenv('REGISTRY') ? strtolower(getenv('REGISTRY')) : null,
        ],
        recursive: false,
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
