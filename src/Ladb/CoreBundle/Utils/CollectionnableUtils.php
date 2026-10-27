<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Entity\Collection\Entry;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\Collection\CollectionManager;
use Ladb\CoreBundle\Manager\Core\PictureManager;
use Ladb\CoreBundle\Model\CollectionnableInterface;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Model\PicturedInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class CollectionnableUtils {

	const NAME = 'ladb_core.collectionnable_utils';

	private $dispatcher;
	private $doctrine;
	private $pictureManager;

	public function __construct(EventDispatcherInterface $dispatcher, ManagerRegistry $doctrine, PictureManager $pictureManager) {
		$this->dispatcher = $dispatcher;
		$this->doctrine = $doctrine;
		$this->pictureManager = $pictureManager;
	}

	/////

	public function createEntry(CollectionnableInterface $collectionnable, Collection $collection) {
		$om = $this->doctrine->getManager();

		if ($collectionnable instanceof HiddableInterface && !$collectionnable->getIsPublic()) {
			throw new \Exception('Entity must be public (entityType='.$collectionnable->getType().', entityId='.$collectionnable->getId().')');
		}
		if ($collectionnable === $collection) {
			throw new \Exception('Entity can not be the collection itself');
		}

		// Create the new Entry
		$entry = new Entry();
		$entry->setEntityType($collectionnable->getType());
		$entry->setEntityId($collectionnable->getId());

		// Add to collection
		$collection->addEntry($entry);
		$collection->incrementEntryCount();
		$collection->incrementEntryTypeCounters($entry->getEntityType());
		$collection->setChangedAt(new \DateTime());
		$collection->setUpdatedAt(new \DateTime());
		if (is_null($collection->getMainPicture()) && $collectionnable instanceof PicturedInterface && !is_null($collectionnable->getMainPicture())) {

			// Duplicate the entyt mainPicture
			$mainPicture = $this->pictureManager->duplicate($collectionnable->getMainPicture());
			$mainPicture->setUser($collection->getUser());
			$collection->setMainPicture($mainPicture);

		}

		// Update related entity
		if ($collection->getIsPublic()) {
			$collectionnable->incrementPublicCollectionCount();
		} else {
			$collectionnable->incrementPrivateCollectionCount();
		}

		$om->persist($entry);
		$om->flush();

		// Dispatch publication event
		$this->dispatcher->dispatch(new PublicationEvent($collection), PublicationListener::PUBLICATION_CHANGED);

	}

	public function deleteEntries(CollectionnableInterface $collectionnable, $flush = true) {
		$om = $this->doctrine->getManager();
		$entryRepository = $om->getRepository(Entry::CLASS_NAME);

		$entries = $entryRepository->findByEntityTypeAndEntityId($collectionnable->getType(), $collectionnable->getId());
		foreach ($entries as $entry) {
			$this->deleteEntry($entry, $collectionnable, $om, false);
		}
		if ($flush) {
			$om->flush();
		}
	}

	public function deleteEntry(Entry $entry, CollectionnableInterface $collectionnable, $om, $flush = false) {
		$collection = $entry->getCollection();

		// Remove from collection
		$collection->removeEntry($entry);
		$collection->incrementEntryCount(-1);
		$collection->incrementEntryTypeCounters($entry->getEntityType(), -1);
		$collection->setChangedAt(new \DateTime());
		$collection->setUpdatedAt(new \DateTime());

		// Update collectionnable collection count
		if ($collection->getIsPublic()) {
			$collectionnable->incrementPublicCollectionCount(-1);
		} else {
			$collectionnable->incrementPrivateCollectionCount(-1);
		}

		// Reove entry from DB
		$om->remove($entry);

		if ($flush) {
			$om->flush();
		}
	}

	/////

	public function getCollectionContext(CollectionnableInterface $collectionnable) {
		return array(
			'entityType'        => $collectionnable->getType(),
			'entityId'          => $collectionnable->getId(),
			'isCollectionnable' => $collectionnable instanceof HiddableInterface ? $collectionnable->getIsPublic() : true,
		);
	}

	public function getEntryContext(Collection $collection, CollectionnableInterface $collectionnable) {
		$om = $this->doctrine->getManager();
		$entryRepository = $om->getRepository(Entry::CLASS_NAME);

		return array(
			'collection'      => $collection,
			'entityType'      => $collectionnable->getType(),
			'entityId'        => $collectionnable->getId(),
			'isCollectionned' => $entryRepository->existsByEntityTypeAndEntityIdAndCollection($collectionnable->getType(), $collectionnable->getId(), $collection),
		);
	}

	/////

	public function transferCollections(CollectionnableInterface $collectionnableSrc, CollectionnableInterface $collectionnableDest, $flush = true) {
		$om = $this->doctrine->getManager();
		$entryRepository = $om->getRepository(Entry::CLASS_NAME);

		// Retrieve entries
		$entries = $entryRepository->findByEntityTypeAndEntityId($collectionnableSrc->getType(), $collectionnableSrc->getId(), false);

		// Transfer entries
		foreach ($entries as $entry) {
			$entry->setEntityType($collectionnableDest->getType());
			$entry->setEntityId($collectionnableDest->getId());

			// Update collectionnable collection count
			if ($entry->getCollection()->getIsPublic()) {
				$collectionnableSrc->incrementPublicCollectionCount(-1);
				$collectionnableDest->incrementPublicCollectionCount(1);
			} else {
				$collectionnableSrc->incrementPrivateCollectionCount(-1);
				$collectionnableDest->incrementPrivateCollectionCount(1);
			}

		}

		if ($flush) {
			$om->flush();
		}
	}

}