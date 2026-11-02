<?php

declare(strict_types=1);

namespace Ladb\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Type\MixedType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Issue #217: the sort directions of DoctrineQueryBuilderSortDirectionRector (rector-doctrine) on the query builders
 * that PHPStan cannot type, which that rule skips: the untyped $queryBuilder parameter of the _applyCommonFilter() of
 * the repositories. orderBy() and addOrderBy() of a fluent chain whose first object has no known type, with "ASC" or
 * "DESC" (any case) written as is: no other class of the code has these methods.
 */
final class UntypedQueryBuilderSortDirectionRector extends AbstractRector
{
    private const DIRECTIONS = ['asc' => 'Ascending', 'desc' => 'Descending'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replaces the string sort directions of an untyped query builder with the SortDirection enum', [
            new CodeSample(
                <<<'CODE_SAMPLE'
private function _applyCommonFilter(&$queryBuilder, $filter) {
    $queryBuilder->addOrderBy('c.changedAt', 'DESC');
}
CODE_SAMPLE,
                <<<'CODE_SAMPLE'
private function _applyCommonFilter(&$queryBuilder, $filter) {
    $queryBuilder->addOrderBy('c.changedAt', \SortDirection::Descending);
}
CODE_SAMPLE,
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->isFirstClassCallable() || !$this->isNames($node->name, ['orderBy', 'addOrderBy'])) {
            return null;
        }

        $order = $node->getArgs()[1] ?? null;
        if (!$order?->value instanceof String_ || !isset(self::DIRECTIONS[strtolower($order->value->value)])) {
            return null;
        }

        $root = $node->var;
        while ($root instanceof MethodCall) {
            $root = $root->var;
        }
        if (!$this->getType($root) instanceof MixedType) {
            return null;
        }

        $order->value = $this->nodeFactory->createClassConstFetch('SortDirection', self::DIRECTIONS[strtolower($order->value->value)]);

        return $node;
    }
}
