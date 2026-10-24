<?php

namespace Ladb\CoreBundle\WebSocket;

use Ratchet\Session\Serialize\PhpHandler;

/**
 * PhpHandler passes each value of the session to unserialize() with the rest of the session after
 * it. Since PHP 8.3, unserialize() warns about this extra data, an exception in debug: the
 * websocket server would see every account as anonymous. To remove with Gos WebSocket (#21).
 */
class SessionSerializer extends PhpHandler {

	public function unserialize($raw) {
		// On a valid session, the only warning is "Extra data starting at offset …": the values are read as before PHP 8.3
		return @parent::unserialize($raw);
	}

}
