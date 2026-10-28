<?php

namespace Ladb\CoreBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The token storage of the SecurityListener of SensioFrameworkExtraBundle (@Security): a NullToken
 * for an anonymous user, whose token is null since the authenticator manager (#177). Without it, the
 * listener denies the access before evaluating the expression: a redirection to the login form in
 * place of the 404 of its statusCode. To remove with SensioFrameworkExtraBundle (#27): the
 * IsGranted attribute of Symfony has no such check.
 */
final readonly class NullTokenStorage implements TokenStorageInterface {

	public function __construct(
		private TokenStorageInterface $tokenStorage,
	) {
	}

	public function getToken(): TokenInterface {
		return $this->tokenStorage->getToken() ?? new NullToken();
	}

	public function setToken(?TokenInterface $token = null): void {
		$this->tokenStorage->setToken($token);
	}

}
