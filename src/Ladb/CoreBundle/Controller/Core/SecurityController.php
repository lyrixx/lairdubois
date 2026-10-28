<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The login form of FOSUserBundle (#24), with the names of its routes: the firewall handles the form
 * (form_login) and the logout.
 */
final class SecurityController extends AbstractController {

	#[Route(path: '/login', methods: ['GET', 'POST'], name: 'fos_user_security_login')]
	public function loginAction(AuthenticationUtils $authenticationUtils, CsrfTokenManagerInterface $csrfTokenManager) {
		return $this->render('@FOSUser/Security/login.html.twig', array(
			'last_username' => $authenticationUtils->getLastUsername(),
			'error'         => $authenticationUtils->getLastAuthenticationError(),
			'csrf_token'    => $csrfTokenManager->getToken('authenticate')->getValue(),
		));
	}

	#[Route(path: '/login_check', methods: ['POST'], name: 'fos_user_security_check')]
	public function checkAction() {
		throw new \LogicException('The firewall handles the login form: see form_login in security.yml.');
	}

	#[Route(path: '/logout', methods: ['GET', 'POST'], name: 'fos_user_security_logout')]
	public function logoutAction() {
		throw new \LogicException('The firewall handles the logout: see logout in security.yml.');
	}

}
