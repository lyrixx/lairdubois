<?php

namespace Ladb\CoreBundle\Form\Type\Core;

use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The password change form of FOSUserBundle (#24).
 */
final class ChangePasswordType extends AbstractType {

	public function buildForm(FormBuilderInterface $builder, array $options): void {
		$builder
			->add('current_password', PasswordType::class, array(
				'label'              => 'form.current_password',
				'translation_domain' => 'FOSUserBundle',
				'mapped'             => false,
				'constraints'        => array(
					new NotBlank(),
					new UserPassword(array( 'message' => 'fos_user.current_password.invalid', 'groups' => array( 'ChangePassword' ) )),
				),
				'attr'               => array( 'autocomplete' => 'current-password' ),
			))
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

	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefaults(array(
			'data_class'        => User::class,
			'csrf_token_id'     => 'change_password',
			'validation_groups' => array( 'ChangePassword', 'Default' ),
		));
	}

	public function getBlockPrefix(): string {
		return 'fos_user_change_password';
	}

}
