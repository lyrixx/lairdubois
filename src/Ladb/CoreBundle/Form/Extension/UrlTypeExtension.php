<?php

namespace Ladb\CoreBundle\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The default protocol of Symfony 7, prepended to a URL typed without one, which Symfony 8 sets to
 * null. On every UrlType, those that the validator guesses from #[Assert\Url] included.
 */
final class UrlTypeExtension extends AbstractTypeExtension {

	public static function getExtendedTypes(): iterable {
		return array( UrlType::class );
	}

	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefault('default_protocol', 'http');
	}

}
