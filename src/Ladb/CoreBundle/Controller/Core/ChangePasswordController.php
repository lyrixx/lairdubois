<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Form\Type\Core\ChangePasswordType;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The password change of FOSUserBundle (#24), with the name of its route.
 */
final class ChangePasswordController extends AbstractController {

	#[Route(path: '/change-password', methods: ['GET', 'POST'], name: 'fos_user_change_password')]
	public function changePasswordAction(Request $request, FormFactoryInterface $formFactory, TranslatorInterface $translator, UserManager $userManager) {
		$this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

		$user = $this->getUser();

		$form = $formFactory->createNamed('fos_user_change_password_form', ChangePasswordType::class, $user);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {
			$userManager->updateUser($user);

			// Flashbag
			$this->addFlash('success', $translator->trans('change_password.flash.success', array(), 'FOSUserBundle'));

			return $this->redirectToRoute('fos_user_profile_show');
		}

		return $this->render('@FOSUser/ChangePassword/change_password.html.twig', array(
			'form' => $form->createView(),
		));
	}

}
