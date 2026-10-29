<?php

namespace Ladb\CoreBundle\Entity\Knowledge\Value;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;

#[LadbAssert\ValidSoftwareIdentityValue]
#[ORM\Table('tbl_knowledge2_value_software_identity')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Knowledge\Value\SoftwareIdentityRepository::class)]
class SoftwareIdentity extends BaseValue {

	const CLASS_NAME = self::class;
	const TYPE = 21;

	const TYPE_STRIPPED_NAME = 'software-identity';

	#[ORM\Column(type: 'string', length: 100)]
	protected $data;

	#[Assert\NotBlank(groups: ['mandatory'])]
	#[Assert\Length(max: 100)]
	#[Assert\Regex("/^[ a-zA-Z0-9ÀÁÂÃÄÅàáâãäåÒÓÔÕÖØòóôõöøÈÉÊËèéêëÇçÌÍÎÏìíîïÙÚÛÜùúûüÿÑñ\\-.'’’&]+\$/")]
	#[ORM\Column(type: 'string', length: 100)]
	private $name;

	#[ORM\Column(type: 'boolean', name: 'is_addon')]
	private $isAddOn = false;

	#[Assert\Length(max: 100)]
	#[Assert\Regex("/^[ a-zA-Z0-9ÀÁÂÃÄÅàáâãäåÒÓÔÕÖØòóôõöøÈÉÊËèéêëÇçÌÍÎÏìíîïÙÚÛÜùúûüÿÑñ\\-.'’’&]+\$/")]
	#[ORM\Column(type: 'string', length: 100, nullable: true, name: 'host_software_name')]
	private $hostSoftwareName;

	/////

	// Type /////

	public function getType() {
		return self::TYPE;
	}

	// Title /////

	public function getTitle() {
		return $this->getData();
	}

	// Name /////

	public function setName($name) {
		$this->name = $name;
		return $this;
	}

	public function getName() {
		return $this->name;
	}

	// IsAddOn /////

	public function setIsAddOn($isAddOn) {
		$this->isAddOn = $isAddOn;
		return $this;
	}

	public function getIsAddOn() {
		return $this->isAddOn;
	}

	// HostSoftwareName /////

	public function setHostSoftwareName($hostSoftwareName) {
		$this->hostSoftwareName = $hostSoftwareName;
		return $this;
	}

	public function getHostSoftwareName() {
		return $this->hostSoftwareName;
	}

}