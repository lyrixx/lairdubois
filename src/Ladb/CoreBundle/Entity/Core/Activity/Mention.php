<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_core_activity_mention')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\MentionRepository::class)]
class Mention extends AbstractActivity {

	const CLASS_NAME = self::class;
	const STRIPPED_NAME = 'mention';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\Mention::class)]
	private $mention;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Mention /////

	public function setMention(\Ladb\CoreBundle\Entity\Core\Mention $mention) {
		$this->mention = $mention;
		return $this;
	}

	public function getMention() {
		return $this->mention;
	}

}