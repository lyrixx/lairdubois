<?php

declare(strict_types=1);

use Ladb\Rector\ControllerGetToActionArgumentRector;
use Ladb\Rector\ContainerToConstructorRector;
use Rector\Composer\InstalledPackageResolver;
use Rector\Config\RectorConfig;
use Rector\Symfony\Symfony42\Rector\New_\RootNodeTreeBuilderRector;
use Rector\Symfony\Symfony43\Rector\MethodCall\MakeDispatchFirstArgumentEventRector;
use Rector\Symfony\Symfony44\Rector\ClassMethod\ConsoleExecuteReturnIntRector;

// The paths come from the command line (castor qa:rector <path>)
return static function (RectorConfig $rectorConfig): void {
    RectorConfig::configure()
        ->withRules([
            ControllerGetToActionArgumentRector::class,
            ContainerToConstructorRector::class,
            // Deprecations of Symfony 4.x (#133): rules of rector-symfony, whose sets for 4.x are
            // gone from Rector 2
            ConsoleExecuteReturnIntRector::class,
            MakeDispatchFirstArgumentEventRector::class,
            RootNodeTreeBuilderRector::class,
        ])
        ->withIndent("\t", 1)
        ->withCache(__DIR__.'/var')
    ($rectorConfig);

    // A rule of rector-symfony applies from a version of a package of Symfony (symfony/console…),
    // read in vendor/composer/installed.json, where symfony/symfony replaces them: Rector would
    // skip it. It reads them in a composer.json that requires the version of symfony/symfony for
    // each of them.
    foreach (json_decode(file_get_contents(__DIR__.'/../../vendor/composer/installed.json'), true)['packages'] as $package) {
        if ('symfony/symfony' === $package['name']) {
            $packagesFile = __DIR__.'/var/symfony-packages.json';
            @mkdir(dirname($packagesFile), 0777, true);
            file_put_contents($packagesFile, json_encode(['require' => array_fill_keys(array_keys($package['replace']), $package['version_normalized'])]));
            $rectorConfig->singleton(InstalledPackageResolver::class, static fn (): InstalledPackageResolver => new InstalledPackageResolver(null, $packagesFile));
        }
    }
};
