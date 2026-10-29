<?php

namespace Ladb\CoreBundle\Entity\Knowledge\Value;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Intl\Intl;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table('tbl_knowledge2_value_isbn')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Knowledge\Value\IsbnRepository::class)]
class Isbn extends BaseValue {

	const CLASS_NAME = self::class;
	const TYPE = 19;

	const TYPE_STRIPPED_NAME = 'isbn';

	#[ORM\Column(type: 'string')]
	protected $data;

	#[Assert\NotBlank(groups: ['mandatory'])]
	#[Assert\Isbn]
	#[Assert\Length(max: 20)]
	#[ORM\Column(type: 'string', length: 20)]
	protected $rawIsbn;

	/////

	// Type /////

	public function getType() {
		return self::TYPE;
	}

	// RawIsbn /////

	public function getRawIsbn() {
		return $this->rawIsbn;
	}

	public function setRawIsbn($rawIsbn) {
		$this->rawIsbn = $rawIsbn;
		return $this;
	}

}