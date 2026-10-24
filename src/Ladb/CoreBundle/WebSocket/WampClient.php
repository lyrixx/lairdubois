<?php

namespace Ladb\CoreBundle\WebSocket;

use Gos\Component\WebSocketClient\Wamp\Client;

/**
 * The WAMP client of Gos only reads the first line of the answer to the protocol upgrade, then
 * takes the headers for the welcome message of the server: a warning since PHP 8, an exception in
 * debug. To remove with Gos WebSocket (#21).
 */
class WampClient extends Client {

	protected function upgradeProtocol($target) {
		$response = parent::upgradeProtocol($target);

		// Skip the headers, up to the empty line
		do {
			$line = fgets($this->socket);
		} while (false !== $line && "\r\n" !== $line);

		return $response;
	}

}
