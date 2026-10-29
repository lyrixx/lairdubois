<?php

namespace Ladb\CoreBundle\Form\Type\Funding;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChargeType extends AbstractType {

	public function buildForm(FormBuilderInterface $builder, array $options): void {
		$builder
			->add('label')
			->add('dutyFreeAmount')
			->add('amount')
			->add('isRecurrent')
		;
	}

	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefaults(array(
			'data_class' => 'Ladb\CoreBundle\Entity\Funding\Charge'
		));
	}

	public function getBlockPrefix(): string {
		return 'ladb_funding_charge';
	}

}
