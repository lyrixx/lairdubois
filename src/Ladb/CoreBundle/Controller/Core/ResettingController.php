<?php

namespace Ladb\CoreBundle\Controller\Core;

use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Form\Type\Core\ResettingType;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Ladb\CoreBundle\Utils\LoginUtils;
use Ladb\CoreBundle\Utils\MailerUtils;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The forgotten password of FOSUserBundle (#24), with the names of its routes.
 */
final class ResettingController extends AbstractController {

	// resetting.retry_ttl and resetting.token_ttl of FOSUserBundle
	private const RETRY_TTL_IN_SECONDS = 7200;
	private const TOKEN_TTL_IN_SECONDS = 86400;

	#[Route(path: '/request', methods: ['GET'], name: 'fos_user_resetting_request')]
	public function requestAction() {
		return $this->render('@FOSUser/Resetting/request.html.twig');
	}

	/**
	 * The same redirection whether the account exists or not. One email per RETRY_TTL_IN_SECONDS at most.
	 */
	#[Route(path: '/send-email', methods: ['POST'], name: 'fos_user_resetting_send_email')]
	public function sendEmailAction(Request $request, MailerUtils $mailerUtils, TokenGeneratorInterface $tokenGenerator, UserManager $userManager) {
		$username = $request->request->get('username');

		$user = $userManager->findUserByUsernameOrEmail($username);
		if (null !== $user && !$user->isPasswordRequestNonExpired(self::RETRY_TTL_IN_SECONDS)) {
			if (null === $user->getConfirmationToken()) {
				$user->setConfirmationToken($tokenGenerator->generateToken());
			}

			$mailerUtils->sendResettingEmailMessage($user);
			$user->setPasswordRequestedAt(new \DateTime());
			$userManager->updateUser($user);
		}

		return $this->redirectToRoute('fos_user_resetting_check_email', array( 'username' => $username ));
	}

	#[Route(path: '/check-email', methods: ['GET'], name: 'fos_user_resetting_check_email')]
	public function checkEmailAction(Request $request) {
		$username = $request->query->get('username');
		if (null === $username || '' === $username) {
			// Not from sendEmailAction()
			return $this->redirectToRoute('fos_user_resetting_request');
		}

		return $this->render('@FOSUser/Resetting/check_email.html.twig');
	}

	#[Route(path: '/reset/{token}', methods: ['GET', 'POST'], name: 'fos_user_resetting_reset')]
	public function resetAction(Request $request, $token, FormFactoryInterface $formFactory, LoginUtils $loginUtils, TranslatorInterface $translator, UserManager $userManager) {
		$user = $userManager->findUserByConfirmationToken($token);
		if (null === $user) {
			return $this->redirectToRoute('fos_user_security_login');
		}
		if (!$user->isPasswordRequestNonExpired(self::TOKEN_TTL_IN_SECONDS)) {
			return $this->redirectToRoute('fos_user_resetting_request');
		}

		$form = $formFactory->createNamed('fos_user_resetting_form', ResettingType::class, $user);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {
			$user->setConfirmationToken(null);
			$user->setPasswordRequestedAt(null);
			// As FOSUserBundle, for which "enabled" means a proven email address: even an account that an admin disabled (#142)
			$user->setEnabled(true);
			$userManager->updateUser($user);

			$response = $this->redirectToRoute('fos_user_profile_show');

			$loginUtils->logIn($user);

			// Flashbag
			$this->addFlash('success', $translator->trans('resetting.flash.success', array(), 'FOSUserBundle'));

			return $response;
		}

		return $this->render('@FOSUser/Resetting/reset.html.twig', array(
			'token' => $token,
			'form'  => $form->createView(),
		));
	}

}
