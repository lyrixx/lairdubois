<?php

declare(strict_types=1);

namespace Ladb\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Issue #16: the services a controller action reads from the container ($this->get(), $this->container->get())
 * become arguments of the action, resolved by controller.service_arguments.
 *
 * - "$fooUtils = $this->get(FooUtils::NAME);" is removed, and "FooUtils $fooUtils" added to the action: the rest
 *   of the action does not change. The comments before it stay, for the next statement.
 * - An inline read ($this->get('translator')->trans(...)) becomes a variable, and an argument.
 * - A closure of the action that uses one of these variables imports it (use).
 * - The reads that a shortcut of ControllerTrait already does ($this->addFlash(), isGranted(), generateUrl(),
 *   renderView()) become that shortcut, in every method.
 * - Issue #177: the default entity manager ($this->getDoctrine()->getManager(), deprecated in Symfony 5.4) is read
 *   the same way, as "EntityManagerInterface $om". A helper of a class that is not abstract (a method that is not
 *   an action) gets it the same way, and its callers pass it: the actions take it as an argument.
 * - The new arguments are sorted by name with the services already in arguments, before the first argument with a
 *   default value.
 *
 * A read whose id is neither a class constant nor in SERVICES stays. An action is left untouched (except for the
 * shortcuts) when the rewrite would not be mechanical: a read in an anonymous class, a variable that also gets
 * another value or that is already an argument of another type, an action that the class calls itself. Otherwise,
 * methods that are not actions (helpers, traits, abstract controllers) only get the shortcuts: their services are
 * injected by hand.
 */
final class ControllerGetToActionArgumentRector extends AbstractRector
{
    private const NAMESPACE_PREFIX = 'Ladb\\CoreBundle\\Controller\\';

    /**
     * Left in place of a removed statement, then removed with its line by castor qa:rector: php-parser would
     * reprint the comments before the statement, without their indentation or the empty line before them.
     */
    public const REMOVED_LINE = '__RECTOR_REMOVED_LINE__';

    /**
     * Services read by their id: their type, that must be an alias of the container (debug:container --types),
     * and the name of the variable of an inline read.
     */
    private const SERVICES = [
        'event_dispatcher' => ['Symfony\\Component\\EventDispatcher\\EventDispatcherInterface', 'dispatcher'],
        'form.factory' => ['Symfony\\Component\\Form\\FormFactoryInterface', 'formFactory'],
        'fos_user.util.token_generator' => ['FOS\\UserBundle\\Util\\TokenGeneratorInterface', 'tokenGenerator'],
        'fos_user.util.user_manipulator' => ['FOS\\UserBundle\\Util\\UserManipulator', 'userManipulator'],
        'liip_imagine.cache.manager' => ['Liip\\ImagineBundle\\Imagine\\Cache\\CacheManager', 'cacheManager'],
        'liip_imagine.controller' => ['Liip\\ImagineBundle\\Controller\\ImagineController', 'imagineController'],
        'templating' => ['Symfony\\Bundle\\FrameworkBundle\\Templating\\EngineInterface', 'templating'],
        'translator' => ['Symfony\\Component\\Translation\\TranslatorInterface', 'translator'],
    ];

    /**
     * $this->getDoctrine()->getManager(): its type, and the name of the variable of an inline read (the one of the
     * controllers, commands and validators).
     */
    private const ENTITY_MANAGER = ['Doctrine\\ORM\\EntityManagerInterface', 'om'];

    /**
     * Arguments typed by a class that are not services: the request of an action, the entities of a helper.
     */
    private const REQUEST = 'Symfony\\Component\\HttpFoundation\\Request';
    private const ENTITY_NAMESPACE_PREFIX = 'Ladb\\CoreBundle\\Entity\\';

