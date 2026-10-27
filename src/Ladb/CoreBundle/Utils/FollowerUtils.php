<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Entity\Core\Follower;
use Ladb\CoreBundle\Model\TypableInterface;
use Ladb\CoreBundle\Model\TitledInterface;
use Ladb\CoreBundle\Entity\Core\User;

class FollowerUtils {

	const NAME = 'ladb_core.follower_utils';

	private $activityUtils;
	private $doctrine;

	public function __construct(ActivityUtils $activityUtils, ManagerRegistry $doctrine) {
		$this->activityUtils = $activityUtils;
		$this->doctrine = $doctrine;
	}

	public function deleteFollowersByUser(User $user, $flush = true) {
		$om = $this->doctrine->getManager();
		$followerRepository = $om->getRepository(Follower::CLASS_NAME);

		$followers = $followerRepository->findByUser($user);
		foreach ($followers as $follower) {
			$follower->getUser()->getMeta()->incrementFollowingCount(-1);
			$follower->getFollowingUser()->getMeta()->incrementFollowerCount(-1);
			$this->activityUtils->deleteActivitiesByFollower($follower);
			$om->remove($follower);
		}
		if ($flush) {
			$om->flush();
		}
	}

	public function deleteFollowingsByUser(User $user, $flush = true) {
		$om = $this->doctrine->getManager();
		$followerRepository = $om->getRepository(Follower::CLASS_NAME);

		$followers = $followerRepository->findByFollowingUser($user);
		foreach ($followers as $follower) {
			$follower->getUser()->getMeta()->incrementFollowingCount(-1);
			$follower->getFollowingUser()->getMeta()->incrementFollowerCount(-1);
			$this->activityUtils->deleteActivitiesByFollower($follower);
			$om->remove($follower);
		}
		if ($flush) {
			$om->flush();
		}
	}

	public function deleteByFollowingUserAndUser(User $followingUser, User $user, $flush = true) {
		$om = $this->doctrine->getManager();
		$followerRepository = $om->getRepository(Follower::CLASS_NAME);

		$follower = $followerRepository->findOneByFollowingUserIdAndUser($followingUser->getId(), $user);
		if (!is_null($follower)) {
			$follower->getUser()->getMeta()->incrementFollowingCount(-1);
			$follower->getFollowingUser()->getMeta()->incrementFollowerCount(-1);
			$this->activityUtils->deleteActivitiesByFollower($follower);
			$om->remove($follower);
		}
		if ($flush) {
			$om->flush();
		}
	}

	/////

	public function getFollowerContext(User $followingUser, User $followerUser = null) {
		$om = $this->doctrine->getManager();
		if (!is_null($followerUser) && $followingUser->getId() == $followerUser->getId()) {
			return null;
		}

		$followerRepository = $om->getRepository(Follower::CLASS_NAME);
		$follower = null;
		if (!is_null($followerUser)) {
			$follower = $followerRepository->findOneByFollowingUserIdAndUser($followingUser->getId(), $followerUser);
		}

		return array(
			'followingUser' => $followingUser,
			'id'            => is_null($follower) ? null : $follower->getId(),
		);
	}

}