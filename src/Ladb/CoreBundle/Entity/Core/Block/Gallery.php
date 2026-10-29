<?php

namespace Ladb\CoreBundle\Entity\Core\Block;

use Doctrine\ORM\Mapping as ORM;
use Ladb\CoreBundle\Model\MultiPicturedTrait;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Model\MultiPicturedInterface;

#[ORM\Table('tbl_core_block_gallery')]
#[ORM\Entity]
class Gallery extends AbstractBlock implements MultiPicturedInterface {

	use MultiPicturedTrait;

	#[Assert\Count(min: 1, max: 20)]
	#[ORM\JoinTable(name: 'tbl_core_block_gallery_picture')]
	#[ORM\ManyToMany(targetEntity: \Ladb\CoreBundle\Entity\Core\Picture::class, cascade: ['persist'], fetch: 'EAGER')]
	#[ORM\OrderBy(['sortIndex' => 'ASC'])]
	private $pictures;

	/////

	public function __construct() {
		$this->pictures = new \Doctrine\Common\Collections\ArrayCollection();
	}

	// StrippedName /////

	public function getStrippedName() {
		return 'gallery';
	}

}