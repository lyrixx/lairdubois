<?php

namespace Tests;

use PHPUnit\Framework\TestResult;
use PHPUnit\Util\ErrorHandler;

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
                return self::getPhpUnitErrorHandler()($type, $message, $errorFile, $line);
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

    /**
     * PHPUnit converts the other errors to exceptions, but its handler does not replace this one:
     * it is built with the settings of the running test, as the collector of the bridge does
     * (its DeprecationErrorHandler::getPhpUnitErrorHandler(), private since Symfony 4.4).
     */
    private static function getPhpUnitErrorHandler(): callable
    {
        foreach (debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT | \DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['object']) && $frame['object'] instanceof TestResult) {
                return new ErrorHandler(
                    $frame['object']->getConvertDeprecationsToExceptions(),
                    $frame['object']->getConvertErrorsToExceptions(),
                    $frame['object']->getConvertNoticesToExceptions(),
                    $frame['object']->getConvertWarningsToExceptions()
                );
            }
        }

        // Outside of a test: left to PHP
        return static fn (): bool => false;
    }
}
