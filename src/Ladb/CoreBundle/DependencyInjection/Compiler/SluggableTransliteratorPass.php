<?php

namespace Ladb\CoreBundle\DependencyInjection\Compiler;

use Behat\Transliterator\Transliterator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The sluggable listener of Gedmo keeps behat/transliterator, its transliteration up to 3.20: since
 * 3.21, it uses symfony/string, which gives other slugs (an apostrophe becomes a separator, "×" and
 * "°" are dropped, Cyrillic and Greek are transliterated otherwise). The tag labels use the same
 * transliteration (TagsToLabelsTransformer). Tests\Core\SlugTest checks both.
 */
final readonly class SluggableTransliteratorPass implements CompilerPassInterface {

	public function process(ContainerBuilder $container): void {
		$container->getDefinition('stof_doctrine_extensions.listener.sluggable')
			->addMethodCall('setTransliterator', [[Transliterator::class, 'transliterate']])
			->addMethodCall('setUrlizer', [[Transliterator::class, 'urlize']]);
	}

}
