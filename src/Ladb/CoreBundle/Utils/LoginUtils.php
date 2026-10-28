<?php

namespace Ladb\CoreBundle\Utils;

use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeServicesInterface;
use Symfony\Component\Security\Http\Session\SessionAuthenticationStrategyInterface;

/**
 * Logs a user in without its password, after its registration or a new password: as the LoginManager
 * and the LastLoginListener of FOSUserBundle did (#24).
 */
final class LoginUtils {

	private const FIREWALL = 'main';

	private $rememberMeServices;
	private $sessionStrategy;
	private $tokenStorage;
	private $userChecker;

	public function __construct(RememberMeServicesInterface $rememberMeServices, SessionAuthenticationStrategyInterface $sessionStrategy, TokenStorageInterface $tokenStorage, UserCheckerInterface $userChecker) {
		$this->rememberMeServices = $rememberMeServices;
		$this->sessionStrategy = $sessionStrategy;
		$this->tokenStorage = $tokenStorage;
		$this->userChecker = $userChecker;
	}

	/////

	/**
	 * Sets the last login of the user: the caller saves it. A disabled account throws an
	 * AccountStatusException, that the firewall turns into a redirection to the login form.
	 */
	public function logIn(User $user, Request $request, Response $response) {
		$this->userChecker->checkPreAuth($user);

		$token = new UsernamePasswordToken($user, null, self::FIREWALL, $user->getRoles());
		$this->sessionStrategy->onAuthentication($request, $token);

		// No "_remember_me" in the forms, thus no cookie: removes the one of a previous login
		$this->rememberMeServices->loginSuccess($request, $response, $token);

		$this->tokenStorage->setToken($token);

		$user->setLastLogin(new \DateTime());
	}

}
