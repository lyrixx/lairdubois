<?php

namespace Ladb\CoreBundle\Form\Type\Core;

use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The registration form of FOSUserBundle (#24): its fields, its labels and its validation groups.
 */
final class RegistrationType extends AbstractType {

	public function buildForm(FormBuilderInterface $builder, array $options) {
		$builder
			->add('email', EmailType::class, array( 'label' => 'form.email', 'translation_domain' => 'FOSUserBundle' ))
			->add('username', null, array( 'label' => 'form.username', 'translation_domain' => 'FOSUserBundle' ))
			->add('plainPassword', RepeatedType::class, array(
				'type'            => PasswordType::class,
				'options'         => array(
					'translation_domain' => 'FOSUserBundle',
					'attr'               => array( 'autocomplete' => 'new-password' ),
				),
				'first_options'   => array( 'label' => 'form.password' ),
				'second_options'  => array( 'label' => 'form.password_confirmation' ),
				'invalid_message' => 'fos_user.password.mismatch',
			))
		;
	}

	public function configureOptions(OptionsResolver $resolver) {
		$resolver->setDefaults(array(
			'data_class'        => User::class,
			'csrf_token_id'     => 'registration',
			'validation_groups' => array( 'Registration', 'Default' ),
		));
	}

	public function getBlockPrefix() {
		return 'fos_user_registration';
	}

}
