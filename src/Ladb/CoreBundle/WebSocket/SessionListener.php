<?php

namespace Ladb\CoreBundle\WebSocket;

use Gos\Bundle\WebSocketBundle\Event\ClientEvent;
use Ratchet\Session\Storage\VirtualSessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Ratchet gives each connection a session read with its PhpHandler, and Gos does not let us pass
 * another serializer: replaces it with the same session read with SessionSerializer, before Gos
 * reads the account from it. To remove with Gos WebSocket (#21).
 */
class SessionListener {

	private $sessionHandler;

	public function __construct(\SessionHandlerInterface $sessionHandler) {
		$this->sessionHandler = $sessionHandler;
	}

	/////

	public function onClientConnect(ClientEvent $event) {
		$connection = $event->getConnection();

		$sessionId = $connection->Session->getId();
		if ('' === $sessionId) {
			return;	// No session cookie: Ratchet reads nothing
		}

		$connection->Session = new Session(new VirtualSessionStorage($this->sessionHandler, $sessionId, new SessionSerializer()));
	}

}
