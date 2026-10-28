<?php

namespace Ladb\CoreBundle\Controller\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractWorkflowBasedController extends AbstractController {

	const TASKINFO_NONE 			= 0b0000000;
	const TASKINFO_STATUS 			= 0b0000001;
	const TASKINFO_POSITION_LEFT 	= 0b0000010;
	const TASKINFO_POSITION_TOP 	= 0b0000100;
	const TASKINFO_SORT_INDEX	 	= 0b0001000;
	const TASKINFO_ROW 				= 0b0010000;
	const TASKINFO_WIDGET 			= 0b0100000;
	const TASKINFO_BOX 				= 0b1000000;

	// The topics of a workflow on the Mercure hub: fixed IRIs, whatever the host of the site. Its
	// workspaces subscribe to the first one. The second one, by owner, is for the subscription
	// cookie: its claim allows all the workflows of the account, so that two workspaces of
	// different workflows do not overwrite each other's cookie.
	private const MERCURE_TOPIC = 'https://lairdubois.fr/workflows/{id}';
	private const MERCURE_OWNER_TOPIC = 'https://lairdubois.fr/users/{userId}/workflows/{id}';

	private $hub;
	// Protected, like the one of UserControllerTrait, that a subclass can use
	protected $om;

	#[Required]
	public function setHub(HubInterface $hub) {
		$this->hub = $hub;
	}

	#[Required]
	public function setOm(EntityManagerInterface $om) {
		$this->om = $om;
	}

	/////

	protected function _retrieveWorkflow($id) {
		$workshopRepository = $this->om->getRepository(Workflow::CLASS_NAME);

		$id = intval($id);

		$workflow = $workshopRepository->findOneById($id);
		if (is_null($workflow)) {
			throw $this->createNotFoundException('Unable to find Workflow entity (id='.$id.').');
		}

		return $workflow;
	}

	protected function _assertAuthorizedWorkflow(Workflow $workflow) {
		if (!$this->isGranted('ROLE_ADMIN') && $workflow->getUser() != $this->getUser()) {
			throw $this->createNotFoundException('Not allowed');
		}
	}

	protected function _generateTaskInfos($tasks = array(), $fieldsStrategy = self::TASKINFO_NONE, $readOnly = false, $durationsHidden = false) {
		if (!is_array($tasks) && !$tasks instanceof \Traversable) {
			$tasks = array( $tasks );
		}
		$taskInfos = array();
		foreach ($tasks as $task) {
			$taskInfo = array(
				'id' => $task->getId(),
			);
			if (($fieldsStrategy & self::TASKINFO_STATUS) == self::TASKINFO_STATUS) {
				$taskInfo['status'] = $task->getStatus();
			}
			if (($fieldsStrategy & self::TASKINFO_POSITION_LEFT) == self::TASKINFO_POSITION_LEFT) {
				$taskInfo['positionLeft'] = $task->getPositionLeft();
			}
			if (($fieldsStrategy & self::TASKINFO_POSITION_TOP) == self::TASKINFO_POSITION_TOP) {
				$taskInfo['positionTop'] = $task->getPositionTop();
			}
			if (($fieldsStrategy & self::TASKINFO_SORT_INDEX) == self::TASKINFO_SORT_INDEX) {
				$taskInfo['sortIndex'] = $task->getPositionTop();
			}
			if (($fieldsStrategy & self::TASKINFO_ROW) == self::TASKINFO_ROW) {
				$taskInfo['row'] = $this->renderView('@LadbCore/Workflow/Task/_row.part.html.twig', array( 'task' => $task, 'readOnly' => $readOnly, 'durationsHidden' => $durationsHidden ));
			}
			if (($fieldsStrategy & self::TASKINFO_WIDGET) == self::TASKINFO_WIDGET) {
				$taskInfo['widget'] = $this->renderView('@LadbCore/Workflow/Task/_widget.part.html.twig', array( 'task' => $task, 'readOnly' => $readOnly, 'durationsHidden' => $durationsHidden ));
			}
			if (($fieldsStrategy & self::TASKINFO_BOX) == self::TASKINFO_BOX) {
				$taskInfo['box'] = $this->renderView('@LadbCore/Workflow/Task/_box.part.html.twig', array( 'task' => $task, 'readOnly' => $readOnly, 'durationsHidden' => $durationsHidden ));
			}
			$taskInfos[] = $taskInfo;
		}
		return $taskInfos;
	}

	protected function _generateWorkflowInfos($workflow) {
		return array(
			'statusPanel' => $this->renderView('@LadbCore/Workflow/Workflow/_workflow-status.part.html.twig', array( 'workflow' => $workflow )),
		);
	}

	protected function _push($workflow, $response) {
		$topics = array(
			$this->_getMercureTopic($workflow),
			strtr(self::MERCURE_OWNER_TOPIC, array( '{userId}' => $workflow->getUser()->getId(), '{id}' => $workflow->getId() )),
		);
		$this->hub->publish(new Update($topics, json_encode($response), true));
	}

	protected function _getMercureTopic(Workflow $workflow) {
		return strtr(self::MERCURE_TOPIC, array( '{id}' => $workflow->getId() ));
	}

	/**
	 * The topics the subscription cookie allows, as a URI template: the workflows of the current
	 * user, all of them for an admin.
	 */
	protected function _getMercureSubscribeTopic() {
		if ($this->isGranted('ROLE_ADMIN')) {
			return self::MERCURE_TOPIC;
		}
		return strtr(self::MERCURE_OWNER_TOPIC, array( '{userId}' => $this->getUser()->getId() ));
	}

}