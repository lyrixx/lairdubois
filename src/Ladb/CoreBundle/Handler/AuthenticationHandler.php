<?php

namespace Ladb\CoreBundle\Handler;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Router;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AuthenticationHandler implements EventSubscriberInterface {

	protected $router;

	public function __construct(\Symfony\Bundle\FrameworkBundle\Routing\Router $router) {
		$this->router = $router;
	}

	/**
	 * Before DefaultLogoutListener (64), which redirects to logout.target of security.yml.
	 */
	public static function getSubscribedEvents(): array {
		return array(
			LogoutEvent::class => array( 'onLogout', 65 ),
		);
	}

	/////

	public function onLogout(LogoutEvent $logoutEvent): void {
		$request = $logoutEvent->getRequest();
		if ($request->headers->get('referer')) {
			$url = $request->headers->get('referer');
		} else {
			$url = $this->router->generate('core_welcome');
		}
		$logoutEvent->setResponse(new RedirectResponse($url));
	}

}