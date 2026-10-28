<?php

namespace Ladb\CoreBundle\Manager\Qa;

use Ladb\CoreBundle\Entity\Core\Block\Gallery;
use Ladb\CoreBundle\Entity\Core\Comment;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Manager\AbstractManager;
use Ladb\CoreBundle\Model\IndexableInterface;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\MentionUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\VotableUtils;
use Symfony\Contracts\Service\Attribute\Required;

class AnswerManager extends AbstractManager {

	const NAME = 'ladb_core.qa_answer_manager';

	private $activityUtils;
	private $commentableUtils;
	private $fieldPreprocessorUtils;
	private $mentionUtils;
	private $questionManager;
	private $searchUtils;
	private $votableUtils;

	public function __construct(ActivityUtils $activityUtils, CommentableUtils $commentableUtils, FieldPreprocessorUtils $fieldPreprocessorUtils, MentionUtils $mentionUtils, SearchUtils $searchUtils, VotableUtils $votableUtils) {
		$this->activityUtils = $activityUtils;
		$this->commentableUtils = $commentableUtils;
		$this->fieldPreprocessorUtils = $fieldPreprocessorUtils;
		$this->mentionUtils = $mentionUtils;
		$this->searchUtils = $searchUtils;
		$this->votableUtils = $votableUtils;
	}

	/**
	 * Not in the constructor: QuestionManager receives this manager in its own.
	 */
	#[Required]
	public function setQuestionManager(QuestionManager $questionManager) {
		$this->questionManager = $questionManager;
	}

	/////

	public function delete(Answer $answer, $flush = true) {

		$question = $answer->getQuestion();

		// Drecrement question answer count
		$question->incrementAnswerCount(-1);

		if (!$question->getIsDraft()) {

			// Decrement user answer count
			$answer->getUser()->getMeta()->incrementAnswerCount(-1);

		}

		// Clear best answer
		if ($answer->getIsBestAnswer()) {
			$question->setBestAnswer(null);
		}

		/////

		// Delete mentions
		$this->mentionUtils->deleteMentions($answer, false);

		// Delete comments
		$this->commentableUtils->deleteComments($answer, false);

		// Delete votes
		$this->votableUtils->deleteVotes($answer, $question, false);

		// Delete activities
		$this->activityUtils->deleteActivitiesByAnswer($answer, false);

		// Compute answer counters
		$this->questionManager->computeAnswerCounters($question);

		parent::deleteEntity($answer, $flush);
	}

	/////

	public function converttocomment(Answer $answer, Question $question) {
		$om = $this->doctrine->getManager();

		// Create a new comment on the question

		$comment = new Comment();
		$comment->setEntityId($question->getId());
		$comment->setEntityType($question->getType());
		$comment->setUser($answer->getUser());
		$comment->setCreatedAt($answer->getCreatedAt());
		$comment->setUpdatedAt($answer->getUpdatedAt());
		$comment->setBody($answer->getBody());

		foreach ($answer->getBodyBlocks() as $block) {
			if ($block instanceof Gallery) {
				$count = 0;
				foreach ($block->getPictures() as $picture) {
					$comment->addPicture($picture);
					if ($count++ >= 4) {
						break;
					}
				}
			}
		}

		$this->fieldPreprocessorUtils->preprocessFields($comment);

		// Comment counters

		$question->incrementCommentCount();
		$answer->getUser()->getMeta()->incrementCommentCount();

		$om->persist($comment);

		// Create activity
		$this->activityUtils->createCommentActivity($comment, false);

		// Remove answer

		$this->delete($answer, true);

		// Update index
		if ($question instanceof IndexableInterface) {
			$this->searchUtils->replaceEntityInIndex($question);
		}

	}

}