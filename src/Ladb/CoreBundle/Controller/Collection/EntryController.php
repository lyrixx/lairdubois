<?php

namespace Ladb\CoreBundle\Controller\Collection;

use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Entity\Collection\Entry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @Route("/collections")
 */
class EntryController extends AbstractCollectionBasedController {

	/////

	/**
	 * @Route("/{id}/entry/{entityType}/{entityId}/create", requirements={"id" = "\d+", "entityType" = "\d+", "entityId" = "\d+"}, name="core_collection_entry_create")
	 * @Template("LadbCoreBundle:Collection/Entry:create-xhr.html.twig")
	 */
	public function createAction($id, $entityType, $entityId, CollectionnableUtils $collectionnableUtils) {

		$this->createLock('core_collection_entry_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		// Retrieve collection
		$collection = $this->_retrieveCollection($id);

		if (!$this->isGranted('ROLE_ADMIN') && $collection->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_collection_entry_create)');
		}

		// Retrieve related entity
		$entity = $this->_retrieveRelatedEntity($entityType, $entityId);

		// Create entry
		try {
			$collectionnableUtils->createEntry($entity, $collection);
		} catch (\Exception $e) {
			throw $this->createNotFoundException($e->getMessage());
		}

		return array(
			'entryContext' => $collectionnableUtils->getEntryContext($collection, $entity),
		);
	}

	/**
	 * @Route("/{id}/entry/{entityType}/{entityId}/delete", requirements={"id" = "\d+", "entityType" = "\d+", "entityId" = "\d+"}, name="core_collection_entry_delete")
	 * @Template("LadbCoreBundle:Collection/Entry:delete-xhr.html.twig")
	 */
	public function deleteAction($id, $entityType, $entityId, CollectionnableUtils $collectionnableUtils, EventDispatcherInterface $dispatcher) {
		$om = $this->getDoctrine()->getManager();
		$entryRepository = $om->getRepository(Entry::CLASS_NAME);

		// Retrieve collection
		$collection = $this->_retrieveCollection($id);

		if (!$this->isGranted('ROLE_ADMIN') && $collection->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_collection_entry_create)');
		}

		$entry = $entryRepository->findOneByEntityTypeAndEntityIdAndCollection($entityType, $entityId, $collection);
		if (is_null($entry)) {
			throw $this->createNotFoundException('Unable to find Collection Entry entity (collectionId='.$id.', entityType='.$entityType.', entityId='.$entityId.').');
		}

		// Retrieve related entity
		$entity = $this->_retrieveRelatedEntity($entry->getEntityType(), $entry->getEntityId());

		// Delete entry
		$collectionnableUtils->deleteEntry($entry, $entity, $om, true);

		// Dispatch publication event
		$dispatcher->dispatch(PublicationListener::PUBLICATION_CHANGED, new PublicationEvent($collection));

		return array(
			'entryContext' => $collectionnableUtils->getEntryContext($collection, $entity),
		);
	}

}
