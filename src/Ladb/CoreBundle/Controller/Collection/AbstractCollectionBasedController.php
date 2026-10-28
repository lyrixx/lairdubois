<?php

namespace Ladb\CoreBundle\Controller\Collection;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Model\CollectionnableInterface;
use Ladb\CoreBundle\Utils\TypableUtils;

abstract class AbstractCollectionBasedController extends AbstractController {

	// Protected, like the one of UserControllerTrait, that a subclass can use
	protected $om;
	private $typableUtils;

	/**
	 * @required
	 */
	public function setOm(EntityManagerInterface $om) {
		$this->om = $om;
	}

	/**
	 * @required
	 */
	public function setTypableUtils(TypableUtils $typableUtils) {
		$this->typableUtils = $typableUtils;
	}

	protected function _retrieveCollection($id) {
		$collectionRepository = $this->om->getRepository(Collection::CLASS_NAME);

		$id = intval($id);

		$collection = $collectionRepository->findOneById($id);
		if (is_null($collection)) {
			throw $this->createNotFoundException('Unable to find Collection entity (id='.$id.').');
		}

		return $collection;
	}

	protected function _retrieveRelatedEntity($entityType, $entityId) {
		try {
			$entity = $this->typableUtils->findTypable(intval($entityType), intval($entityId));
		} catch (\Exception $e) {
			throw $this->createNotFoundException($e->getMessage());
		}
		if (!($entity instanceof CollectionnableInterface)) {
			throw $this->createNotFoundException('Entity must implements CollectionnableInterface.');
		}
		return $entity;
	}

}
