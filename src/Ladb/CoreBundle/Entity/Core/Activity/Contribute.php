<?php

namespace Ladb\CoreBundle\Entity\Core\Activity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_core_activity_contribute')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\Activity\ContributeRepository::class)]
class Contribute extends AbstractActivity {

	const CLASS_NAME = self::class;
	const STRIPPED_NAME = 'contribute';

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue::class, cascade: ['persist'])]
	private $value;

	/////

	// StrippedName /////

	public function getStrippedName() {
		return self::STRIPPED_NAME;
	}

	// Value /////

	public function setValue(\Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue $value) {
		$this->value = $value;
	}

	public function getValue() {
		return $this->value;
	}

}