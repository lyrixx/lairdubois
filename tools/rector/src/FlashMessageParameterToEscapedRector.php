<?php

declare(strict_types=1);

namespace Ladb\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * The flash messages are rendered as HTML (|raw of layout.html.twig), for the markup of their
 * translations: each parameter of a trans() in the message of an addFlash() goes through
 * htmlspecialchars(), with the flags of the "e" filter of Twig. Except %count%, the plural number
 * of the translator, a count of the code. A parameter already escaped is left as is.
 */
final class FlashMessageParameterToEscapedRector extends AbstractRector
{
    private const UNESCAPED_PARAMETERS = ['%count%'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Escapes for HTML the translation parameters of a flash message', [
            new CodeSample(
                <<<'CODE_SAMPLE'
$this->addFlash('success', $translator->trans('wonder.creation.form.alert.update_success', array( '%title%' => $creation->getTitle() )));
CODE_SAMPLE,
                <<<'CODE_SAMPLE'
$this->addFlash('success', $translator->trans('wonder.creation.form.alert.update_success', array( '%title%' => htmlspecialchars($creation->getTitle(), ENT_QUOTES | ENT_SUBSTITUTE) )));
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
        if ($node->isFirstClassCallable() || !$this->isName($node->name, 'addFlash') || !isset($node->getArgs()[1])) {
            return null;
        }

        $changed = false;
        $this->traverseNodesWithCallable($node->getArgs()[1]->value, function (Node $call) use (&$changed): ?Node {
            if (!$call instanceof MethodCall || $call->isFirstClassCallable() || !$this->isName($call->name, 'trans')) {
                return null;
            }

            $parameters = $call->getArgs()[1]->value ?? null;
            if (!$parameters instanceof Array_) {
                return null;
            }

            foreach ($parameters->items as $parameter) {
                if ($parameter->key instanceof String_ && \in_array($parameter->key->value, self::UNESCAPED_PARAMETERS, true)) {
                    continue;
                }
                if ($parameter->value instanceof FuncCall && $this->isName($parameter->value, 'htmlspecialchars')) {
                    continue;
                }

                $parameter->value = new FuncCall(new Name('htmlspecialchars'), [
                    new Arg($parameter->value),
                    new Arg(new BitwiseOr(new ConstFetch(new Name('ENT_QUOTES')), new ConstFetch(new Name('ENT_SUBSTITUTE')))),
                ]);
                $changed = true;
            }

            return null;
        });

        return $changed ? $node : null;
    }
}
