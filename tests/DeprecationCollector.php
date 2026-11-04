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
     * Writes [error_reporting() & $type, $message, $count, $origins] for each message in the file,
     * at the end of the run. The first value is 0 for a deprecation silenced by Symfony
     * (@trigger_error()). $origins counts a deprecation of PHP itself (E_DEPRECATED) by the
     * "file:line" where PHP raises it, then by its caller (getCaller()), relative to the root
     * directory, the template for a compiled Twig template. Empty for the others:
     * trigger_error() would be their origin.
     */
    public static function register(string $file): void
    {
        /** @var array<string, array{int, string, int, array<string, array<string, int>>}> $deprecations */
        $deprecations = [];

        set_error_handler(static function (int $type, string $message, string $errorFile = '', int $line = 0) use (&$deprecations): bool {
            if (\E_DEPRECATED !== $type && \E_USER_DEPRECATED !== $type) {
                return self::getPhpUnitErrorHandler()($type, $message, $errorFile, $line);
            }

            $unsilenced = error_reporting() & $type;
            $key = $unsilenced.':'.$message;
            $deprecations[$key] ??= [$unsilenced, $message, 0, []];
            ++$deprecations[$key][2];

            if (\E_DEPRECATED === $unsilenced) {
                $origin = $errorFile.':'.$line;
                $caller = self::getCaller($errorFile, $line);
                $deprecations[$key][3][$origin][$caller] = ($deprecations[$key][3][$origin][$caller] ?? 0) + 1;
            }

            return true;
        });

        register_shutdown_function(static function () use ($file, &$deprecations): void {
            foreach ($deprecations as &$deprecation) {
                $origins = [];
                foreach ($deprecation[3] as $origin => $callers) {
                    $origin = self::resolve($origin);
                    foreach ($callers as $caller => $count) {
                        $caller = self::resolve($caller);
                        // Two lines of a compiled template can be the same line of the template
                        $origins[$origin][$caller] = ($origins[$origin][$caller] ?? 0) + $count;
                    }
                }
                $deprecation[3] = $origins;
            }
            unset($deprecation);

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

    /**
     * The first frame outside of vendor/ when PHP raises the deprecation in vendor/: the code of
     * the site that called the dependency. Otherwise, the caller of the function where PHP raises
     * it, often the one that passes a wrong value; '' when PHP raises it while compiling the file.
     */
    private static function getCaller(string $errorFile, int $line): string
    {
        $vendor = self::isVendor($errorFile);
        $raised = false;
        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (!isset($frame['file']) || __FILE__ === $frame['file']) {
                continue;
            }
            if ($frame['file'] === $errorFile && $frame['line'] === $line) {
                $raised = true;

                continue;
            }
            if ($vendor) {
                if (!self::isVendor($frame['file'])) {
                    return $frame['file'].':'.$frame['line'];
                }

                continue;
            }

            // No frame in the file yet: PHP is compiling it
            return $raised ? $frame['file'].':'.$frame['line'] : '';
        }

        return '';
    }

    private static function isVendor(string $file): bool
    {
        return str_contains($file, '/vendor/');
    }

    /**
     * "file:line" relative to the root directory, with the template and its line for a compiled
     * Twig template (var/cache/<env>/twig/), as Twig itself does (Template::getDebugInfo()).
     */
    private static function resolve(string $origin): string
    {
        if (!preg_match('/^(.+):(\d+)$/', $origin, $matches)) {
            return $origin;
        }
        [, $file, $line] = $matches;

        // The compiled class ran, it is loaded
        if (str_contains($file, '/twig/') && is_file($file) && preg_match('/^class (__TwigTemplate_\w+)/m', file_get_contents($file), $class) && class_exists($class[1], false)) {
            $template = (new \ReflectionClass($class[1]))->newInstanceWithoutConstructor();
            // From the last line of the compiled class to the first
            foreach ($template->getDebugInfo() as $codeLine => $templateLine) {
                if ($codeLine <= $line) {
                    $file = $template->getSourceContext()->getPath();
                    $line = $templateLine;

                    break;
                }
            }
        }

        $rootDir = \dirname(__DIR__).'/';
        if (str_starts_with($file, $rootDir)) {
            $file = substr($file, \strlen($rootDir));
        }

        return $file.':'.$line;
    }
}
