<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_core_activity_like')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\LikeRepository::class)]
class Like extends AbstractActivity {

	const CLASS_NAME = self::class;
	const STRIPPED_NAME = 'like';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Like::class)]
	private $like;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Like /////

	public function setLike(\Ladb\CoreBundle\Entity\Core\Like $like) {
		$this->like = $like;
		return $this;
	}

	public function getLike() {
		return $this->like;
	}

}