    /**
     * $this->get(<id>)-><method>(...) that ControllerTrait does with $this-><shortcut>(...).
     */
    private const SHORTCUTS = [
        'router' => ['generate', 'generateUrl'],
        'security.authorization_checker' => ['isGranted', 'isGranted'],
        'templating' => ['render', 'renderView'],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Container reads of a controller action to arguments of the action', [
            new CodeSample(
                <<<'CODE_SAMPLE'
public function listAction(Request $request, $page = 0) {
	// Paginate
	$paginatorUtils = $this->get(PaginatorUtils::NAME);
	$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('done'));
}
CODE_SAMPLE,
                <<<'CODE_SAMPLE'
public function listAction(Request $request, PaginatorUtils $paginatorUtils, TranslatorInterface $translator, $page = 0) {
	// Paginate
	$this->addFlash('success', $translator->trans('done'));
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
        $imports = [];
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Class_ && !$stmt instanceof Trait_) {
                continue;
            }
            $className = $this->getName($stmt);
            if (null === $className || !str_starts_with($className, self::NAMESPACE_PREFIX)) {
                continue;
            }

            // An action called by the class itself ($this->createAction($team, $user)) keeps its signature
            $calledMethods = [];
            $this->traverseNodesWithCallable($stmt, function (Node $node) use (&$calledMethods): ?Node {
                if ($node instanceof MethodCall && $node->var instanceof Variable && $this->isName($node->var, 'this') && $node->name instanceof Identifier) {
                    $calledMethods[$node->name->toString()] = true;
                }

                return null;
            });

            // The helpers of a class that is not abstract that read the entity manager, or call such a helper: name =>
            // position of the argument they get
            $helpers = [];
            if ($stmt instanceof Class_ && !$stmt->isAbstract()) {
                $helpers = $this->findEntityManagerHelpers($stmt, $calledMethods);
            }

            foreach ($stmt->getMethods() as $method) {
                if (null === $method->stmts) {
                    continue;
                }
                $changed = $this->refactorShortcuts($method) || $changed;
                $name = $method->name->toString();
                if ($this->isAction($stmt, $method, $calledMethods)) {
                    $changed = $this->refactorAction($method, $imports, $helpers) || $changed;
                } elseif (isset($helpers[$name])) {
                    if (!$this->refactorAction($method, $imports, $helpers)) {
                        throw new \LogicException(sprintf('The helper %s::%s() reads the entity manager, but its rewrite is not mechanical.', $className, $name));
                    }
                    $changed = true;
                }
            }
        }

        if (!$changed) {
            return null;
        }
        $this->addImports($node, $imports);

