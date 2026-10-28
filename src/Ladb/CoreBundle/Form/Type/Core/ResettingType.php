<?php

namespace Ladb\CoreBundle\Form\Type\Core;

use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The new password form of FOSUserBundle, after a forgotten password (#24).
 */
final class ResettingType extends AbstractType {

	public function buildForm(FormBuilderInterface $builder, array $options) {
		$builder
			->add('plainPassword', RepeatedType::class, array(
				'type'            => PasswordType::class,
				'options'         => array(
					'translation_domain' => 'FOSUserBundle',
					'attr'               => array( 'autocomplete' => 'new-password' ),
				),
				'first_options'   => array( 'label' => 'form.new_password' ),
				'second_options'  => array( 'label' => 'form.new_password_confirmation' ),
				'invalid_message' => 'fos_user.password.mismatch',
			))
		;
	}

	public function configureOptions(OptionsResolver $resolver) {
		$resolver->setDefaults(array(
			'data_class'        => User::class,
			'csrf_token_id'     => 'resetting',
			'validation_groups' => array( 'ResetPassword', 'Default' ),
		));
	}

	public function getBlockPrefix(): string {
		return 'fos_user_resetting';
	}

}
