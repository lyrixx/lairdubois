<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_core_activity_join')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\JoinRepository::class)]
class Join extends AbstractActivity {

	const CLASS_NAME = self::class;
	const STRIPPED_NAME = 'join';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Join::class)]
	private $join;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Join /////

	public function setJoin(\Ladb\CoreBundle\Entity\Core\Join $join) {
		$this->join = $join;
		return $this;
	}

	public function getJoin() {
		return $this->join;
	}

}