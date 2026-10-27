<?php

namespace Ladb\CoreBundle\Utils;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpFoundation\Request;

class GlobalUtils {

	const NAME = 'ladb_core.global_utils';

	private $debug;
	private $environment;
	private $requestStack;
	private $tokenStorage;

	public function __construct($debug, $environment, RequestStack $requestStack, TokenStorageInterface $tokenStorage) {
		$this->debug = $debug;
		$this->environment = $environment;
		$this->requestStack = $requestStack;
		$this->tokenStorage = $tokenStorage;
	}

	/**
	 * Returns the security context service.
	 *
	 * @return TokenStorage|null The security context
	 */
	public function getSecurity() {
		return $this->tokenStorage;
	}

	/**
	 * Returns the current user.
	 *
	 * @return mixed
	 *
	 * @see TokenInterface::getUser()
	 */
	public function getUser() {
		if (!$security = $this->getSecurity()) {
			return;
		}

		if (!$token = $security->getToken()) {
			return;
		}

		$user = $token->getUser();
		if (!is_object($user)) {
			return;
		}

		return $user;
	}

	/**
	 * Returns the current request.
	 *
	 * @return Request|null The HTTP request object
	 */
	public function getRequest() {
		return $this->requestStack->getCurrentRequest();
	}

	/**
	 * Returns the current session.
	 *
	 * @return Session|null The session
	 */
	public function getSession() {
		if ($request = $this->getRequest()) {
			return $request->getSession();
		}
	}

	/**
	 * Returns the current app environment.
	 *
	 * @return string The current environment string (e.g 'dev')
	 */
	public function getEnvironment() {
		return $this->environment;
	}

	/**
	 * Returns the current app debug mode.
	 *
	 * @return bool    The current debug mode
	 */
	public function getDebug() {
		return (bool)$this->debug;
	}

}
