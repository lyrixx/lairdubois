<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\TypableUtils;
use Ladb\CoreBundle\Model\ReviewableInterface;
use Ladb\CoreBundle\Form\Type\Core\ReviewType;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Entity\Core\Review;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Utils\ReviewableUtils;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/reviews')]
class ReviewController extends AbstractController {

	private function _retrieveRelatedEntity($entityType, $entityId, TypableUtils $typableUtils) {
		try {
			$entity = $typableUtils->findTypable($entityType, $entityId);
		} catch (\Exception $e) {
			throw $this->createNotFoundException($e->getMessage());
		}
		if (!($entity instanceof ReviewableInterface)) {
			throw $this->createNotFoundException('Entity must implements ReviewableInterface.');
		}
		return $entity;
	}

	/////
	/**
	 * @Template("@LadbCore/Core/Review/new-xhr.html.twig")
	 */
	#[Route(path: '/{entityType}/{entityId}/new', requirements: ['entityType' => '\d+', 'entityId' => '\d+'], name: 'core_review_new')]
	public function newAction(Request $request, $entityType, $entityId, TypableUtils $typableUtils) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_review_new)');
		}

        if ($this->getUser()->getCreatedAt() >= new \DateTime('-7days')) {
            throw $this->createNotFoundException('User must be more than 7 days old to publish review (core_review_new)');
        }

		$entity = $this->_retrieveRelatedEntity($entityType, $entityId, $typableUtils);
		if ($entity instanceof HiddableInterface && $entity->getIsPrivate()) {
			throw $this->createNotFoundException('Hidden entity could not be reviewed.');
		}

		$review = new Review();
		$form = $this->createForm(ReviewType::class, $review);

		return array(
			'entity' => $entity,
			'form'   => $form->createView(),
		);
	}

	/**
	 * @Template("@LadbCore/Core/Review/new-xhr.html.twig")
	 */
	#[Route(path: '/{entityType}/{entityId}/create', requirements: ['entityType' => '\d+', 'entityId' => '\d+'], methods: ['POST'], name: 'core_review_create')]
	public function createAction(Request $request, $entityType, $entityId, ActivityUtils $activityUtils, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, ReviewableUtils $reviewableUtils, TypableUtils $typableUtils, WatchableUtils $watchableUtils) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_review_create)');
		}

		// Exclude if user is not email confirmed
		if (!$this->getUser()->getEmailConfirmed()) {
			throw $this->createNotFoundException('Not allowed - User email not confirmed (core_knowledge_value_create)');
		}

		$this->createLock('core_review_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		// Retrieve related entity

		$entity = $this->_retrieveRelatedEntity($entityType, $entityId, $typableUtils);
		if ($entity instanceof HiddableInterface && $entity->getIsPrivate()) {
			throw $this->createNotFoundException('Hidden entity could not be reviewed.');
		}

		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		if ($reviewRepository->existsByEntityTypeAndEntityIdAndUser($entityType, $entityId, $this->getUser())) {
			throw $this->createNotFoundException('Only one review is allowed by user.');
		}

		$review = new Review();
		$review->setEntityType($entityType);
		$review->setEntityId($entityId);
		$review->setUser($this->getUser());
		$form = $this->createForm(ReviewType::class, $review);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($review);

			$entity->setChangedAt(new \DateTime());
			$entity->setUpdatedAt(new \DateTime());

			$entity->incrementReviewCount();
			$this->getUser()->getMeta()->incrementReviewCount();

			$om->persist($review);
			$om->flush();	// Need to flush before computing average rating

			// Average rating
			$reviewableUtils->computeAverageRating($entity);

			// Create activity
			$activityUtils->createReviewActivity($review, false);

			// Dispatch publication event (on entity)
			$dispatcher->dispatch(new PublicationEvent($entity), PublicationListener::PUBLICATION_CHANGED);

			// Auto watch
			$watchableUtils->autoCreateWatch($entity, $this->getUser());

			$om->flush();

			return $this->render('@LadbCore/Core/Review/create-xhr.html.twig', array(
				'entity' => $entity,
				'review' => $review,
			));
		}

		return array(
			'entity' => $entity,
			'form'   => $form->createView(),
		);
	}

	/**
	 * @Template("@LadbCore/Core/Review/edit-xhr.html.twig")
	 */
	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'], name: 'core_review_edit')]
	public function editAction(Request $request, $id, EntityManagerInterface $om, TypableUtils $typableUtils) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_review_edit)');
		}

		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		$review = $reviewRepository->findOneById($id);
		if (is_null($review)) {
			throw $this->createNotFoundException('Unable to find Review entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $review->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_core_review_edit)');
		}

		// Retrieve related entity
		$entity = $this->_retrieveRelatedEntity($review->getEntityType(), $review->getEntityId(), $typableUtils);

		$form = $this->createForm(ReviewType::class, $review);

		return array(
			'entity' => $entity,
			'review' => $review,
			'form'   => $form->createView(),
		);
	}

	/**
	 * @Template("@LadbCore/Core/Review/edit-xhr.html.twig")
	 */
	#[Route(path: '/{id}/update', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_review_update')]
	public function updateAction(Request $request, $id, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, ReviewableUtils $reviewableUtils, SearchUtils $searchUtils, TypableUtils $typableUtils) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_review_update)');
		}

		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		$review = $reviewRepository->findOneById($id);
		if (is_null($review)) {
			throw $this->createNotFoundException('Unable to find Review entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $review->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_knowledge_book_review_update)');
		}

		// Retrieve related entity
		$entity = $this->_retrieveRelatedEntity($review->getEntityType(), $review->getEntityId(), $typableUtils);

		$form = $this->createForm(ReviewType::class, $review);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($review);

			if ($review->getUser()->getId() == $this->getUser()->getId()) {
				$review->setUpdatedAt(new \DateTime());
			}

			// Average rating
			$reviewableUtils->computeAverageRating($entity);

			$om->flush();

			// Search index update
			$searchUtils->replaceEntityInIndex($entity);

			return $this->render('@LadbCore/Core/Review/update-xhr.html.twig', array(
				'entity' => $entity,
				'review' => $review,
			));
		}

		return array(
			'entity' => $entity,
			'review' => $review,
			'form'   => $form->createView(),
		);
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], name: 'core_review_delete')]
	public function deleteAction($id, ActivityUtils $activityUtils, EntityManagerInterface $om, ReviewableUtils $reviewableUtils, SearchUtils $searchUtils, TranslatorInterface $translator, TypableUtils $typableUtils) {
		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		$review = $reviewRepository->findOneById($id);
		if (is_null($review)) {
			throw $this->createNotFoundException('Unable to find Review entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $review->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_knowledge_book_review_delete)');
		}

		// Retrieve related entity

		$entity = $this->_retrieveRelatedEntity($review->getEntityType(), $review->getEntityId(), $typableUtils);

		// Delete comment

		$reviewableUtils->deleteReview($review, $entity, $activityUtils, $om, false);

		$om->flush();	// Need to flush before computing average rating

		// Average rating
		$reviewableUtils->computeAverageRating($entity);

		$om->flush();

		// Search index update
		$searchUtils->replaceEntityInIndex($entity);

		// Flashbag
		$this->addFlash('success', $translator->trans('review.'.$typableUtils->getStrippedNameByType($entity->getType()).'.alert.delete_success'));

		return $this->redirect($typableUtils->getUrlAction($entity));
	}

	#[Route(path: '/{id}', requirements: ['id' => '\d+'], name: 'core_review_show')]
	public function showAction(Request $request, $id, EntityManagerInterface $om, TypableUtils $typableUtils) {
		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		$review = $reviewRepository->findOneById($id);
		if (is_null($review)) {
			throw $this->createNotFoundException('Unable to find Review entity (id='.$id.').');
		}

		$entity = $this->_retrieveRelatedEntity($review->getEntityType(), $review->getEntityId(), $typableUtils);

		return $this->redirect($typableUtils->getUrlAction($entity).'#_review_'.$review->getId());
	}

}
