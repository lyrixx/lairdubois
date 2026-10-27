<?php

namespace Ladb\CoreBundle\Event;

use Ladb\CoreBundle\Manager\Qa\QuestionManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue;
use Ladb\CoreBundle\Model\IndexableInterface;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\KnowledgeUtils;
use Ladb\CoreBundle\Utils\TextureUtils;

class VotableListener implements EventSubscriberInterface {

	const VOTE_UPDATED = 'ladb.votable.vote_updated';

	private $knowledgeUtils;
	private $questionManager;
	private $searchUtils;
	private $textureUtils;

	public function __construct(KnowledgeUtils $knowledgeUtils, QuestionManager $questionManager, SearchUtils $searchUtils, TextureUtils $textureUtils) {
		$this->knowledgeUtils = $knowledgeUtils;
		$this->questionManager = $questionManager;
		$this->searchUtils = $searchUtils;
		$this->textureUtils = $textureUtils;
	}

	public static function getSubscribedEvents() {
		return array(
			VotableListener::VOTE_UPDATED   => 'onVoteUpdated',
		);
	}

	/////

	public function onVoteUpdated(VotableEvent $event) {
		$votable = $event->getVotable();
		$votableParent = $event->getVotableParent();

		if ($votableParent instanceof \Ladb\CoreBundle\Entity\Knowledge\AbstractKnowledge) {

			// Update field
			$this->knowledgeUtils->updateKnowledgeField($votableParent, $votable->getParentEntityField());

			if ($votableParent instanceof \Ladb\CoreBundle\Entity\Knowledge\Wood
				&& ($votable->getParentEntityField() == \Ladb\CoreBundle\Entity\Knowledge\Wood::FIELD_GRAIN || $votable->getParentEntityField() == \Ladb\CoreBundle\Entity\Knowledge\Wood::FIELD_ENDGRAIN)
				&& $votable instanceof BaseValue
			) {

				if ($votable->getVoteScore() < 0) {

					// Delete texture if it exists
					$this->textureUtils->deleteTexture($votableParent, $votable, false);

				} else {

					// Create texture if it doesn't exist
					$this->textureUtils->createTexture($votableParent, $votable, false);

				}

			}

			// Search index update
			$this->searchUtils->replaceEntityInIndex($votableParent);

		} else if ($votableParent instanceof \Ladb\CoreBundle\Entity\Qa\Question) {

			// Compute answer counters
			$this->questionManager->computeAnswerCounters($votableParent);

			// Search index update
			$this->searchUtils->replaceEntityInIndex($votableParent);

		}

	}

}