<?php

declare(strict_types=1);

namespace Ladb\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeVisitor;
use PHPStan\Reflection\ReflectionProvider;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Issue #118: the services and parameters that a subclass of a container aware base class (BASE_CLASSES) reads from
 * the container ($this->get(), $this->getParameter(), $this->getDoctrine(), and the same on $this->container) become
 * arguments of its constructor, kept in private properties.
 *
 * - The property of a service is named after its class (FooUtils::NAME => $fooUtils) or after SERVICES, the one of a
 *   parameter after the parameter (google_api_key => $googleApiKey), getDoctrine() gives $doctrine.
 * - "$fooUtils = $this->get(FooUtils::NAME);" is removed, and $fooUtils becomes $this->fooUtils in the method, unless
 *   the variable also gets another value or is used by a closure: then only the read is replaced.
 * - The constructor keeps the other arguments of the existing one, without the container. Arguments and properties
 *   are sorted by name.
 * - An abstract class gets protected properties, each one set by a @required setter, so that its subclasses keep
 *   their constructor. A property that a parent class already declares (protected) is not injected again: run the
 *   rule on the parent classes first, as it reads them as they are on disk.
 * - The class no longer extends AbstractContainerAwareUtils (#125). AbstractManager stays, with $doctrine.
 * - A class that keeps the container in $this->container, set by its constructor (listeners, validators), loses the
 *   property and the container argument.
 *
 * A class is left untouched when one of its reads is not mechanical: an id that is neither a class constant nor in
 * SERVICES, a parameter whose name is not a string or that an abstract class reads, has(), another use of
 * $this->container, a static method, an existing constructor that does more than assign its arguments or in an
 * abstract class, a setter that already exists.
 */
final class ContainerToConstructorRector extends AbstractRector
{
    /**
     * Namespace => its container aware base class, and whether its subclasses stop extending it.
     */
    private const BASE_CLASSES = [
        'Ladb\\CoreBundle\\Utils\\' => ['Ladb\\CoreBundle\\Utils\\AbstractContainerAwareUtils', true],
        'Ladb\\CoreBundle\\Manager\\' => ['Ladb\\CoreBundle\\Manager\\AbstractManager', false],
    ];

    /**
     * The types of a container argument.
     */
    private const CONTAINER_CLASSES = [
        'Symfony\\Component\\DependencyInjection\\Container',
        'Symfony\\Component\\DependencyInjection\\ContainerInterface',
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Left in place of a removed statement, then removed with its line by castor qa:rector.
     */
    public const REMOVED_LINE = '__RECTOR_REMOVED_LINE__';

    /**
     * A constant whose value is code encoded in base64, that castor qa:rector writes in place of its line.
     */
    public const INSERTED_CODE = '__RECTOR_INSERTED_CODE__';

    /**
     * Services read by their id: their type, that must be an alias of the container (debug:container --types), and
     * the name of the property. The same names as ControllerGetToActionArgumentRector.
     */
    private const SERVICES = [
        // The key of getDoctrine(): the same property
        'doctrine' => ['Doctrine\\Persistence\\ManagerRegistry', 'doctrine'],
        'doctrine.orm.entity_manager' => ['Doctrine\\ORM\\EntityManagerInterface', 'om'],
        'event_dispatcher' => ['Symfony\\Component\\EventDispatcher\\EventDispatcherInterface', 'dispatcher'],
        'liip_imagine.cache.manager' => ['Liip\\ImagineBundle\\Imagine\\Cache\\CacheManager', 'cacheManager'],
        'liip_imagine.data.manager' => ['Liip\\ImagineBundle\\Imagine\\Data\\DataManager', 'dataManager'],
        'liip_imagine.filter.manager' => ['Liip\\ImagineBundle\\Imagine\\Filter\\FilterManager', 'filterManager'],
        'logger' => ['Psr\\Log\\LoggerInterface', 'logger'],
        'mailer' => ['Swift_Mailer', 'mailer'],
        'router' => ['Symfony\\Component\\Routing\\RouterInterface', 'router'],
        'templating' => ['Symfony\\Bundle\\FrameworkBundle\\Templating\\EngineInterface', 'templating'],
        'translator' => ['Symfony\\Component\\Translation\\TranslatorInterface', 'translator'],
    ];

    /**
     * What AbstractContainerAwareUtils::getDoctrine() and AbstractManager::getDoctrine() returned.
     */
    private const DOCTRINE = ['Doctrine\\Persistence\\ManagerRegistry', 'doctrine'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Container reads of a subclass of a container aware base class to arguments of its constructor', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class FooUtils extends AbstractContainerAwareUtils {

	const NAME = 'ladb_core.foo_utils';

	public function foo() {
		$barUtils = $this->get(BarUtils::NAME);
		$barUtils->bar($this->getParameter('secret'));
	}

}
CODE_SAMPLE,
                <<<'CODE_SAMPLE'
class FooUtils {

	const NAME = 'ladb_core.foo_utils';

	private $barUtils;
	private $secret;

	public function __construct(BarUtils $barUtils, $secret) {
		$this->barUtils = $barUtils;
		$this->secret = $secret;
	}

	public function foo() {
		$this->barUtils->bar($this->secret);
	}

}
CODE_SAMPLE
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Namespace_::class];
    }

    /**
     * @param Namespace_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $changed = false;
        // The container, and the classes of the services read in a parent class
        $imports = self::CONTAINER_CLASSES;
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Class_ && $this->refactorClass($node, $stmt, $imports)) {
                $changed = true;
            }
        }

        if (!$changed) {
            return null;
        }
        $this->removeUnusedImports($node, $imports);

        return $node;
    }

    /**
     * @param list<string> $imports The imports that may no longer be used, completed
     */
    private function refactorClass(Namespace_ $namespace, Class_ $class, array &$imports): bool
    {
        $className = $this->getName($class);
        if (null === $className || !$this->reflectionProvider->hasClass($className)) {
            return false;
        }
        $baseClass = null;
        $removesExtends = false;
        foreach (self::BASE_CLASSES as $prefix => [$base, $removes]) {
            if (str_starts_with($className, $prefix)) {
                [$baseClass, $removesExtends] = [$base, $removes];
            }
        }
        $classReflection = $this->reflectionProvider->getClass($className);
        $parentClasses = $classReflection->getParentClassesNames();
        $keepsContainer = $this->keepsContainer($class);
        if (!$keepsContainer && (null === $baseClass || null === $class->extends || ($removesExtends ? $baseClass !== $this->getName($class->extends) : !\in_array($baseClass, $parentClasses, true)))) {
            return false;
        }

        // 1. Collect the reads, and check that they are mechanical
        // Property name => ['type' => written type or null for a parameter]
        $properties = [];
        $isMechanical = true;
        foreach ($class->getMethods() as $method) {
            // The constructor that sets $this->container is checked below
            if (null === $method->stmts || ($keepsContainer && '__construct' === $method->name->toString())) {
                continue;
            }
            $this->traverseNodesWithCallable($method->stmts, function (Node $node) use (&$properties, &$isMechanical, $method, $namespace): ?int {
                if ($node instanceof Class_) {
                    $isMechanical = false;

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof MethodCall && null !== $read = $this->matchRead($node, $namespace)) {
                    if ($method->isStatic() || (isset($properties[$read['property']]) && $properties[$read['property']]['key'] !== $read['key'])) {
                        $isMechanical = false;
                    }
                    $properties[$read['property']] = $read;

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                // Any other use of the container: has(), a dynamic id, $this->container
                if ($node instanceof MethodCall && $node->var instanceof Variable && $this->isName($node->var, 'this') && $this->isNames($node->name, ['get', 'has', 'getParameter', 'getDoctrine'])) {
                    $isMechanical = false;
                }
                if ($node instanceof PropertyFetch && $node->var instanceof Variable && $this->isName($node->var, 'this') && $this->isName($node->name, 'container')) {
                    $isMechanical = false;
                }

                return null;
            });
        }
        if (!$isMechanical) {
            return false;
        }
        // Nothing read from the container
        if ([] === $properties && null === $class->getMethod('__construct') && $removesExtends) {
            $class->extends = null;

            return true;
        }
        if ([] === $properties) {
            return false;
        }

        // Already declared by a parent class (protected): read there, not injected again
        $inherited = [];
        foreach (array_keys($properties) as $name) {
            foreach ($parentClasses as $parentClass) {
                $nativeReflection = $this->reflectionProvider->getClass($parentClass)->getNativeReflection();
                if ($nativeReflection->hasProperty($name) && !$nativeReflection->getProperty($name)->isPrivate()) {
                    $inherited[$name] = true;
                }
            }
        }
        $injected = array_diff_key($properties, $inherited);
        foreach (array_intersect_key($properties, $inherited) as $read) {
            if ($read['type'] instanceof Name) {
                $imports[] = $read['key'];
            }
        }
        if ($class->isAbstract()) {
            if (null !== $class->getMethod('__construct')) {
                return false;
            }
            foreach ($injected as $name => $read) {
                if (null === $read['type'] || $classReflection->hasNativeMethod('set'.ucfirst($name))) {
                    return false;
                }
            }
        }

        // The existing constructor: its arguments, except the container, and its statements, except the call to the parent
        $constructor = $class->getMethod('__construct');
        $params = [];
        $containerParams = [];
        $otherStmts = [];
        $existingProperties = [];
        if (null !== $constructor) {
            foreach ($constructor->params as $param) {
                $name = $this->getName($param->var);
                if (null !== $param->type && \in_array($this->getName($param->type), self::CONTAINER_CLASSES, true)) {
                    $containerParams[] = $name;

                    continue;
                }
                if (isset($injected[$name])) {
                    return false;
                }
                $params[$name] = $param;
            }
            foreach ((array) $constructor->stmts as $stmt) {
                if ($stmt instanceof Expression && $stmt->expr instanceof StaticCall && $this->isName($stmt->expr->class, 'parent') && $this->isName($stmt->expr->name, '__construct')) {
                    continue;
                }
                // $this->container = $container;, removed with the property
                if ($keepsContainer && $this->isContainerAssignment($stmt, $containerParams)) {
                    continue;
                }
                // $this->foo = $foo;, written again below
                if ($stmt instanceof Expression && $stmt->expr instanceof Assign && $stmt->expr->var instanceof PropertyFetch && $stmt->expr->expr instanceof Variable && isset($params[$this->getName($stmt->expr->expr)]) && $this->getName($stmt->expr->var->name) === $this->getName($stmt->expr->expr)) {
                    $existingProperties[$this->getName($stmt->expr->expr)] = true;

                    continue;
                }
                $otherStmts[] = $stmt;
            }
        }
        if ([] !== $otherStmts) {
            return false;
        }

        // 2. Rewrite the methods
        foreach ($class->getMethods() as $method) {
            if (null !== $method->stmts && '__construct' !== $method->name->toString()) {
                $this->refactorMethod($method, $namespace);
            }
        }

        // 3. The constructor or the setters, and the properties, sorted by name
        if ([] === $injected && null === $constructor) {
            if ($removesExtends) {
                $class->extends = null;
            }

            return true;
        }
        $types = [];
        foreach ($params as $name => $param) {
            $type = $param->type?->getAttribute(AttributeKey::ORIGINAL_NAME) ?? $param->type;
            $types[$name] = null === $type ? null : ($type instanceof FullyQualified ? $type->toCodeString() : $type->toString());
        }
        foreach ($injected as $name => $read) {
            $type = \is_string($read['type']) ? $this->writeType($namespace, $read['type']) : $read['type'];
            $types[$name] = null === $type ? null : ($type instanceof FullyQualified ? $type->toCodeString() : $type->toString());
        }
        ksort($types);

        // The properties of the existing constructor keep their visibility
        $visibilities = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property && 1 === \count($stmt->props) && isset($existingProperties[$this->getName($stmt->props[0])])) {
                $visibilities[$this->getName($stmt->props[0])] = $stmt->isProtected() ? 'protected' : ($stmt->isPublic() ? 'public' : 'private');
            }
        }
        if ($keepsContainer) {
            $visibilities['container'] = null;
        }
        $class->stmts = array_values(array_filter($class->stmts, fn (Stmt $stmt): bool => !($stmt instanceof Property && 1 === \count($stmt->props) && \array_key_exists($this->getName($stmt->props[0]), $visibilities)) && !($stmt instanceof ClassMethod && '__construct' === $stmt->name->toString())));
        // After the constants and properties before the first method
        $position = 0;
        foreach ($class->stmts as $i => $stmt) {
            if ($stmt instanceof ClassMethod) {
                break;
            }
            if ($stmt instanceof ClassConst || $stmt instanceof Property) {
                $position = $i + 1;
            }
        }

        // Written as text: php-parser would indent the new members of the class with the wrong number of tabs, and
        // put the brace of the constructor on its own line
        $code = "\n";
        foreach (array_keys($types) as $name) {
            $code .= "\t".($visibilities[$name] ?? ($class->isAbstract() ? 'protected' : 'private')).' $'.$name.";\n";
        }
        if ($class->isAbstract()) {
            foreach ($types as $name => $type) {
                $code .= "\n\t/**\n\t * @required\n\t */\n\tpublic function set".ucfirst($name).'('.$type.' $'.$name.") {\n\t\t\$this->".$name.' = $'.$name.";\n\t}\n";
            }
            $code = rtrim($code, "\n");
        } else {
            $signature = [];
            $assignments = '';
            foreach ($types as $name => $type) {
                $signature[] = (null === $type ? '' : $type.' ').'$'.$name;
                $assignments .= "\t\t\$this->".$name.' = $'.$name.";\n";
            }
            $code .= "\n\tpublic function __construct(".implode(', ', $signature).") {\n".$assignments."\t}";
        }
        // First in the class: after the empty line that follows the brace of the class, and before the next member
        if (0 === $position) {
            $code = substr($code, 1)."\n";
        }
        array_splice($class->stmts, $position, 0, [new ClassConst([new Node\Const_(self::INSERTED_CODE, new String_(base64_encode($code)))])]);

        if ($removesExtends) {
            $class->extends = null;
        }

        return true;
    }

    /**
     * A concrete class with a $container property, that its constructor sets from an argument typed as a container.
     */
    private function keepsContainer(Class_ $class): bool
    {
        $constructor = $class->getMethod('__construct');
        if ($class->isAbstract() || null === $constructor || null === $class->getProperty('container')) {
            return false;
        }
        $containerParams = [];
        foreach ($constructor->params as $param) {
            if (null !== $param->type && \in_array($this->getName($param->type), self::CONTAINER_CLASSES, true)) {
                $containerParams[] = $this->getName($param->var);
            }
        }
        foreach ((array) $constructor->stmts as $stmt) {
            if ($this->isContainerAssignment($stmt, $containerParams)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string|null> $containerParams
     */
    private function isContainerAssignment(Stmt $stmt, array $containerParams): bool
    {
        return $stmt instanceof Expression && $stmt->expr instanceof Assign && $stmt->expr->var instanceof PropertyFetch && $stmt->expr->var->var instanceof Variable && $this->isName($stmt->expr->var->var, 'this') && $this->isName($stmt->expr->var->name, 'container') && $stmt->expr->expr instanceof Variable && \in_array($this->getName($stmt->expr->expr), $containerParams, true);
    }

    private function refactorMethod(ClassMethod $method, Namespace_ $namespace): void
    {
        // Variable => property, for the "$foo = <read>;" statements whose variable gets no other value
        $assigned = [];
        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use (&$assigned, $namespace): ?Node {
            if ($node instanceof Expression && $node->expr instanceof Assign && $node->expr->var instanceof Variable && \is_string($node->expr->var->name) && $node->expr->expr instanceof MethodCall && null !== $read = $this->matchRead($node->expr->expr, $namespace)) {
                $name = $node->expr->var->name;
                if (isset($assigned[$name]) && $assigned[$name] !== $read['property']) {
                    $assigned[$name] = false;
                } else {
                    $assigned[$name] = $read['property'];
                }
            }

            return null;
        });
        foreach ($method->params as $param) {
            unset($assigned[$this->getName($param->var)]);
        }

        // Another value, or a closure
        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use (&$assigned, $namespace): ?Node {
            $vars = [];
            if (($node instanceof Assign && !($node->expr instanceof MethodCall && null !== $this->matchRead($node->expr, $namespace))) || $node instanceof AssignOp || $node instanceof AssignRef) {
                $vars[] = $node->var;
            } elseif ($node instanceof Foreach_) {
                $vars[] = $node->valueVar;
                $vars[] = $node->keyVar;
            } elseif ($node instanceof Closure) {
                foreach ($node->uses as $use) {
                    $vars[] = $use->var;
                }
                foreach ($node->params as $param) {
                    $vars[] = $param->var;
                }
            } elseif ($node instanceof ArrowFunction) {
                foreach ($node->params as $param) {
                    $vars[] = $param->var;
                }
            }
            foreach ($vars as $var) {
                if ($var instanceof List_) {
                    foreach ($var->items as $item) {
                        if (null !== $item) {
                            $vars[] = $item->value;
                        }
                    }
                }
                if ($var instanceof Variable && \is_string($var->name) && isset($assigned[$var->name])) {
                    $assigned[$var->name] = false;
                }
            }

            return null;
        });
        $assigned = array_filter($assigned);

        // Remove the assignments, then replace the variables and the other reads by the property
        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use ($assigned, $namespace): ?Node {
            if ($node instanceof Expression && $node->expr instanceof Assign && $node->expr->var instanceof Variable && \is_string($node->expr->var->name) && isset($assigned[$node->expr->var->name]) && $node->expr->expr instanceof MethodCall && null !== $this->matchRead($node->expr->expr, $namespace)) {
                $node->expr = new ConstFetch(new Name(self::REMOVED_LINE));

                return $node;
            }
            if ($node instanceof Variable && \is_string($node->name) && isset($assigned[$node->name])) {
                return new PropertyFetch(new Variable('this'), $assigned[$node->name]);
            }
            if ($node instanceof MethodCall && null !== $read = $this->matchRead($node, $namespace)) {
                return new PropertyFetch(new Variable('this'), $read['property']);
            }

            return null;
        });
    }

    /**
     * The type is the name written in the file for a class constant, the class otherwise (imported by writeType()).
     *
     * @return array{property: string, key: string, type: Name|string|null}|null
     */
    private function matchRead(MethodCall $methodCall, Namespace_ $namespace): ?array
    {
        if (!$this->isThisOrContainer($methodCall->var) || !$methodCall->name instanceof Identifier) {
            return null;
        }
        $method = $methodCall->name->toString();
        $args = $methodCall->getArgs();

        // $this->getDoctrine()
        if ('getDoctrine' === $method && $methodCall->var instanceof Variable && [] === $args) {
            return ['property' => self::DOCTRINE[1], 'key' => 'doctrine', 'type' => self::DOCTRINE[0]];
        }

        if (1 !== \count($args)) {
            return null;
        }
        $arg = $args[0]->value;

        // $this->getParameter('google_api_key')
        if ('getParameter' === $method && $arg instanceof String_) {
            return ['property' => lcfirst(str_replace(' ', '', ucwords(str_replace(['.', '_'], ' ', $arg->value)))), 'key' => '%'.$arg->value.'%', 'type' => null];
        }

        if ('get' !== $method) {
            return null;
        }

        // $this->get(FooUtils::NAME), $this->get(FooUtils::class)
        if ($arg instanceof ClassConstFetch && $arg->class instanceof Name) {
            $type = $this->getName($arg->class);
            $original = $arg->class->getAttribute(AttributeKey::ORIGINAL_NAME);
            $written = $original instanceof Name ? $original : $arg->class;
            $shortName = substr((string) strrchr('\\'.$type, '\\'), 1);

            return ['property' => lcfirst($shortName), 'key' => $type, 'type' => $written instanceof FullyQualified ? new FullyQualified($written->toString()) : new Name($written->toString())];
        }

        // $this->get('logger')
        if ($arg instanceof String_ && isset(self::SERVICES[$arg->value])) {
            [$type, $property] = self::SERVICES[$arg->value];

            return ['property' => $property, 'key' => $arg->value, 'type' => $type];
        }

        return null;
    }

    /**
     * The name of a type as the file writes it: imported (added unless already there), or fully qualified when its
     * short name is taken or when it is a global class.
     */
    private function writeType(Namespace_ $namespace, string $type): Name
    {
        if (!str_contains($type, '\\')) {
            return new FullyQualified($type);
        }
        $shortName = substr((string) strrchr('\\'.$type, '\\'), 1);
        $lastUse = null;
        foreach ($namespace->stmts as $i => $stmt) {
            if (!$stmt instanceof Use_) {
                continue;
            }
            $lastUse = $i;
            foreach ($stmt->uses as $use) {
                if ($use->name->toString() === $type) {
                    return new Name($use->getAlias()->toString());
                }
                if ($use->getAlias()->toString() === $shortName) {
                    return new FullyQualified($type);
                }
            }
        }
        // A class of the namespace, used without import
        if ($this->reflectionProvider->hasClass($this->getName($namespace).'\\'.$shortName)) {
            return new FullyQualified($type);
        }

        // After the last import, kept sorted by the next ones
        $useStmt = new Use_([new UseItem(new Name($type))]);
        $position = null === $lastUse ? 0 : $lastUse + 1;
        foreach ($namespace->stmts as $i => $stmt) {
            if ($stmt instanceof Use_ && strcmp($stmt->uses[0]->name->toString(), $type) > 0) {
                $position = $i;

                break;
            }
        }
        array_splice($namespace->stmts, $position, 0, [$useStmt]);

        return new Name($shortName);
    }

    /**
     * @param list<string> $classes
     */
    private function removeUnusedImports(Namespace_ $namespace, array $classes): void
    {
        $used = [];
        $this->traverseNodesWithCallable(array_filter($namespace->stmts, fn (Stmt $stmt): bool => !$stmt instanceof Use_), function (Node $node) use (&$used): ?Node {
            if ($node instanceof Name) {
                $used[$this->getName($node)] = true;
            }

            return null;
        });
        foreach ($namespace->stmts as $i => $stmt) {
            if ($stmt instanceof Use_ && 1 === \count($stmt->uses) && \in_array($stmt->uses[0]->name->toString(), $classes, true) && !isset($used[$stmt->uses[0]->name->toString()])) {
                unset($namespace->stmts[$i]);
            }
        }
        $namespace->stmts = array_values($namespace->stmts);
    }

    private function isThisOrContainer(Expr $expr): bool
    {
        if ($expr instanceof Variable) {
            return $this->isName($expr, 'this');
        }

        return $expr instanceof PropertyFetch && $expr->var instanceof Variable && $this->isName($expr->var, 'this') && $this->isName($expr->name, 'container');
    }
}
