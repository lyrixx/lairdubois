<?php

namespace Ladb\CoreBundle\Utils;

use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Logs a user in without its password, after its registration or a new password: as the LoginManager
 * and the LastLoginListener of FOSUserBundle did (#24).
 */
final class LoginUtils {

	private $authenticator;
	private $userAuthenticator;
	private $userChecker;

	public function __construct(AuthenticatorInterface $authenticator, UserAuthenticatorInterface $userAuthenticator, UserCheckerInterface $userChecker) {
		$this->authenticator = $authenticator;
		$this->userAuthenticator = $userAuthenticator;
		$this->userChecker = $userChecker;
	}

	/////

	/**
	 * As a login by the form, whose authenticator creates the token: LastLoginListener sets and saves
	 * the last login, the session migrates. The response of the authenticator is not used, the caller
	 * returns its own. A disabled account throws an AccountStatusException, that the firewall turns
	 * into a redirection to the login form.
	 */
	public function logIn(User $user, Request $request) {
		// authenticateUser() does not call the user checker
		$this->userChecker->checkPreAuth($user);

		// No "_remember_me" in the forms, thus no cookie: the badge removes the one of a previous login
		$this->userAuthenticator->authenticateUser($user, $this->authenticator, $request, array( new RememberMeBadge() ));
	}

}