        return $node;
    }

    /**
     * Adds the imports after the last one of the file, unless already there.
     *
     * @param array<string, string> $imports
     */
    private function addImports(Namespace_ $namespace, array $imports): void
    {
        $lastUse = null;
        foreach ($namespace->stmts as $i => $stmt) {
            if (!$stmt instanceof Use_) {
                continue;
            }
            $lastUse = $i;
            foreach ($stmt->uses as $use) {
                unset($imports[$use->name->toString()]);
            }
        }
        if ([] === $imports) {
            return;
        }
        ksort($imports);
        $uses = [];
        foreach ($imports as $import) {
            $uses[] = new Use_([new UseItem(new Name($import))]);
        }
        array_splice($namespace->stmts, null === $lastUse ? 0 : $lastUse + 1, 0, $uses);
    }

    private function refactorShortcuts(ClassMethod $method): bool
    {
        $changed = false;
        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use (&$changed): ?Node {
            if (!$node instanceof MethodCall || !$node->name instanceof Identifier) {
                return null;
            }

            // $this->get('session')->getFlashBag()->add(...)
            if ('add' === $node->name->toString() && $node->var instanceof MethodCall && $this->isName($node->var->name, 'getFlashBag') && 'session' === $this->matchServiceId($node->var->var)) {
                $changed = true;
                // The node is changed in place: its arguments keep their formatting
                $node->var = new Variable('this');
                $node->name = new Identifier('addFlash');

                return $node;
            }

            $id = $this->matchServiceId($node->var);
            if (null !== $id && isset(self::SHORTCUTS[$id]) && self::SHORTCUTS[$id][0] === $node->name->toString()) {
                $changed = true;
                $node->var = new Variable('this');
                $node->name = new Identifier(self::SHORTCUTS[$id][1]);

                return $node;
            }

            return null;
        });

        return $changed;
    }

    /**
     * @param array<string, true> $calledMethods
     */
    private function isAction(Class_|Trait_ $class, ClassMethod $method, array $calledMethods): bool
    {
        $name = $method->name->toString();

        return $class instanceof Class_ && $method->isPublic() && str_ends_with($name, 'Action') && !isset($calledMethods[$name]);
    }

    /**
     * The methods of the class that are not actions and read the entity manager, or call such a method: they get it
     * as an argument, sorted like the services of an action, that their callers pass.
     *
     * @param array<string, true> $calledMethods
     *
     * @return array<string, int> Name => position of the argument
     */
    private function findEntityManagerHelpers(Class_ $class, array $calledMethods): array
    {
        $helpers = [];
        foreach ($class->getMethods() as $method) {
            if (null !== $method->stmts && !$this->isAction($class, $method, $calledMethods)) {
                $helpers[$method->name->toString()] = $method;
            }
        }

        $found = [];
        do {
            $added = false;
            foreach ($helpers as $name => $method) {
                if (isset($found[$name])) {
                    continue;
                }
                $reads = false;
                $this->traverseNodesWithCallable($method->stmts, function (Node $node) use (&$reads, $found): ?Node {
                    if ($node instanceof MethodCall && (self::ENTITY_MANAGER[0] === ($this->matchRead($node)['type'] ?? null) || null !== $this->matchHelperCall($node, $found))) {
                        $reads = true;
                    }

                    return null;
                });
                if ($reads) {
                    $found[$name] = true;
                    $added = true;
                }
            }
        } while ($added);

        $positions = [];
        foreach (array_keys($found) as $name) {
            $positions[$name] = $this->findParamPosition($helpers[$name], self::ENTITY_MANAGER[1]);
        }

        return $positions;
    }

    /**
     * $this-><helper>(...), for one of the given helpers.
     *
     * @param array<string, mixed> $helpers
     */
    private function matchHelperCall(MethodCall $methodCall, array $helpers): ?string
    {
        if (!$methodCall->var instanceof Variable || !$this->isName($methodCall->var, 'this') || !$methodCall->name instanceof Identifier || !isset($helpers[$methodCall->name->toString()])) {
            return null;
        }

        return $methodCall->name->toString();
    }

    /**
     * Where a new argument goes: sorted by name with the services already in arguments (typed by a class, except the
     * Request and the entities), before the first one with a default value.
     */
    private function findParamPosition(ClassMethod $method, string $name): int
    {
        foreach ($method->params as $i => $param) {
            if (null !== $param->default || $param->variadic) {
                return $i;
            }
            if (!$param->type instanceof Name) {
                continue;
            }
            $type = (string) $this->getName($param->type);
            if (self::REQUEST !== $type && !str_starts_with($type, self::ENTITY_NAMESPACE_PREFIX) && strcmp((string) $this->getName($param->var), $name) > 0) {
                return $i;
            }
        }

        return \count($method->params);
    }

    /**
     * Rewrites an action, or a helper that gets the entity manager.
     *
     * @param array<string, string> $fileImports
     * @param array<string, int>    $helpers     Helpers of the class that get the entity manager => position of the
     *                                           argument
     */
    private function refactorAction(ClassMethod $method, array &$fileImports, array $helpers = []): bool
    {
        // Variable name => type (as written in the file), for the arguments to add
        $services = [];
        // Fully qualified types to import, for the services read by their id
        $imports = [];

        $existingParams = [];
        foreach ($method->params as $param) {
            $existingParams[$this->getName($param->var)] = null === $param->type ? null : $this->getName($param->type);
        }

        $isMechanical = true;
        $removedAssigns = [];
        $callsHelpers = false;

        // 1. Collect the reads, and check that the rewrite is mechanical
        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use (&$services, &$imports, &$isMechanical, &$removedAssigns, &$callsHelpers, $existingParams, $helpers): ?int {
            if ($node instanceof MethodCall && null !== $this->matchHelperCall($node, $helpers)) {
                $callsHelpers = true;
            }

            // In an anonymous class, $this is another object
            if ($node instanceof Class_) {
                $this->traverseNodesWithCallable($node, function (Node $inner) use (&$isMechanical): ?Node {
                    if ($inner instanceof MethodCall && null !== $this->matchRead($inner)) {
                        $isMechanical = false;
                    }

                    return null;
                });

                return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            if ($node instanceof Expression && $node->expr instanceof Assign && $node->expr->var instanceof Variable && $node->expr->expr instanceof MethodCall && null !== $read = $this->matchRead($node->expr->expr)) {
                $name = $this->getName($node->expr->var);
                if (null === $name || !$this->addService($services, $imports, $existingParams, $name, $read)) {
                    $isMechanical = false;
                }
                $removedAssigns[spl_object_id($node->expr)] = true;

                return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            if ($node instanceof MethodCall && null !== $read = $this->matchRead($node)) {
                $name = $this->findServiceName($services, $read) ?? $read['variable'];
                if (null === $name || !$this->addService($services, $imports, $existingParams, $name, $read)) {
                    $isMechanical = false;
                }
            }

            return null;
        });

        // A call to a helper that gets the entity manager passes it
        $entityManagerRead = $this->createEntityManagerRead();
        if ($callsHelpers) {
            $name = $this->findServiceName($services, $entityManagerRead) ?? $entityManagerRead['variable'];
            if (!$this->addService($services, $imports, $existingParams, $name, $entityManagerRead)) {
                $isMechanical = false;
            }
        }

        if ([] === $services) {
            return false;
        }

        // A variable of a service that also gets another value
        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use (&$isMechanical, $services, $removedAssigns): ?Node {
            $assigned = null;
            if ($node instanceof Assign && !isset($removedAssigns[spl_object_id($node)])) {
                $assigned = $node->var;
            } elseif ($node instanceof Foreach_) {
                $assigned = $node->valueVar;
            } elseif ($node instanceof Closure || $node instanceof ArrowFunction) {
                foreach ($node->params as $param) {
                    if ($param->var instanceof Variable && \is_string($param->var->name) && isset($services[$param->var->name])) {
                        $isMechanical = false;
                    }
                }
            }
            if ($assigned instanceof Variable && \is_string($assigned->name) && isset($services[$assigned->name])) {
                $isMechanical = false;
            }

            return null;
        });

        if (!$isMechanical) {
            if ($callsHelpers) {
                throw new \LogicException(sprintf('%s() calls a helper that gets the entity manager, but its rewrite is not mechanical.', $method->name->toString()));
            }

            return false;
        }

        // 2. Rewrite: remove the assignments, replace the inline reads by their variable, that the closures use
        $this->removeAssignments($method);
        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use ($services): ?Node {
            if (!$node instanceof MethodCall || null === $read = $this->matchRead($node)) {
                return null;
            }

            return new Variable($this->findServiceName($services, $read));
        });
        if ($callsHelpers) {
            $variable = $this->findServiceName($services, $entityManagerRead);
            $this->traverseNodesWithCallable($method->stmts, function (Node $node) use ($helpers, $variable): int|Node|null {
                if ($node instanceof Class_) {
                    return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if (!$node instanceof MethodCall || null === $helper = $this->matchHelperCall($node, $helpers)) {
                    return null;
                }
                if (\count($node->args) < $helpers[$helper]) {
                    throw new \LogicException(sprintf('A call to %s() passes fewer arguments than the position of the entity manager.', $helper));
                }
                array_splice($node->args, $helpers[$helper], 0, [new Arg(new Variable($variable))]);

                return $node;
            });
        }
        $this->addClosureUses($method, $services);

        // 3. Add the arguments
        ksort($services);
        foreach ($services as $name => $service) {
            if (\array_key_exists($name, $existingParams)) {
                continue;
            }
            $written = $service['written'];
            $newParam = new Param(new Variable($name), null, $written instanceof FullyQualified ? new FullyQualified($written->toString()) : new Name($written->toString()));
            array_splice($method->params, $this->findParamPosition($method, $name), 0, [$newParam]);
        }

        $fileImports += $imports;

        return true;
    }

    /**
     * @param array<string, array{type: string, written: Name}> $services
     * @param array<string, string>                                $imports
     * @param array<string, string|null>                           $existingParams
     * @param array{type: string, written: Name, import: string|null, variable: string|null} $read
     */
    private function addService(array &$services, array &$imports, array $existingParams, string $name, array $read): bool
    {
        if (isset($services[$name]) && $services[$name]['type'] !== $read['type']) {
            return false;
        }
        if (\array_key_exists($name, $existingParams) && $existingParams[$name] !== $read['type']) {
            return false;
        }
        $services[$name] = ['type' => $read['type'], 'written' => $read['written']];
        if (null !== $read['import']) {
            $imports[$read['import']] = $read['import'];
        }

        return true;
    }

    /**
     * @param array<string, array{type: string, written: Name}> $services
     * @param array{type: string} $read
     */
    private function findServiceName(array $services, array $read): ?string
    {
        foreach ($services as $name => $service) {
            if ($service['type'] === $read['type']) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A closure that uses the variable of a service, now an argument of the action, imports it.
     *
     * @param array<string, array{type: string, written: Name}> $services
     */
    private function addClosureUses(ClassMethod $method, array $services): void
    {
        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use ($services): ?Node {
            if (!$node instanceof Closure) {
                return null;
            }

            $used = [];
            $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$used, $services): ?Node {
                if ($inner instanceof Variable && \is_string($inner->name) && isset($services[$inner->name])) {
                    $used[$inner->name] = true;
                }

                return null;
            });
            foreach ($node->uses as $use) {
                unset($used[$use->var->name]);
            }
            if ([] === $used) {
                return null;
            }

            ksort($used);
            foreach (array_keys($used) as $name) {
                $node->uses[] = new ClosureUse(new Variable($name));
            }

            return $node;
        });
    }

    /**
     * Replaces "$foo = $this->get(...);" by "REMOVED_LINE;", that castor qa:rector removes with its line.
     */
    private function removeAssignments(ClassMethod $method): void
    {
        $this->traverseNodesWithCallable($method, function (Node $node): ?Node {
            if (!$node instanceof Expression || !$node->expr instanceof Assign || !$node->expr->var instanceof Variable || !$node->expr->expr instanceof MethodCall || null === $this->matchRead($node->expr->expr)) {
                return null;
            }
            $node->expr = new ConstFetch(new Name(self::REMOVED_LINE));

            return $node;
        });
    }

    /**
     * @return array{type: string, written: Name, import: string|null, variable: string|null}|null
     */
    private function matchRead(MethodCall $methodCall): ?array
    {
        // $this->getDoctrine()->getManager()
        if ($this->isName($methodCall->name, 'getManager') && [] === $methodCall->args && $methodCall->var instanceof MethodCall && $this->isName($methodCall->var->name, 'getDoctrine') && [] === $methodCall->var->args && $methodCall->var->var instanceof Variable && $this->isName($methodCall->var->var, 'this')) {
            return $this->createEntityManagerRead();
        }

        if (!$this->isThisOrContainer($methodCall->var) || !$this->isName($methodCall->name, 'get') || 1 !== \count($methodCall->args)) {
            return null;
        }
        $arg = $methodCall->getArgs()[0]->value;

        // $this->get(FooUtils::NAME), $this->get(FooUtils::class)
        if ($arg instanceof ClassConstFetch && $arg->class instanceof Name) {
            $type = $this->getName($arg->class);
            $original = $arg->class->getAttribute(AttributeKey::ORIGINAL_NAME);
            $written = $original instanceof Name ? $original : $arg->class;
            $shortName = substr((string) strrchr('\\'.$type, '\\'), 1);

            return ['type' => $type, 'written' => $written, 'import' => null, 'variable' => lcfirst($shortName)];
        }

        // $this->get('translator')
        if ($arg instanceof String_ && isset(self::SERVICES[$arg->value])) {
            [$type, $variable] = self::SERVICES[$arg->value];
            $shortName = substr((string) strrchr('\\'.$type, '\\'), 1);

            return ['type' => $type, 'written' => new Name($shortName), 'import' => $type, 'variable' => $variable];
        }

        return null;
    }

    /**
     * @return array{type: string, written: Name, import: string, variable: string}
     */
    private function createEntityManagerRead(): array
    {
        [$type, $variable] = self::ENTITY_MANAGER;
        $shortName = substr((string) strrchr('\\'.$type, '\\'), 1);

        return ['type' => $type, 'written' => new Name($shortName), 'import' => $type, 'variable' => $variable];
    }

    private function matchServiceId(Expr $expr): ?string
    {
        if (!$expr instanceof MethodCall || !$this->isThisOrContainer($expr->var) || !$this->isName($expr->name, 'get') || 1 !== \count($expr->args)) {
            return null;
        }
        $arg = $expr->getArgs()[0]->value;

        return $arg instanceof String_ ? $arg->value : null;
    }

    private function isThisOrContainer(Expr $expr): bool
    {
        if ($expr instanceof Variable) {
            return $this->isName($expr, 'this');
        }

        return $expr instanceof PropertyFetch && $expr->var instanceof Variable && $this->isName($expr->var, 'this') && $this->isName($expr->name, 'container');
    }
}
