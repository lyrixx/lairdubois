<?php

namespace qa;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;

use function Castor\fs;
use function Castor\io;
use function Castor\variable;
use function docker\docker_compose_run;
use function docker\docker_exit_code;

// Deprecations collected by the last PHPUnit run (see phpunit()), relative to the root directory
const PHPUNIT_DEPRECATIONS_FILE = 'var/deprecations/phpunit.ser';
const DEPRECATIONS_REPORT_FILE = 'var/deprecations/report.md';

#[AsTask(description: 'Runs all the QA tasks, as the CI does', aliases: ['qa'])]
function all(): int
{
    $exitCode = max(lint(), phpstan(), phpunit());

    deprecations();

    return $exitCode;
}

#[AsTask(description: 'Installs the QA tooling (tools/*)')]
function install(): void
{
    io()->title('Installing the QA tooling');

    docker_compose_run(['composer', 'install', '-n', '--prefer-dist', '--optimize-autoloader'], workDir: '/var/www/tools/phpstan');
    docker_compose_run(['composer', 'install', '-n', '--prefer-dist', '--optimize-autoloader'], workDir: '/var/www/tools/phpunit');
}

#[AsTask(description: 'Lints the Twig templates, the YAML files, the Doctrine mapping, the prod container and composer.json', aliases: ['lint'])]
function lint(): int
{
    $commands = [
        ['bin/console', 'lint:twig', 'app/Resources', 'src'],
        // lint:yaml only takes one path in Symfony 3.4
        ['bin/console', 'lint:yaml', 'app/config'],
        ['bin/console', 'lint:yaml', 'src'],
        // Needs the database and its schema (castor app:db:schema)
        ['bin/console', 'doctrine:schema:validate'],
        ['bin/console', 'cache:warmup', '--env=prod'],
        ['composer', 'validate', '--no-check-publish'],
    ];

    // Every lint runs, even after a failure, to report all the problems at once
    $exitCode = 0;
    foreach ($commands as $command) {
        io()->section(implode(' ', $command));

        $exitCode = max($exitCode, docker_exit_code($command));
    }

    return $exitCode;
}

#[AsTask(description: 'Runs PHPStan', aliases: ['phpstan'])]
function phpstan(
    #[AsOption(description: 'Generates the baseline file', shortcut: 'b')]
    bool $baseline = false,
): int {
    install_if_missing();

    io()->section('Running PHPStan');

    $command = ['tools/phpstan/vendor/bin/phpstan', 'analyse', '--memory-limit=-1'];
    if ($baseline) {
        $command = [...$command, '--generate-baseline', '--allow-empty-baseline'];
    }

    return docker_exit_code($command);
}

/**
 * @param list<string> $rawTokens
 */
