<?php

namespace Ladb\CoreBundle\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Crypto\DkimSigner;

/**
 * Signs each email with DKIM when the transport sends it, not when Mailer puts it on the message bus: the queued
 * message stays an Email, that the tests and the profiler read. Symfony 7.3 has its own listener
 * (framework.mailer.dkim_signer), which signs the queued message too.
 */
final class DkimSignedMessageListener implements EventSubscriberInterface {

	private $signer;

	public function __construct(DkimSigner $signer) {
		$this->signer = $signer;
	}

	public static function getSubscribedEvents(): array {
		return array(
			// As the one of Symfony 7.3: after the listeners that change the message, before MessageLoggerListener (-255)
			MessageEvent::class => array( 'onMessage', -128 ),
		);
	}

	/////

	public function onMessage(MessageEvent $event): void {
		if ($event->isQueued()) {
			return;
		}
		$event->setMessage($this->signer->sign($event->getMessage()));
	}

}
