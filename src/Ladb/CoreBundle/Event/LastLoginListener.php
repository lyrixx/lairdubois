<?php

namespace Ladb\CoreBundle\Event;

use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * The last login of the user at each interactive login (login form, remember me), as the
 * LastLoginListener of FOSUserBundle (#24). LoginUtils sets it itself.
 */
final class LastLoginListener implements EventSubscriberInterface {

	private $userManager;

	public function __construct(UserManager $userManager) {
		$this->userManager = $userManager;
	}

	public static function getSubscribedEvents() {
		return array(
			SecurityEvents::INTERACTIVE_LOGIN => 'onSecurityInteractiveLogin',
		);
	}

	/////

	public function onSecurityInteractiveLogin(InteractiveLoginEvent $event) {
		$user = $event->getAuthenticationToken()->getUser();
		if ($user instanceof User) {
			$user->setLastLogin(new \DateTime());
			$this->userManager->updateUser($user);
		}
	}

}
