<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Entity\Core\Registration;
use Ladb\CoreBundle\Form\Type\Core\RegistrationType;
use Ladb\CoreBundle\Fos\UserManager;
use Ladb\CoreBundle\Utils\LoginUtils;
use Ladb\CoreBundle\Utils\MailerUtils;
use Ladb\CoreBundle\Utils\MessageUtils;
use Ladb\CoreBundle\Utils\UserUtils;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The registration of FOSUserBundle without its email confirmation (#24), with the names of its routes.
 */
final class RegistrationController extends AbstractController {

	/**
	 * @Route("/", methods={"GET", "POST"}, name="fos_user_registration_register")
	 */
	public function registerAction(Request $request, FormFactoryInterface $formFactory, LoginUtils $loginUtils, MailerUtils $mailerUtils, MessageUtils $messageUtils, TranslatorInterface $translator, UserManager $userManager, UserUtils $userUtils) {
		$user = $userManager->createUser();
		$user->setEnabled(true);

		$form = $formFactory->createNamed('fos_user_registration_form', RegistrationType::class, $user);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {
			$userManager->updateUser($user);

			$response = $this->redirectToRoute('fos_user_registration_confirmed');

			// Last login
			$loginUtils->logIn($user, $request, $response);
			$userManager->updateUser($user);

			// Flashbag
			$this->addFlash('success', $translator->trans('registration.flash.user_created', array(), 'FOSUserBundle'));

			/////

			// Create default avatar
			$userUtils->createDefaultAvatar($user, false);

			/////

			$registration = new Registration();
			$registration->setUser($user);
			$registration->setClientIp4($request->getClientIp());
			$registration->setClientUserAgent($request->server->get('HTTP_USER_AGENT'));

			$om = $this->getDoctrine()->getManager();
			$om->persist($registration);
			$om->flush();

			/////

			// Send welcome Message
			$sender = $userManager->findUserByUsername($this->getParameter('admin_username'));
			if (!is_null($sender)) {
				// Unread message count: composeThread() leaves it to the caller
				$user->getMeta()->incrementUnreadMessageCount();
				$messageUtils->composeThread($sender, array( $user ), 'Bienvenue !', $this->renderView('@LadbCore/Core/User/message-welcome.md.twig', array( 'recipientUser' => $user )), null, true);
			}

			// Send admin email notification
			$mailerUtils->sendNewUserNotificationEmailMessage($user);

			return $response;
		}

		return $this->render('@FOSUser/Registration/register.html.twig', array(
			'form' => $form->createView(),
		));
	}

	/**
	 * @Route("/confirmed", methods={"GET"}, name="fos_user_registration_confirmed")
	 */
	public function confirmedAction() {
		$this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

		return $this->render('@FOSUser/Registration/confirmed.html.twig', array(
			'user' => $this->getUser(),
		));
	}

}
