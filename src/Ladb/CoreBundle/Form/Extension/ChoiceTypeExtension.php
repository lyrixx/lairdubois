<?php

namespace Ladb\CoreBundle\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * No attribute on the placeholder option, as in Symfony 7: Symfony 8.1 renders the one of a
 * required collapsed choice with "hidden". On every ChoiceType (EntityType, CountryType...).
 */
final class ChoiceTypeExtension extends AbstractTypeExtension {

	public static function getExtendedTypes(): iterable {
		return array( ChoiceType::class );
	}

	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefault('placeholder_attr', array());
	}

}
