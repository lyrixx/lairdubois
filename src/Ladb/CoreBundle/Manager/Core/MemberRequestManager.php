<?php

namespace Ladb\CoreBundle\Manager\Core;

use Ladb\CoreBundle\Entity\Core\MemberRequest;
use Ladb\CoreBundle\Manager\AbstractManager;
use Ladb\CoreBundle\Utils\ActivityUtils;

class MemberRequestManager extends AbstractManager {

	const NAME = 'ladb_core.core_member_request_manager';

	private $activityUtils;

	public function __construct(ActivityUtils $activityUtils) {
		$this->activityUtils = $activityUtils;
	}

	public function create(\Ladb\CoreBundle\Entity\Core\User $team, \Ladb\CoreBundle\Entity\Core\User $sender, $flush = true) {
		$om = $this->doctrine->getManager();

		// Create the request
		$request = new MemberRequest();
		$request->setTeam($team);
		$request->setSender($sender);

		$om->persist($request);

		// Update counters

		$team->getMeta()->incrementRequestCount();
		$sender->getMeta()->incrementRequestCount();

		// Create activity
		$this->activityUtils->createRequestActivity($request, false);

		if ($flush) {
			$om->flush();
		}

		return $request;
	}

	public function delete(MemberRequest $request, $flush = true) {

		// Delete request activities
		$this->activityUtils->deleteActivitiesByRequest($request);

		// Update counters

		$request->getTeam()->getMeta()->incrementRequestCount(-1);
		$request->getSender()->getMeta()->incrementRequestCount(-1);

		parent::deleteEntity($request, $flush);
	}

}