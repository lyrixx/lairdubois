<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_core_activity_follow')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\FollowRepository::class)]
class Follow extends AbstractActivity {

	const CLASS_NAME = self::class;
	const STRIPPED_NAME = 'follow';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Follower::class)]
	private $follower;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Follower /////

	public function setFollower(\Ladb\CoreBundle\Entity\Core\Follower $follower) {
		$this->follower = $follower;
		return $this;
	}

	public function getFollower() {
		return $this->follower;
	}

}