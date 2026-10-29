<?php

namespace Ladb\CoreBundle\Entity\Core;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Ladb\CoreBundle\Model\BodiedInterface;
use Ladb\CoreBundle\Model\IdentifiableInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Model\HtmlBodiedTrait;
use Ladb\CoreBundle\Model\HtmlBodiedInterface;

#[ORM\Table('tbl_core_biography')]
#[ORM\Entity]
class Biography implements IdentifiableInterface, HtmlBodiedInterface {

	use HtmlBodiedTrait;

	const CLASS_NAME = self::class;

	#[ORM\Column(name: 'id', type: 'integer')]
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'AUTO')]
	private $id;

	#[Assert\Length(min: 2, max: 10000)]
	#[ORM\Column(type: 'text', nullable: true)]
	private $body;

	#[ORM\Column(type: 'text', nullable: true)]
	private $htmlBody;

	/////

	// Id /////

	public function getId() {
		return $this->id;
	}

}