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
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Issue #118: the services and parameters that a subclass of AbstractContainerAwareUtils reads from the container
 * ($this->get(), $this->getParameter(), $this->getDoctrine(), and the same on $this->container) become arguments of
 * its constructor, kept in private properties.
 *
 * - The property of a service is named after its class (FooUtils::NAME => $fooUtils) or after SERVICES, the one of a
 *   parameter after the parameter (google_api_key => $googleApiKey), getDoctrine() gives $doctrine.
 * - "$fooUtils = $this->get(FooUtils::NAME);" is removed, and $fooUtils becomes $this->fooUtils in the method, unless
 *   the variable also gets another value or is used by a closure: then only the read is replaced.
 * - The constructor keeps the other arguments of the existing one, without the container. Arguments and properties
 *   are sorted by name.
 * - The class no longer extends AbstractContainerAwareUtils.
 *
 * A class is left untouched when one of its reads is not mechanical: an id that is neither a class constant nor in
 * SERVICES, a parameter whose name is not a string, has(), another use of $this->container, a static method, an
 * existing constructor that does more than assign its arguments.
 */
final class UtilsContainerToConstructorRector extends AbstractRector
{
    private const NAMESPACE_PREFIX = 'Ladb\\CoreBundle\\Utils\\';

    private const PARENT_CLASS = 'Ladb\\CoreBundle\\Utils\\AbstractContainerAwareUtils';

    private const CONTAINER_CLASS = 'Symfony\\Component\\DependencyInjection\\ContainerInterface';

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
        'doctrine.orm.entity_manager' => ['Doctrine\\ORM\\EntityManagerInterface', 'om'],
        'event_dispatcher' => ['Symfony\\Component\\EventDispatcher\\EventDispatcherInterface', 'dispatcher'],
        'liip_imagine.cache.manager' => ['Liip\\ImagineBundle\\Imagine\\Cache\\CacheManager', 'cacheManager'],
        'logger' => ['Psr\\Log\\LoggerInterface', 'logger'],
        'mailer' => ['Swift_Mailer', 'mailer'],
        'router' => ['Symfony\\Component\\Routing\\RouterInterface', 'router'],
        'templating' => ['Symfony\\Bundle\\FrameworkBundle\\Templating\\EngineInterface', 'templating'],
        'translator' => ['Symfony\\Component\\Translation\\TranslatorInterface', 'translator'],
    ];

    /**
     * What AbstractContainerAwareUtils::getDoctrine() returns.
     */
    private const DOCTRINE = ['Doctrine\\Persistence\\ManagerRegistry', 'doctrine'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Container reads of a container aware utils to arguments of its constructor', [
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
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Class_ && $this->refactorClass($node, $stmt)) {
                $changed = true;
            }
        }

        if (!$changed) {
            return null;
        }
        $this->removeUnusedContainerImport($node);

        return $node;
    }

    private function refactorClass(Namespace_ $namespace, Class_ $class): bool
    {
        $className = $this->getName($class);
        if (null === $className || !str_starts_with($className, self::NAMESPACE_PREFIX) || null === $class->extends || self::PARENT_CLASS !== $this->getName($class->extends)) {
            return false;
        }

        // 1. Collect the reads, and check that they are mechanical
        // Property name => ['type' => written type or null for a parameter]
        $properties = [];
        $isMechanical = true;
        foreach ($class->getMethods() as $method) {
            if (null === $method->stmts) {
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
        if ([] === $properties && null === $class->getMethod('__construct')) {
            $class->extends = null;

            return true;
        }
        if ([] === $properties) {
            return false;
        }

        // The existing constructor: its arguments, except the container, and its statements, except the call to the parent
        $constructor = $class->getMethod('__construct');
        $params = [];
        $otherStmts = [];
        $existingProperties = [];
        if (null !== $constructor) {
            foreach ($constructor->params as $param) {
                $name = $this->getName($param->var);
                if (null !== $param->type && self::CONTAINER_CLASS === $this->getName($param->type)) {
                    continue;
                }
                if (isset($properties[$name])) {
                    return false;
                }
                $params[$name] = $param;
            }
            foreach ((array) $constructor->stmts as $stmt) {
                if ($stmt instanceof Expression && $stmt->expr instanceof StaticCall && $this->isName($stmt->expr->class, 'parent') && $this->isName($stmt->expr->name, '__construct')) {
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

        // 3. The constructor and the properties, sorted by name
        $types = [];
        foreach ($params as $name => $param) {
            $type = $param->type?->getAttribute(AttributeKey::ORIGINAL_NAME) ?? $param->type;
            $types[$name] = null === $type ? null : ($type instanceof FullyQualified ? $type->toCodeString() : $type->toString());
        }
        foreach ($properties as $name => $read) {
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
        $class->stmts = array_values(array_filter($class->stmts, fn (Stmt $stmt): bool => !($stmt instanceof Property && 1 === \count($stmt->props) && isset($visibilities[$this->getName($stmt->props[0])])) && !($stmt instanceof ClassMethod && '__construct' === $stmt->name->toString())));
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
            $code .= "\t".($visibilities[$name] ?? 'private').' $'.$name.";\n";
        }
        $signature = [];
        $assignments = '';
        foreach ($types as $name => $type) {
            $signature[] = (null === $type ? '' : $type.' ').'$'.$name;
            $assignments .= "\t\t\$this->".$name.' = $'.$name.";\n";
        }
        $code .= "\n\tpublic function __construct(".implode(', ', $signature).") {\n".$assignments."\t}";
        array_splice($class->stmts, $position, 0, [new ClassConst([new Node\Const_(self::INSERTED_CODE, new String_(base64_encode($code)))])]);

        $class->extends = null;

        return true;
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
        if (file_exists(__DIR__.'/../../../src/Ladb/CoreBundle/Utils/'.$shortName.'.php')) {
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

    private function removeUnusedContainerImport(Namespace_ $namespace): void
    {
        $used = false;
        $this->traverseNodesWithCallable(array_filter($namespace->stmts, fn (Stmt $stmt): bool => !$stmt instanceof Use_), function (Node $node) use (&$used): ?Node {
            if ($node instanceof Name && self::CONTAINER_CLASS === $this->getName($node)) {
                $used = true;
            }

            return null;
        });
        if ($used) {
            return;
        }
        foreach ($namespace->stmts as $i => $stmt) {
            if ($stmt instanceof Use_ && 1 === \count($stmt->uses) && self::CONTAINER_CLASS === $stmt->uses[0]->name->toString()) {
                unset($namespace->stmts[$i]);
                $namespace->stmts = array_values($namespace->stmts);

                return;
            }
        }
    }

    private function isThisOrContainer(Expr $expr): bool
    {
        if ($expr instanceof Variable) {
            return $this->isName($expr, 'this');
        }

        return $expr instanceof PropertyFetch && $expr->var instanceof Variable && $this->isName($expr->var, 'this') && $this->isName($expr->name, 'container');
    }
}
