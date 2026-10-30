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
use PHPStan\Type\VoidType;
use Rector\Composer\InstalledPackageResolver;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\AnnotationToAttributeRector;
use Rector\Php80\Rector\Property\NestedAnnotationToAttributeRector;
use Rector\Php80\ValueObject\AnnotationPropertyToAttributeClass;
use Rector\Php80\ValueObject\AnnotationToAttribute;
use Rector\Php80\ValueObject\NestedAnnotationToAttribute;
use Rector\PhpParser\Printer\BetterStandardPrinter;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Symfony\Symfony42\Rector\New_\RootNodeTreeBuilderRector;
use Rector\Symfony\Symfony43\Rector\MethodCall\MakeDispatchFirstArgumentEventRector;
use Rector\Symfony\Symfony44\Rector\ClassMethod\ConsoleExecuteReturnIntRector;
use Rector\Symfony\Symfony51\Rector\Class_\LogoutSuccessHandlerToLogoutEventSubscriberRector;
use Rector\Symfony\Symfony61\Rector\Class_\CommandConfigureToAttributeRector;
use Rector\Symfony\Symfony61\Rector\Class_\CommandPropertyToAttributeRector;
use Rector\Symfony\Symfony62\Rector\Class_\MessageHandlerInterfaceToAttributeRector;
use Rector\Symfony\Symfony62\Rector\Class_\MessageSubscriberInterfaceToAttributeRector;
use Rector\Symfony\Symfony62\Rector\Class_\SecurityAttributeToIsGrantedAttributeRector;
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
            // $defaultName of the commands (deprecated in 6.1) and the setDescription() of their
            // configure() → #[AsCommand] (#28)
            CommandPropertyToAttributeRector::class,
            CommandConfigureToAttributeRector::class,
            // #[Security] of SensioFrameworkExtraBundle → #[IsGranted] of Symfony 6.2 (#27), after
            // the @Security annotations become attributes (a first pass)
            SecurityAttributeToIsGrantedAttributeRector::class,
            // MessageHandlerInterface and MessageSubscriberInterface of Messenger (deprecated in
            // 6.2) → #[AsMessageHandler] (#205)
            MessageHandlerInterfaceToAttributeRector::class,
            MessageSubscriberInterfaceToAttributeRector::class,
        ])
        // Of the renames of symfony/security-core 5.3 (composer-based set of rector-symfony), the
        // one that the code needs (#177). The #[Template] of SensioFrameworkExtraBundle → that of
        // symfony/twig-bridge 6.2 (#27), after the @Template annotations become attributes. The
        // Annotation\Route of symfony/routing, an alias deprecated in 7.4 → Attribute\Route (#205)
        ->withConfiguredRule(RenameClassRector::class, [
            'Sensio\\Bundle\\FrameworkExtraBundle\\Configuration\\Template' => 'Symfony\\Bridge\\Twig\\Attribute\\Template',
            'Symfony\\Component\\Routing\\Annotation\\Route' => 'Symfony\\Component\\Routing\\Attribute\\Route',
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
            // Those that the DebugClassLoader of Symfony 6.4 announces for 7.0 (#205), as given by
            // patch-type-declarations of ErrorHandler in report mode
            new AddReturnTypeDeclaration('Symfony\Component\Console\Command\Command', 'configure', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Console\Command\Command', 'interact', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\DependencyInjection\Extension\ExtensionInterface', 'load', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Form\AbstractType', 'buildForm', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Form\AbstractType', 'buildView', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Form\AbstractType', 'configureOptions', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Form\AbstractType', 'finishView', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Form\Extension\Core\EventListener\ResizeFormListener', 'preSetData', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Form\Extension\Core\EventListener\ResizeFormListener', 'preSubmit', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Security\Core\User\UserCheckerInterface', 'checkPostAuth', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Security\Core\User\UserCheckerInterface', 'checkPreAuth', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Security\Core\User\UserInterface', 'eraseCredentials', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Validator\ConstraintValidatorInterface', 'validate', new VoidType()),
            new AddReturnTypeDeclaration('Symfony\Component\Validator\ObjectInitializerInterface', 'initialize', new VoidType()),
        ])
        // Annotations → attributes (#25), one kind per PR. Rector 2.7 no longer applies
        // withAttributesSets(symfonyRoute: true) alone, and its "symfony" option also converts the
        // constraints and @required. Those of SensioFrameworkExtraBundle become its own attributes
        // (#27), that the rules above replace with those of Symfony.
        // The mapping of Doctrine and Gedmo: what withAttributesSets(doctrine: true, gedmo: true)
        // configures, for the annotations that the code uses (class names as fully qualified
        // ::class). The nested annotations (join columns of a join table, indexes and unique
        // constraints of a table) become attributes of the property or the class, before
        // AnnotationToAttributeRector: it would keep them in their parent, where the
        // AttributeDriver of the ORM 2 ignores those of a table.
        ->withConfiguredRule(NestedAnnotationToAttributeRector::class, [
            new NestedAnnotationToAttribute('Doctrine\ORM\Mapping\JoinTable', [
                new AnnotationPropertyToAttributeClass('Doctrine\ORM\Mapping\JoinColumn', 'joinColumns'),
                new AnnotationPropertyToAttributeClass('Doctrine\ORM\Mapping\InverseJoinColumn', 'inverseJoinColumns', true),
            ]),
            new NestedAnnotationToAttribute('Doctrine\ORM\Mapping\Table', [
                new AnnotationPropertyToAttributeClass('Doctrine\ORM\Mapping\Index', 'indexes', true),
                new AnnotationPropertyToAttributeClass('Doctrine\ORM\Mapping\UniqueConstraint', 'uniqueConstraints', true),
            ]),
        ])
        ->withConfiguredRule(AnnotationToAttributeRector::class, [
            new AnnotationToAttribute('Doctrine\ORM\Mapping\Column'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\DiscriminatorColumn'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\DiscriminatorMap'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\Entity', null, ['repositoryClass']),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\GeneratedValue'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\HasLifecycleCallbacks'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\Id'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\Index'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\InheritanceType'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\JoinColumn'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\JoinTable'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\ManyToMany', null, ['targetEntity']),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\ManyToOne', null, ['targetEntity']),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\MappedSuperclass', null, ['repositoryClass']),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\OneToMany', null, ['targetEntity']),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\OneToOne', null, ['targetEntity']),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\OrderBy'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\PostRemove'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\PreRemove'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\Table'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\UniqueConstraint'),
            new AnnotationToAttribute('Gedmo\Mapping\Annotation\Slug'),
            new AnnotationToAttribute('Gedmo\Mapping\Annotation\Timestampable'),
            new AnnotationToAttribute('Sensio\Bundle\FrameworkExtraBundle\Configuration\Security'),
            new AnnotationToAttribute('Sensio\Bundle\FrameworkExtraBundle\Configuration\Template'),
            new AnnotationToAttribute('Symfony\Component\Routing\Annotation\Route'),
            // The constraints that the code uses: Rector skips ours until they are attributes
            // (#[\Attribute], added by hand)
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ArticleBody'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\BodyBlocks'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\NoMediaLink'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\PlanResourcesMaxSize'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\SchoolTestimonial'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\SelfRecipient'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\SupportedVideoHosting'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\UniqueFind'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidAnswer'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidBookIdentityValue'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidEvent'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidHexColor'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidLocationValue'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidOffer'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidPhoneValue'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidSignValue'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidSoftwareIdentityValue'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidTook'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidUserDisplayname'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidUserEmail'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValidUserUsername'),
            new AnnotationToAttribute('Ladb\CoreBundle\Validator\Constraints\ValueSource'),
            new AnnotationToAttribute('Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Choice'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Count'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Email'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\GreaterThan'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\GreaterThanOrEqual'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Isbn'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Language'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Length'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\NotBlank'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\NotNull'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Range'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Regex'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Type'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Url'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Valid'),
            new AnnotationToAttribute('required', 'Symfony\Contracts\Service\Attribute\Required'),
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

    // A rule of rector-symfony applies from a version of a package of Symfony (symfony/console…):
    // Rector reads the versions installed in the application (its vendor/composer/installed.json),
    // in a composer.json that requires them, not those of tools/rector.
    $packagesFile = __DIR__.'/var/symfony-packages.json';
    @mkdir(dirname($packagesFile), 0777, true);
    $versions = array_column(json_decode(file_get_contents(__DIR__.'/../../vendor/composer/installed.json'), true)['packages'], 'version_normalized', 'name');
    file_put_contents($packagesFile, json_encode(['require' => $versions]));
    $rectorConfig->singleton(InstalledPackageResolver::class, static fn (): InstalledPackageResolver => new InstalledPackageResolver(null, $packagesFile));
};
