<?php

namespace Ladb\CoreBundle\Entity\Knowledge\Value;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_knowledge2_value_decimal')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Knowledge\Value\DecimalRepository::class)]
class Decimal extends BaseValue {

	const CLASS_NAME = self::class;
	const TYPE = 27;

	const TYPE_STRIPPED_NAME = 'decimal';

	#[Assert\NotNull(groups: ['mandatory'])]
	#[Assert\Type(type: 'numeric')]
	#[Assert\Range(min: 0)]
	#[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
	protected $data;

	/////

	// Type /////

	public function getType() {
		return self::TYPE;
	}

}