<?php

namespace Ladb\CoreBundle\Topic;

use Doctrine\Persistence\ManagerRegistry;
use Gos\Bundle\WebSocketBundle\Router\WampRequest;
use Ladb\CoreBundle\Entity\User;
use Psr\Log\LoggerInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\Topic;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class WorkflowTopic extends AbstractTopic {

	private $authorizationChecker;
	private $doctrine;
	private $logger;

	public function __construct(AuthorizationCheckerInterface $authorizationChecker, ManagerRegistry $doctrine, LoggerInterface $logger) {
		$this->authorizationChecker = $authorizationChecker;
		$this->doctrine = $doctrine;
		$this->logger = $logger;
	}

	/////

	private function _retrieveWorkflow(ConnectionInterface $connection, WampRequest $request, $user) {
		$om = $this->doctrine->getManager();
		$workflowRepository = $om->getRepository(Workflow::CLASS_NAME);

		$id = intval($request->getAttributes()->get('id'));

		$workflow = $workflowRepository->findOneById($id);
		if (is_null($workflow)) {
			$this->logger->error('Unable to find Workflow entity (id='.$id.').');
			$connection->close();
			return null;
		}
		if (!$user instanceof UserInterface || !$this->authorizationChecker->isGranted('ROLE_ADMIN') && $workflow->getUser() != $user) {
			$this->logger->error('Not allowed');
			$connection->close();
			return null;
		}

		return $workflow;
	}

	/////

	/**
	 * This will receive any Subscription requests for this topic.
	 *
	 * @param ConnectionInterface $connection
	 * @param Topic $topic
	 * @param WampRequest $request
	 * @return void
	 */
	public function onSubscribe(ConnectionInterface $connection, Topic $topic, WampRequest $request) {

		// Retrieve user
		$user = $this->getUserByConnection($connection);

		// Retieve and check workflow
		$workflow = $this->_retrieveWorkflow($connection, $request, $user);

	}

	/**
	 * This will receive any UnSubscription requests for this topic.
	 *
	 * @param ConnectionInterface $connection
	 * @param Topic $topic
	 * @param WampRequest $request
	 * @return void
	 */
	public function onUnSubscribe(ConnectionInterface $connection, Topic $topic, WampRequest $request) {
		//this will broadcast the message to ALL subscribers of this topic.
		//$topic->broadcast(['data' => $connection->resourceId." has left ".$topic->getId()]);
	}

	/**
	 * This will receive any Publish requests for this topic.
	 *
	 * @param ConnectionInterface $connection
	 * @param Topic $topic
	 * @param WampRequest $request
	 * @param $event
	 * @param array $exclude
	 * @param array $eligible
	 * @return mixed|void
	 */
	public function onPublish(ConnectionInterface $connection, Topic $topic, WampRequest $request, $event, array $exclude, array $eligible) {
		$topic->broadcast($event);
	}

	/**
	 * Like RPC is will use to prefix the channel
	 * @return string
	 */
	public function getName() {
		return 'workflow.topic';
	}

}