<?php

namespace Ladb\CoreBundle\Utils;

use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Logs a user in without its password, after its registration or a new password: as the LoginManager
 * and the LastLoginListener of FOSUserBundle did (#24).
 */
final class LoginUtils {

	private $security;

	public function __construct(Security $security) {
		$this->security = $security;
	}

	/////

	/**
	 * As a login by the form, whose authenticator creates the token, in the current request: the user
	 * checker runs, LastLoginListener sets and saves the last login, the session migrates. The
	 * response of the authenticator is not used, the caller returns its own. A disabled account
	 * throws an AccountStatusException, that the firewall turns into a redirection to the login form.
	 */
	public function logIn(User $user) {
		// No "_remember_me" in the forms, thus no cookie: the badge removes the one of a previous login
		$this->security->login($user, 'form_login', 'main', array( new RememberMeBadge() ));
	}

}
