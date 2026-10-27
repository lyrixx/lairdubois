<?php

namespace Ladb\CoreBundle\Manager\Core;

use Ladb\CoreBundle\Entity\Core\Block\Gallery;
use Ladb\CoreBundle\Entity\Core\Block\Text;
use Ladb\CoreBundle\Entity\Core\Comment;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\AbstractManager;
use Ladb\CoreBundle\Manager\Qa\QuestionManager;
use Ladb\CoreBundle\Model\IndexableInterface;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\MentionUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\VotableUtils;

class CommentManager extends AbstractManager {

	const NAME = 'ladb_core.core_comment_manager';

	private $blockBodiedUtils;
	private $commentableUtils;
	private $fieldPreprocessorUtils;
	private $searchUtils;

	public function __construct(BlockBodiedUtils $blockBodiedUtils, CommentableUtils $commentableUtils, FieldPreprocessorUtils $fieldPreprocessorUtils, SearchUtils $searchUtils) {
		$this->blockBodiedUtils = $blockBodiedUtils;
		$this->commentableUtils = $commentableUtils;
		$this->fieldPreprocessorUtils = $fieldPreprocessorUtils;
		$this->searchUtils = $searchUtils;
	}

	public function delete(Comment $comment, $flush = true) {
		parent::deleteEntity($comment, $flush);
	}

	public function convertToAnswer(Comment $comment, Question $question) {
		$om = $this->doctrine->getManager();

		$answer = new Answer();
		$answer->setCreatedAt($comment->getCreatedAt());
		$answer->setUpdatedAt($comment->getUpdatedAt());
		$answer->setUser($comment->getUser());
		$answer->setParentEntity($question);
		$answer->setParentEntityField('bestAnswer');

		$this->blockBodiedUtils->copyBodyTo($comment, $answer);
		$this->blockBodiedUtils->copyPicturesTo($comment, $answer);

		$question->addAnswer($answer);

		$this->fieldPreprocessorUtils->preprocessBodyBlocksField($answer);

		// Answer counters

		$question->incrementAnswerCount();
		$answer->getUser()->getMeta()->incrementAnswerCount();

		// Persist answer to generate ID
		$om->persist($answer);
		$om->flush();

		// Transfer children comments
		$this->commentableUtils->transferChildrenComments($comment, $question, $answer);

		// Remove comment

		$this->commentableUtils->deleteComment($comment, $question, $om, true);

		// Update index
		$this->searchUtils->replaceEntityInIndex($question);

		return $answer;
	}

}