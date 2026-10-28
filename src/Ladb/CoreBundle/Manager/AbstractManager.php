<?php

namespace Ladb\CoreBundle\Manager;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractManager {

	protected $doctrine;

	#[Required]
	public function setDoctrine(ManagerRegistry $doctrine) {
		$this->doctrine = $doctrine;
	}

	/////

	public function deleteEntity($entity, $flush = true) {
		$om = $this->doctrine->getManager();

		$om->remove($entity);

		if ($flush) {
			$om->flush();
		}

	}

}