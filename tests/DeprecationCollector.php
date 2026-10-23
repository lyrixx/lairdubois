<?php

namespace Tests;

use Symfony\Bridge\PhpUnit\DeprecationErrorHandler;

/**
 * Counts the deprecations of a PHPUnit run by message, for castor qa:deprecations.
 *
 * Replaces the collector of symfony/phpunit-bridge (SYMFONY_DEPRECATIONS_SERIALIZE), which keeps
 * every occurrence: hundreds per request, more than the memory limit over the smoke test.
 */
final class DeprecationCollector
{
    /**
     * Writes [error_reporting() & $type, $message, $count] for each message in the file, at the
     * end of the run. The first value is 0 for a deprecation silenced by Symfony (@trigger_error()).
     */
    public static function register(string $file): void
    {
        /** @var array<string, array{int, string, int}> $deprecations */
        $deprecations = [];

        set_error_handler(static function (int $type, string $message, string $errorFile = '', int $line = 0) use (&$deprecations): bool {
            if (\E_DEPRECATED !== $type && \E_USER_DEPRECATED !== $type) {
                // PHPUnit converts the other errors to exceptions, as with the collector of the bridge
                return DeprecationErrorHandler::getPhpUnitErrorHandler()($type, $message, $errorFile, $line);
            }

            $unsilenced = error_reporting() & $type;
            $key = $unsilenced.':'.$message;
            $deprecations[$key] ??= [$unsilenced, $message, 0];
            ++$deprecations[$key][2];

            return true;
        });

        register_shutdown_function(static function () use ($file, &$deprecations): void {
            file_put_contents($file, serialize(array_values($deprecations)));
        });
    }
}
