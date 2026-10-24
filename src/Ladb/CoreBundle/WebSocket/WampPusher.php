<?php

namespace Ladb\CoreBundle\WebSocket;

use Gos\Bundle\WebSocketBundle\Pusher\Wamp\WampPusher as BaseWampPusher;

/**
 * The WAMP pusher of Gos, with WampClient. To remove with Gos WebSocket (#21).
 */
class WampPusher extends BaseWampPusher {

	protected function doPush($data, array $context) {
		if (false === $this->isConnected()) {
			// @phpstan-ignore method.deprecated (the configuration of the pusher, read as the WampPusher of Gos 1.10 does)
			$config = $this->getConfig();

			$this->connection = new WampClient($config['host'], $config['port'], $config['ssl'], $config['origin']);
			$this->connection->connect('/');
			$this->setConnected();
		}

		return parent::doPush($data, $context);
	}

}
