<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table('tbl_core_activity_review')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\ReviewRepository::class)]
class Review extends AbstractActivity {

	const CLASS_NAME = 'LadbCoreBundle:Core\Activity\Review';
	const STRIPPED_NAME = 'review';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Review::class)]
	private $review;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Review /////

	public function setReview(\Ladb\CoreBundle\Entity\Core\Review $review) {
		$this->review = $review;
		return $this;
	}

	public function getReview() {
		return $this->review;
	}

}