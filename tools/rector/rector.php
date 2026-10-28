<?php

declare(strict_types=1);

use Ladb\Rector\ControllerGetToActionArgumentRector;
use Ladb\Rector\ContainerToConstructorRector;
use PHPStan\Type\ArrayType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\UnionType;
use Rector\Composer\InstalledPackageResolver;
use Rector\Config\RectorConfig;
use Rector\Symfony\Symfony42\Rector\New_\RootNodeTreeBuilderRector;
use Rector\Symfony\Symfony43\Rector\MethodCall\MakeDispatchFirstArgumentEventRector;
use Rector\Symfony\Symfony44\Rector\ClassMethod\ConsoleExecuteReturnIntRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationRector;
use Rector\TypeDeclaration\ValueObject\AddReturnTypeDeclaration;

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
        // Return types that the DebugClassLoader of Symfony 5.4 announces for our classes (#177),
        // as its messages give them ("might add … as a native return type declaration")
        ->withConfiguredRule(AddReturnTypeDeclarationRector::class, [
            new AddReturnTypeDeclaration('cebe\markdown\Parser', 'consumeParagraph', new ArrayType(new MixedType(), new MixedType())),
            new AddReturnTypeDeclaration('Stripe\HttpClient\ClientInterface', 'request', new ArrayType(new MixedType(), new MixedType())),
            new AddReturnTypeDeclaration('Symfony\Component\Config\Definition\ConfigurationInterface', 'getConfigTreeBuilder', new ObjectType('Symfony\Component\Config\Definition\Builder\TreeBuilder')),
            new AddReturnTypeDeclaration('Symfony\Component\EventDispatcher\EventSubscriberInterface', 'getSubscribedEvents', new ArrayType(new MixedType(), new MixedType())),
            new AddReturnTypeDeclaration('Symfony\Component\Form\DataTransformerInterface', 'reverseTransform', new MixedType(true)),
            new AddReturnTypeDeclaration('Symfony\Component\Form\DataTransformerInterface', 'transform', new MixedType(true)),
            new AddReturnTypeDeclaration('Symfony\Component\Form\FormTypeInterface', 'getBlockPrefix', new StringType()),
            new AddReturnTypeDeclaration('Symfony\Component\Validator\Constraint', 'getTargets', new UnionType([new StringType(), new ArrayType(new MixedType(), new MixedType())])),
            new AddReturnTypeDeclaration('Symfony\Component\Validator\Constraint', 'validatedBy', new StringType()),
            new AddReturnTypeDeclaration('Twig\Extension\ExtensionInterface', 'getFilters', new ArrayType(new MixedType(), new MixedType())),
            new AddReturnTypeDeclaration('Twig\Extension\ExtensionInterface', 'getFunctions', new ArrayType(new MixedType(), new MixedType())),
            new AddReturnTypeDeclaration('Twig\Extension\ExtensionInterface', 'getTests', new ArrayType(new MixedType(), new MixedType())),
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
