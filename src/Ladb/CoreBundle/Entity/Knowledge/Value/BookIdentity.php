<?php

namespace Ladb\CoreBundle\Entity\Knowledge\Value;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;

#[LadbAssert\ValidBookIdentityValue]
#[ORM\Table('tbl_knowledge2_value_book_identity')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Knowledge\Value\BookIdentityRepository::class)]
class BookIdentity extends BaseValue {

	const CLASS_NAME = 'LadbCoreBundle:Knowledge\Value\BookIdentity';
	const TYPE = 25;

	const TYPE_STRIPPED_NAME = 'book-identity';

	#[ORM\Column(type: 'string', length: 200)]
	protected $data;

	#[Assert\NotBlank(groups: ['mandatory'])]
	#[Assert\Length(max: 100)]
	#[Assert\Regex("/^[ a-zA-Z0-9ÀÁÂÃÄÅàáâãäåÒÓÔÕÖØòóôõöøÈÉÊËèéêëÇçÌÍÎÏìíîïÙÚÛÜùúûüÿÑñ'’’#,.:«»&+-]+\$/")]
	#[ORM\Column(type: 'string', length: 100)]
	private $work;

	#[ORM\Column(type: 'boolean', name: 'is_volume')]
	private $isVolume = false;

	#[Assert\Length(max: 100)]
	#[Assert\Regex("/^[ a-zA-Z0-9ÀÁÂÃÄÅàáâãäåÒÓÔÕÖØòóôõöøÈÉÊËèéêëÇçÌÍÎÏìíîïÙÚÛÜùúûüÿÑñ'’’#,.:«»&+-]+\$/")]
	#[ORM\Column(type: 'string', length: 100, nullable: true)]
	private $volume;

	/////

	// Type /////

	public function getType() {
		return self::TYPE;
	}

	// Title /////

	public function getTitle() {
		return $this->getData();
	}

	// Work /////

	public function setWork($work) {
		$this->work = $work;
		return $this;
	}

	public function getWork() {
		return $this->work;
	}

	// IsVolume /////

	public function setIsVolume($isVolume) {
		$this->isVolume = $isVolume;
		return $this;
	}

	public function getIsVolume() {
		return $this->isVolume;
	}

	// Volume /////

	public function setVolume($volume) {
		$this->volume = $volume;
		return $this;
	}

	public function getVolume() {
		return $this->volume;
	}

}