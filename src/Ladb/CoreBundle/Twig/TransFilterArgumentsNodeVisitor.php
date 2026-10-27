<?php

namespace Ladb\CoreBundle\Twig;

use Twig\Environment;
use Twig\Node\EmptyNode;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\NodeVisitor\NodeVisitorInterface;

// Twig 3 gives a filter without arguments an EmptyNode, to which the TranslationDefaultDomainNodeVisitor of the
// TwigBridge of Symfony 4.4 cannot add the domain of {% trans_default_domain %}: the template does not compile
// ("EmptyNode cannot have children"), and the domain stays for the next templates of the process, those of
// cache:warmup included (#138). Runs before it (lowest priority first, its own is -10) and gives the trans filter
// an empty Nodes instead. To remove in 5.4, whose TwigBridge does it.
class TransFilterArgumentsNodeVisitor implements NodeVisitorInterface {

	public function enterNode(Node $node, Environment $env): Node {
		if ($node instanceof FilterExpression && 'trans' === $node->getAttribute('name') && $node->getNode('arguments') instanceof EmptyNode) {
			$node->setNode('arguments', new Nodes());
		}
		return $node;
	}

	public function leaveNode(Node $node, Environment $env): ?Node {
		return $node;
	}

	public function getPriority() {
		return -11;
	}

}
