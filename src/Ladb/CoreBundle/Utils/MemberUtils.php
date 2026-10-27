<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Model\TypableInterface;
use Ladb\CoreBundle\Model\TitledInterface;
use Ladb\CoreBundle\Entity\Core\User;

class MemberUtils {

	const NAME = 'ladb_core.member_utils';

	private $doctrine;

	public function __construct(ManagerRegistry $doctrine) {
		$this->doctrine = $doctrine;
	}

	public function deleteMembersByTeam(User $team, $flush = true) {
		$om = $this->doctrine->getManager();
		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		$members = $memberRepository->findByUser($team);
		foreach ($members as $member) {
			$member->getUser()->getMeta()->incrementTeamCount(-1);
			$member->getTeam()->getMeta()->incrementMemberCount(-1);
			$om->remove($member);
		}
		if ($flush) {
			$om->flush();
		}
	}

	public function deleteTeamsByUser(User $user, $flush = true) {
		$om = $this->doctrine->getManager();
		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		$members = $memberRepository->findByTeam($user);
		foreach ($members as $member) {
			$member->getUser()->getMeta()->incrementTeamCount(-1);
			$member->getTeam()->incrementMemberCount(-1);
			$om->remove($member);
		}
		if ($flush) {
			$om->flush();
		}
	}

}