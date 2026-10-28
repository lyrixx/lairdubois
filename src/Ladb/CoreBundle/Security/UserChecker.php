<?php

namespace Ladb\CoreBundle\Security;

use Ladb\CoreBundle\Entity\Core\User;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What the user checker of Symfony checked of the User of FOSUserBundle (#24), never locked nor
 * expired: a disabled account cannot log in (login form, remember me, LoginUtils). Nothing after the
 * authentication: an admin switches to a disabled account.
 */
final class UserChecker implements UserCheckerInterface {

	public function checkPreAuth(UserInterface $user) {
		if ($user instanceof User && !$user->isEnabled()) {
			$exception = new DisabledException('User account is disabled.');
			$exception->setUser($user);
			throw $exception;
		}
	}

	public function checkPostAuth(UserInterface $user) {
	}

}
