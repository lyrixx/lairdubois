<?php

namespace Ladb\CoreBundle\Manager\Offer;

use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\AbstractAuthoredPublicationManager;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\ViewableUtils;

class OfferManager extends AbstractAuthoredPublicationManager {

	const NAME = 'ladb_core.offer_offer_manager';

	private $activityUtils;
	private $blockBodiedUtils;
	private $fieldPreprocessorUtils;
	private $viewableUtils;

	public function __construct(ActivityUtils $activityUtils, BlockBodiedUtils $blockBodiedUtils, FieldPreprocessorUtils $fieldPreprocessorUtils, ViewableUtils $viewableUtils) {
		$this->activityUtils = $activityUtils;
		$this->blockBodiedUtils = $blockBodiedUtils;
		$this->fieldPreprocessorUtils = $fieldPreprocessorUtils;
		$this->viewableUtils = $viewableUtils;
	}

	/////

	public function publish(Offer $offer, $flush = true) {

        $offer->getUser()->getMeta()->incrementPrivateOfferCount(-1);
        $offer->getUser()->getMeta()->incrementPublicOfferCount();

        parent::publishPublication($offer, $flush);
	}

	public function unpublish(Offer $offer, $flush = true) {

        $offer->getUser()->getMeta()->incrementPrivateOfferCount();
        $offer->getUser()->getMeta()->incrementPublicOfferCount(-1);

        parent::unpublishPublication($offer, $flush);
	}

	public function delete(Offer $offer, $withWitness = true, $flush = true) {

		// Decrement user offer count
		if ($offer->getIsDraft()) {
			$offer->getUser()->getMeta()->incrementPrivateOfferCount(-1);
		} else {
			$offer->getUser()->getMeta()->incrementPublicOfferCount(-1);
		}

        parent::deletePublication($offer, $withWitness, $flush);
	}

	//////

	public function changeOwner(Offer $offer, User $user, $flush = true) {
		parent::changeOwnerPublication($offer, $user, $flush);
	}

	protected function updateUserCounterAfterChangeOwner(User $user, $by, $isPrivate) {
		if ($isPrivate) {
			$user->getMeta()->incrementPrivateOfferCount($by);
		} else {
			$user->getMeta()->incrementPublicOfferCount($by);
		}
	}

	/////

	public function convertToQuestion(Offer $offer, $flush = true) {
		$om = $this->doctrine->getManager();

		// Create a new offer

		$question = new \Ladb\CoreBundle\Entity\Qa\Question();
		$question->setCreatedAt($offer->getCreatedAt());
		$question->setUpdatedAt($offer->getUpdatedAt());
		$question->setChangedAt($offer->getChangedAt());
		$question->setVisibility($offer->getVisibility());
		$question->setIsDraft($offer->getIsDraft());
		$question->setTitle($offer->getTitle());
		$question->setUser($offer->getUser());

		$this->blockBodiedUtils->copyBlocksTo($offer, $question);

		if ($offer->getPictures()->count() > 0) {

			$textBlock = new \Ladb\CoreBundle\Entity\Core\Block\Text();
			$textBlock->setBody('Images');
			$textBlock->setSortIndex(0);
			$question->addBodyBlock($textBlock);

			$galleryBlock = new \Ladb\CoreBundle\Entity\Core\Block\Gallery();
			foreach ($offer->getPictures() as $picture) {
				$galleryBlock->addPicture($picture);
			}
			$galleryBlock->setSortIndex(1);
			$question->addBodyBlock($galleryBlock);

		}

		foreach ($offer->getTags() as $tag) {
			$question->addTag($tag);
		}

		// Setup question's htmlBody
		$this->fieldPreprocessorUtils->preprocessFields($question);

		// Persist question to generate ID
		$om->persist($question);
		$om->flush();

		// Dispatch publication event
		$this->dispatcher->dispatch(PublicationListener::PUBLICATION_CREATED_FROM_CONVERT, new PublicationEvent($question));

		// User counter
		if ($question->getIsDraft()) {
			$question->getUser()->getMeta()->incrementPrivateQuestionCount(1);
		} else {
			$question->getUser()->getMeta()->incrementPublicQuestionCount(1);
		}

		// Transfer views
		$this->viewableUtils->transferViews($offer, $question, false);

		// Transfer likes
		$this->likableUtils->transferLikes($offer, $question, false);

		// Transfer comments
		$this->commentableUtils->transferComments($offer, $question, false);

		// Transfer watches
		$this->watchableUtils->transferWatches($offer, $question, false);

		// transfer reports
		$this->reportableUtils->transferReports($offer, $question, false);

		// Transfer publish activities
		$this->activityUtils->transferPublishActivities($offer->getType(), $offer->getId(), $question->getType(), $question->getId(), false);

		// Create the witness
		$this->witnessManager->createConvertedByPublication($offer, $question, false);

		// Delete the offer
		$this->delete($offer, false, false);

		if ($flush) {
			$om->flush();
		}

		return $question;
	}

}