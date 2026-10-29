<?php

namespace Ladb\CoreBundle\Entity\Core;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;
use Ladb\CoreBundle\Entity\AbstractPublication;
use Ladb\CoreBundle\Model\HtmlBodiedInterface;
use Ladb\CoreBundle\Model\HtmlBodiedTrait;
use Ladb\CoreBundle\Model\IndexableTrait;
use Ladb\CoreBundle\Model\ViewableTrait;
use Ladb\CoreBundle\Model\IndexableInterface;
use Ladb\CoreBundle\Model\TitledInterface;
use Ladb\CoreBundle\Model\ViewableInterface;

#[LadbAssert\BodyBlocks]
#[ORM\Table('tbl_core_tip')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Core\TipRepository::class)]
class Tip extends AbstractPublication implements TitledInterface, HtmlBodiedInterface, IndexableInterface, ViewableInterface {

	use HtmlBodiedTrait;
	use IndexableTrait, ViewableTrait;

	const CLASS_NAME = self::class;
	const TYPE = 5;

	#[ORM\Column(type: 'text', nullable: false)]
	private $body;

	#[ORM\Column(type: 'text', nullable: false)]
	private $htmlBody;

	#[Assert\NotBlank]
	#[Assert\Url]
	#[ORM\Column(type: 'string', length: 255, nullable: false)]
	private $url;

	#[ORM\Column(type: 'integer', name: 'view_count')]
	private $viewCount = 0;

	/////

	// Type /////

	public function getType() {
		return Tip::TYPE;
	}

	// Title /////

	public function setTitle($title) {
	}

	public function getTitle() {
		return 'Le saviez-vous n°'.$this->getId();
	}

	// Url /////

	public function getUrl() {
		return $this->url;
	}

	public function setUrl($url) {
		$this->url = $url;
		return $this;
	}

}