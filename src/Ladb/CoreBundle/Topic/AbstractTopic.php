<?php

namespace Ladb\CoreBundle\Topic;

use Gos\Bundle\WebSocketBundle\Client\ClientManipulatorInterface;
use Gos\Bundle\WebSocketBundle\Router\WampRequest;
use Gos\Bundle\WebSocketBundle\Topic\TopicInterface;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\Topic;

abstract class AbstractTopic implements TopicInterface {

	protected $clientManipulator;

	/**
	 * @required
	 */
	public function setClientManipulator(ClientManipulatorInterface $clientManipulator) {
		$this->clientManipulator = $clientManipulator;
	}

	protected function getUserByConnection(ConnectionInterface $connection) {
		return $this->clientManipulator->getClient($connection);
	}

}