#[AsTask(description: 'Runs PHPUnit, and collects its deprecations for qa:deprecations', aliases: ['phpunit'])]
function phpunit(#[AsRawTokens] array $rawTokens = []): int
{
    install_if_missing();

    io()->section('Running PHPUnit');

    fs()->mkdir(\dirname(variable('root_dir') . '/' . PHPUNIT_DEPRECATIONS_FILE));

    // With this variable, the bootstrap of symfony/phpunit-bridge does not register its usual
    // deprecations handler (summary at the end of the run, exit code): it serializes every
    // deprecation, [error_reporting() & $type, $message, $file], in the given file instead.
    // The bridge uses it for the tests run in a separate process; it is the only structured
    // output of the bridge of Symfony 3.4.
    return docker_exit_code([
        'env', 'SYMFONY_DEPRECATIONS_SERIALIZE=/var/www/' . PHPUNIT_DEPRECATIONS_FILE,
        'tools/phpunit/vendor/bin/phpunit',
        ...$rawTokens,
    ]);
}

#[AsTask(description: 'Reports the Symfony deprecations grouped by message: container compilation and last PHPUnit run (never fails)')]
function deprecations(): void
{
    io()->section('Reporting the Symfony deprecations');

    $rootDir = variable('root_dir');

    // The kernel writes the deprecations of the container compilation in the cache, next to the
    // container, except under PHPUnit. The optional warmers would compile the Twig templates: the
    // next PHPUnit run would not see their deprecations.
    docker_compose_run(['bin/console', 'cache:clear', '--no-optional-warmers', '--env=test']);

    $counts = [];
    $phpCounts = [];

    foreach (glob($rootDir . '/var/cache/test/*Deprecations.log') ?: [] as $file) {
        foreach (unserialize(file_get_contents($file), ['allowed_classes' => false]) as $log) {
            if (\E_USER_DEPRECATED !== $log['type']) {
                $phpCounts[$log['message']] = ($phpCounts[$log['message']] ?? 0) + $log['count'];

                continue;
            }
            $counts[$log['message']]['container'] = ($counts[$log['message']]['container'] ?? 0) + $log['count'];
        }
    }

    $phpunitFile = $rootDir . '/' . PHPUNIT_DEPRECATIONS_FILE;
    $phpunitRun = is_file($phpunitFile) ? date('Y-m-d H:i', filemtime($phpunitFile)) : null;
    if (null !== $phpunitRun) {
        // error_reporting is -1 in phpunit.xml.dist: the first value is E_DEPRECATED for the
        // deprecations of PHP itself, 0 for the ones Symfony silences (@trigger_error())
        foreach (unserialize(file_get_contents($phpunitFile), ['allowed_classes' => false]) as [$unsilenced, $message]) {
            if (\E_DEPRECATED === $unsilenced) {
                $phpCounts[$message] = ($phpCounts[$message] ?? 0) + 1;

                continue;
            }
            $counts[$message]['phpunit'] = ($counts[$message]['phpunit'] ?? 0) + 1;
        }
    }

    $rows = [];
    foreach ($counts as $message => $count) {
        $container = $count['container'] ?? 0;
        $tests = $count['phpunit'] ?? 0;
        $rows[] = [$container + $tests, $container, $tests, $message];
    }
    // Most frequent first, then by message
    usort($rows, static fn (array $a, array $b) => [$b[0], $a[3]] <=> [$a[0], $b[3]]);

    $total = array_sum(array_column($rows, 0));
    $report = "## Symfony deprecations\n\n";
    $report .= \sprintf("%d deprecations, %d distinct messages: container compilation (test environment) and %s.\n", $total, \count($rows), null === $phpunitRun ? 'no PHPUnit run' : "last PHPUnit run ({$phpunitRun})");
    $report .= \sprintf("PHP deprecations (E_DEPRECATED), not listed: %d, %d distinct messages.\n\n", array_sum($phpCounts), \count($phpCounts));
    if ($rows) {
        $report .= "| Total | Container | PHPUnit | Message |\n| ---: | ---: | ---: | --- |\n";
        foreach ($rows as [$count, $container, $tests, $message]) {
            $message = str_replace(['|', "\r", "\n"], ['\|', '', ' '], htmlspecialchars($message, \ENT_NOQUOTES));
            $report .= "| {$count} | {$container} | {$tests} | {$message} |\n";
        }
    }

    fs()->dumpFile($rootDir . '/' . DEPRECATIONS_REPORT_FILE, $report);
    if ($summaryFile = getenv('GITHUB_STEP_SUMMARY')) {
        fs()->appendToFile($summaryFile, $report);
    }

    io()->text(\sprintf('%d Symfony deprecations, %d distinct messages: see %s.', $total, \count($rows), DEPRECATIONS_REPORT_FILE));
}

function install_if_missing(): void
{
    $rootDir = variable('root_dir');

    if (!is_dir($rootDir . '/tools/phpstan/vendor') || !is_dir($rootDir . '/tools/phpunit/vendor')) {
        install();
    }
}
