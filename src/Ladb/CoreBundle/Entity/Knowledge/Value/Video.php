<?php

namespace Ladb\CoreBundle\Entity\Knowledge\Value;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;
use Ladb\CoreBundle\Utils\VideoHostingUtils;

#[LadbAssert\SupportedVideoHosting]
#[ORM\Table('tbl_knowledge2_value_video')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Knowledge\Value\VideoRepository::class)]
class Video extends BaseValue {

	const CLASS_NAME = 'LadbCoreBundle:Knowledge\Value\Video';
	const TYPE = 24;

	const TYPE_STRIPPED_NAME = 'video';

	#[Assert\NotBlank(groups: ['mandatory'])]
	#[Assert\Length(max: 255)]
	#[Assert\Url]
	#[ORM\Column(type: 'string', length: 255)]
	protected $data;

	#[ORM\Column(type: 'smallint')]
	private $kind = VideoHostingUtils::KIND_UNKNOW;

	#[ORM\Column(type: 'string', length: 255, nullable: true)]
	private $embedIdentifier;

	/////

	// Type /////

	public function getType() {
		return self::TYPE;
	}

	// Kind /////

	public function getKind() {
		return $this->kind;
	}

	public function setKind($kind) {
		$this->kind = $kind;
		return $this;
	}

	// EmbedIdentifier /////

	public function getEmbedIdentifier() {
		return $this->embedIdentifier;
	}

	public function setEmbedIdentifier($embedIdentifier) {
		$this->embedIdentifier = $embedIdentifier;
		return $this;
	}

	/////

	// IsDisplayGrid /////

	public function getIsDisplayGrid() {
		return true;
	}

}