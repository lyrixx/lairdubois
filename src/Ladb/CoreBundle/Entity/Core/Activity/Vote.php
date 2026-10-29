<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_core_activity_vote')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\VoteRepository::class)]
class Vote extends AbstractActivity {

	const CLASS_NAME = self::class;
	const STRIPPED_NAME = 'vote';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Vote::class)]
	private $vote;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Vote /////

	public function setVote(\Ladb\CoreBundle\Entity\Core\Vote $vote) {
		$this->vote = $vote;
		return $this;
	}

	public function getVote() {
		return $this->vote;
	}

}