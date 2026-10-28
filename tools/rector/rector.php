<?php

declare(strict_types=1);

use Ladb\Rector\ControllerGetToActionArgumentRector;
use Ladb\Rector\ContainerToConstructorRector;
use PhpParser\PrettyPrinterAbstract;
use PHPStan\Type\ArrayType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\UnionType;
use Rector\Composer\InstalledPackageResolver;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\AnnotationToAttributeRector;
use Rector\Php80\ValueObject\AnnotationToAttribute;
use Rector\PhpParser\Printer\BetterStandardPrinter;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Symfony\Symfony42\Rector\New_\RootNodeTreeBuilderRector;
use Rector\Symfony\Symfony43\Rector\MethodCall\MakeDispatchFirstArgumentEventRector;
use Rector\Symfony\Symfony44\Rector\ClassMethod\ConsoleExecuteReturnIntRector;
use Rector\Symfony\Symfony51\Rector\Class_\LogoutSuccessHandlerToLogoutEventSubscriberRector;
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
            // Deprecations of Symfony 5.x (#177)
            LogoutSuccessHandlerToLogoutEventSubscriberRector::class,
        ])
        // Of the renames of symfony/security-core 5.3 (composer-based set of rector-symfony), the
        // one that the code needs (#177)
        ->withConfiguredRule(RenameClassRector::class, [
            'Symfony\\Component\\Security\\Core\\Encoder\\EncoderFactoryInterface' => 'Symfony\\Component\\PasswordHasher\\Hasher\\PasswordHasherFactoryInterface',
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
        // Annotations → attributes (#25), one kind per PR. Rector 2.7 no longer applies
        // withAttributesSets(symfonyRoute: true) alone, and its "symfony" option also converts the
        // constraints and @required. The annotations of SensioFrameworkExtraBundle stay until #27.
        ->withConfiguredRule(AnnotationToAttributeRector::class, [
            new AnnotationToAttribute('Symfony\Component\Routing\Annotation\Route'),
        ])
        ->withIndent("\t", 1)
        ->withCache(__DIR__.'/var')
    ($rectorConfig);

    // php-parser counts a tab of the original code as 4 columns (PrettyPrinterAbstract::$tabWidth),
    // and Rector prints one indent character per column: a node that it reprints (a method that
    // gets an attribute, its docblock) would get 4 tabs per level. One column per tab.
    $rectorConfig->afterResolving(BetterStandardPrinter::class, static function (BetterStandardPrinter $printer): void {
        (new \ReflectionProperty(PrettyPrinterAbstract::class, 'tabWidth'))->setValue($printer, 1);
    });

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
