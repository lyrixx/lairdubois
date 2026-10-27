<?php

namespace Ladb\CoreBundle\Manager\Core;

use Ladb\CoreBundle\Entity\Core\MemberInvitation;
use Ladb\CoreBundle\Manager\AbstractManager;
use Ladb\CoreBundle\Utils\ActivityUtils;

class MemberInvitationManager extends AbstractManager {

	const NAME = 'ladb_core.core_member_invitation_manager';

	private $activityUtils;

	public function __construct(ActivityUtils $activityUtils) {
		$this->activityUtils = $activityUtils;
	}

	public function create(\Ladb\CoreBundle\Entity\Core\User $team, \Ladb\CoreBundle\Entity\Core\User $sender, \Ladb\CoreBundle\Entity\Core\User $recipient, $flush = true) {
		$om = $this->doctrine->getManager();

		// Create the invitation
		$invitation = new MemberInvitation();
		$invitation->setTeam($team);
		$invitation->setSender($sender);
		$invitation->setRecipient($recipient);

		$om->persist($invitation);

		// Update counters

		$team->getMeta()->incrementInvitationCount();
		$recipient->getMeta()->incrementInvitationCount();

		// Create activity
		$this->activityUtils->createInviteActivity($invitation, false);

		if ($flush) {
			$om->flush();
		}

		return $invitation;
	}

	public function delete(MemberInvitation $invitation, $flush = true) {

		// Delete invitation activities
		$this->activityUtils->deleteActivitiesByInvitation($invitation);

		// Update counters

		$invitation->getTeam()->getMeta()->incrementInvitationCount(-1);
		$invitation->getRecipient()->getMeta()->incrementInvitationCount(-1);

		parent::deleteEntity($invitation, $flush);
	}

}