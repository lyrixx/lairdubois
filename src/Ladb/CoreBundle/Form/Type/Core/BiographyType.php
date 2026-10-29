<?php

namespace Ladb\CoreBundle\Form\Type\Core;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BiographyType extends AbstractType {

	public function buildForm(FormBuilderInterface $builder, array $options): void {
		$builder
			->add('body')
		;
	}

	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefaults(array(
			'data_class' => 'Ladb\CoreBundle\Entity\Core\Biography'
		));
	}

	public function getBlockPrefix(): string {
		return 'ladb_biography';
	}

}
