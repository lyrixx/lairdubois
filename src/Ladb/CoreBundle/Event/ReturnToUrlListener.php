<?php

namespace Ladb\CoreBundle\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Without "rtu" (return to URL), the actions that read it redirect to the referer. An empty one, that
 * the site never sends (forged URL), is removed: they would redirect to an empty URL (500).
 */
class ReturnToUrlListener implements EventSubscriberInterface {

	public static function getSubscribedEvents() {
		return array(
			KernelEvents::REQUEST => 'onKernelRequest',
		);
	}

	/////

	public function onKernelRequest(GetResponseEvent $event) {
		$request = $event->getRequest();
		if ($request->query->get('rtu') === '') {
			$request->query->remove('rtu');
		}
		if ($request->request->get('rtu') === '') {
			$request->request->remove('rtu');
		}
	}

}
