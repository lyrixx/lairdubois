<?php

namespace Ladb\CoreBundle\Form\Type\Knowledge\Value;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PriceValueType extends AbstractValueType {

	public function buildForm(FormBuilderInterface $builder, array $options): void {
		parent::buildForm($builder, $options);
		$builder
			->add('rawPrice')
			// All the currencies, as before Symfony 7.4, whose CurrencyType only lists the ones in use today
			->add('currency', \Symfony\Component\Form\Extension\Core\Type\CurrencyType::class, array( 'active_at' => null, 'legal_tender' => null ))
		;

		$builder->addEventListener(
			FormEvents::POST_SUBMIT,
			function(FormEvent $event) {
				$value = $event->getForm()->getData();

				$rawPrice = $value->getRawPrice();
				$currency = $value->getCurrency();

				$formatter = new \NumberFormatter('fr_FR',  \NumberFormatter::CURRENCY);
				$value->setData($formatter->formatCurrency($rawPrice, $currency));

			}
		);

	}

	public function configureOptions(OptionsResolver $resolver): void {
		parent::configureOptions($resolver);
		$resolver->setDefaults(array(
			'data_class' => 'Ladb\CoreBundle\Entity\Knowledge\Value\Price',
		));
	}

	public function getBlockPrefix(): string {
		return 'ladb_knowledge_value_price';
	}

}
