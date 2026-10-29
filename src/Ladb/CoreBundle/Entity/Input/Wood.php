<?php

namespace Ladb\CoreBundle\Entity\Input;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table('tbl_input_wood')]
#[ORM\Entity(repositoryClass: \Ladb\CoreBundle\Repository\Input\WoodRepository::class)]
class Wood extends AbstractInput {

	const CLASS_NAME = self::class;

}