<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_core_activity_write')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\WriteRepository::class)]
class Write extends AbstractActivity {

	const CLASS_NAME = self::class;
	const STRIPPED_NAME = 'write';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Message\Message::class)]
	private $message;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Message /////

	public function setMessage(\Ladb\CoreBundle\Entity\Message\Message $message) {
		$this->message = $message;
		return $this;
	}

	public function getMessage() {
		return $this->message;
	}

}