<?php

namespace Ladb\CoreBundle\Controller\Qa;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Utils\MentionUtils;
use Ladb\CoreBundle\Utils\WebpushNotificationUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Form\Type\Qa\AnswerType;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;
use Ladb\CoreBundle\Utils\VotableUtils;
use Ladb\CoreBundle\Manager\Qa\AnswerManager;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\Qa\QuestionManager;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/questions")
 */
class AnswerController extends AbstractController {

	use PublicationControllerTrait;

	/**
	 * @Route("/{id}/reponses/new", requirements={"id" = "\d+"}, name="core_qa_answer_new")
	 * @Template("@LadbCore/Qa/Answer/new-xhr.html.twig")
	 */
	public function newAction(Request $request, $id) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_qa_answer_new)');
		}

		$question = $this->retrievePublication($id, Question::CLASS_NAME);

        if ($question->getIsLocked()) {
            throw $this->createNotFoundException('Can\'t create answer on locked question (id='.$id.', core_qa_answer_new).');
        }

        $answer = new Answer();
		$answer->addBodyBlock(new \Ladb\CoreBundle\Entity\Core\Block\Text());	// Add a default Text body block
		$form = $this->createForm(AnswerType::class, $answer);

		return array(
			'question' => $question,
			'form'     => $form->createView(),
		);
	}

	/**
	 * @Route("/{id}/reponses/create", requirements={"id" = "\d+"}, methods={"POST"}, name="core_qa_answer_create")
	 * @Template("@LadbCore/Qa/Answer/new-xhr.html.twig")
	 */
	public function createAction(Request $request, $id, ActivityUtils $activityUtils, BlockBodiedUtils $blockUtils, CommentableUtils $commentableUtils, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, MentionUtils $mentionUtils, QuestionManager $questionManager, VotableUtils $votableUtils, WatchableUtils $watchableUtils, WebpushNotificationUtils $webpushNotificationUtils) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_qa_answer_create)');
		}
        if (!$this->getUser()->getEmailConfirmed()) {
            throw $this->createNotFoundException('Not emailConfirmed (core_qa_answer_create)');
        }

        $this->createLock('core_qa_answer_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$question = $this->retrievePublication($id, Question::CLASS_NAME);

		if ($question->getIsLocked()) {
			throw $this->createNotFoundException('Can\'t create answer on locked question (id='.$id.', core_qa_answer_create).');
		}

		$om = $this->getDoctrine()->getManager();
		$answerRepository = $om->getRepository(Answer::CLASS_NAME);
		if ($answerRepository->existsByQuestionAndUser($question, $this->getUser())) {
			throw $this->createNotFoundException('Only one answer allowed (id='.$id.').');
		}

		$answer = new Answer();
		$answer->setQuestion($question);		// Used by validator
		$answer->setUser($this->getUser());		// Used by validator
		$form = $this->createForm(AnswerType::class, $answer);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$blockUtils->preprocessBlocks($answer);

			$fieldPreprocessorUtils->preprocessFields($answer);

			$answer->setUser($this->getUser());
			$answer->setParentEntity($question);
			$answer->setParentEntityField('bestAnswer');
			$answer->setUpdatedAt(new \DateTime());

			$question->addAnswer($answer);
			$question->setChangedAt(new \DateTime());

			$question->incrementAnswerCount();
			$this->getUser()->getMeta()->incrementAnswerCount();

			$om->persist($answer);
			$om->flush();

			// Process mentions
			$mentionUtils->processMentions($answer);

			// Compute answer counters
			$questionManager->computeAnswerCounters($question);

			// Create activity
			$activityUtils->createAnswerActivity($answer, false);

			// Dispatch publication event (on Question)
			$dispatcher->dispatch(new PublicationEvent($question), PublicationListener::PUBLICATION_CHANGED);

			// Auto watch
			$watchableUtils->autoCreateWatch($question, $this->getUser());

			if ($answer->getUser() !== $question->getUser()) {
				// Publish a webpush notification in queue
				$webpushNotificationUtils->enqueueNewAnswerNotification($answer, $question);
			}

			return $this->render('@LadbCore/Qa/Answer/create-xhr.html.twig', array(
				'question'       => $question,
				'answer'         => $answer,
				'commentContext' => $commentableUtils->getCommentContext($answer, $this->getUser(), false),
				'voteContext'    => $votableUtils->getVoteContext($answer, $this->getUser()),
			));
		}

		return array(
			'question' => $question,
			'form'     => $form->createView(),
		);
	}

	/**
	 * @Route("/reponses/{id}/edit", requirements={"id" = "\d+"}, name="core_qa_answer_edit")
	 * @Template("@LadbCore/Qa/Answer/edit-xhr.html.twig")
	 */
	public function editAction(Request $request, $id) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_qa_answer_edit)');
		}

		$om = $this->getDoctrine()->getManager();
		$answerRepository = $om->getRepository(Answer::CLASS_NAME);

		$answer = $answerRepository->findOneById($id);
		if (is_null($answer)) {
			throw $this->createNotFoundException('Unable to find Answer entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $answer->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_qa_answer_edit)');
		}

		$form = $this->createForm(AnswerType::class, $answer);

		return array(
			'answer' => $answer,
			'form'   => $form->createView(),
		);
	}

	/**
	 * @Route("/reponses/{id}/update", requirements={"id" = "\d+"}, methods={"POST"}, name="core_qa_answer_update")
	 * @Template("@LadbCore/Qa/Answer/edit-xhr.html.twig")
	 */
	public function updateAction(Request $request, $id, BlockBodiedUtils $blockUtils, CommentableUtils $commentableUtils, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, MentionUtils $mentionUtils, VotableUtils $votableUtils) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_qa_answer_update)');
		}

		$om = $this->getDoctrine()->getManager();
		$answerRepository = $om->getRepository(Answer::CLASS_NAME);

		$answer = $answerRepository->findOneById($id);
		if (is_null($answer)) {
			throw $this->createNotFoundException('Unable to find Answer entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $answer->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_qa_answer_update)');
		}

		$originalBodyBlocks = $answer->getBodyBlocks()->toArray();	// Need to be an array to copy values

		$answer->resetBodyBlocks(); // Reset bodyBlocks array to consider form bodyBlocks order

		$form = $this->createForm(AnswerType::class, $answer);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$blockUtils->preprocessBlocks($answer, $originalBodyBlocks);

			$fieldPreprocessorUtils->preprocessFields($answer);

			if ($answer->getUser() == $this->getUser()) {
				$answer->setUpdatedAt(new \DateTime());
			}

			$om->flush();

			// Process mentions
			$mentionUtils->processMentions($answer);

			// Dispatch publication event (on Question)
			$dispatcher->dispatch(new PublicationEvent($answer->getQuestion()), PublicationListener::PUBLICATION_UPDATED);

			return $this->render('@LadbCore/Qa/Answer/update-xhr.html.twig', array(
				'question'       => $answer->getQuestion(),
				'answer'         => $answer,
				'commentContext' => $commentableUtils->getCommentContext($answer, $this->getUser(), false),
				'voteContext'    => $votableUtils->getVoteContext($answer, $this->getUser()),
			));
		}

		return array(
			'answer' => $answer,
			'form'   => $form->createView(),
		);
	}

	/**
	 * @Route("/reponses/{id}/best/create", requirements={"id" = "\d+"}, defaults={"action" = "create"}, name="core_qa_answer_best_create")
	 * @Route("/reponses/{id}/best/delete", requirements={"id" = "\d+"}, defaults={"action" = "delete"}, name="core_qa_answer_best_delete")
	 */
	public function bestToggleAction(Request $request, $id, $action, EventDispatcherInterface $dispatcher) {
		$om = $this->getDoctrine()->getManager();
		$answerRepository = $om->getRepository(Answer::CLASS_NAME);

		$answer = $answerRepository->findOneById($id);
		if (is_null($answer)) {
			throw $this->createNotFoundException('Unable to find Answer entity (id='.$id.').');
		}
		if ($action == 'create' && $answer->getVoteScore() < 0) {
			throw $this->createNotFoundException('Not allowed on negative voteScore answer.');
		}

		$question = $answer->getQuestion();

		if (!$this->isGranted('ROLE_ADMIN') && $question->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_qa_answer_best_create or core_qa_answer_best_delete)');
		}

		if ($action == 'delete') {
			$oldBestAnswer = $question->getBestAnswer();
			if (!is_null($oldBestAnswer) && $oldBestAnswer->getId() != $answer->getId()) {
				throw $this->createNotFoundException('Not the best answer (core_qa_answer_best_delete)');
			}
			$question->setBestAnswer(null);
		} else {
			$question->setBestAnswer($answer);
		}

		$om->flush();

		// Dispatch publication event (on Question)
		$dispatcher->dispatch(new PublicationEvent($question), PublicationListener::PUBLICATION_UPDATED);

		return $this->redirect($this->generateUrl('core_qa_answer_show', array( 'id' => $answer->getId() )));
	}

	/**
	 * @Route("/reponses/{id}/delete", requirements={"id" = "\d+"}, name="core_qa_answer_delete")
	 */
	public function deleteAction(Request $request, $id, AnswerManager $answerManager, SearchUtils $searchUtils, TranslatorInterface $translator) {
		$om = $this->getDoctrine()->getManager();
		$answerRepository = $om->getRepository(Answer::CLASS_NAME);

		$answer = $answerRepository->findOneById($id);
		if (is_null($answer)) {
			throw $this->createNotFoundException('Unable to find Answer entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $answer->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_qa_answer_delete)');
		}

		$question = $answer->getQuestion();

		// Delete
		$answerManager->delete($answer);

		// Search index update
		$searchUtils->replaceEntityInIndex($question);

		// Flashbag
		$this->addFlash('success', $translator->trans('qa.answer.form.alert.delete_success'));

		return $this->redirect($this->generateUrl('core_qa_question_show', array( 'id' => $question->getSluggedId() )));
	}

	/**
	 * @Route("/reponses/{id}", requirements={"id" = "\d+"}, name="core_qa_answer_show")
	 */
	public function showAction($id) {
		$om = $this->getDoctrine()->getManager();
		$answerRepository = $om->getRepository(Answer::CLASS_NAME);

		$answer = $answerRepository->findOneById($id);
		if (is_null($answer)) {
			throw $this->createNotFoundException('Unable to find Answer entity (id='.$id.').');
		}

		$question = $answer->getQuestion();
		if ($question->getIsDraft() === true) {
			if (!$this->isGranted('ROLE_ADMIN') && (is_null($this->getUser()) || $question->getUser()->getId() != $this->getUser()->getId())) {
				throw $this->createNotFoundException('Not allowed (core_qa_answer_show)');
			}
		}

		return $this->redirect($this->generateUrl('core_qa_question_show', array( 'id' => $question->getSluggedId() )).'#_answer_'.$answer->getId());
	}

	/**
	 * @Route("/{id}/reponses", requirements={"id" = "\d+"}, name="core_qa_answer_list")
	 * @Route("/{id}/reponses/{sorter}", requirements={"id" = "\d+", "sorter" = "[a-z-]+"}, name="core_qa_answer_list_sorter")
	 * @Template("@LadbCore/Qa/Answer/list-xhr.html.twig")
	 */
	public function listAction(Request $request, $id, CommentableUtils $commentableUtils, VotableUtils $votableUtils, $sorter = 'score') {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_qa_answer_list_sorter)');
		}

		$om = $this->getDoctrine()->getManager();
		$questionRepository = $om->getRepository(\Ladb\CoreBundle\Entity\Qa\Question::CLASS_NAME);
		$answerRepository = $om->getRepository(\Ladb\CoreBundle\Entity\Qa\Answer::CLASS_NAME);

		$id = intval($id);

		$question = $questionRepository->findOneById($id);
		if (is_null($question)) {
			throw $this->createNotFoundException('Unable to find Question entity (id='.$id.').');
		}

		$answers = $answerRepository->findByQuestion($question, $sorter);

		return array(
			'sorter'          => $sorter,
			'question'        => $question,
			'answers'         => $answers,
			'voteContexts'    => $votableUtils->getVoteContexts($question->getAnswers(), $this->getUser()),
			'commentContexts' => $commentableUtils->getCommentContexts($question->getAnswers(), false),
		);
	}

	/**
	 * @Route("/{id}/reponses/admin/converttocomment", requirements={"id" = "\d+"}, name="core_qa_answer_admin_converttocomment")
	 * @Security("is_granted('ROLE_ADMIN')", statusCode=404, message="Not allowed (core_qa_answer_admin_converttocomment)")
	 */
	public function adminConvertToCommentAction($id, AnswerManager $answerManager, TranslatorInterface $translator) {
		$om = $this->getDoctrine()->getManager();
		$answerRepository = $om->getRepository(Answer::CLASS_NAME);

		$answer = $answerRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($answer)) {
			throw $this->createNotFoundException('Unable to find Answer entity (id='.$id.').');
		}
		$question = $answer->getQuestion();

		// Convert
		$find = $answerManager->convertToComment($answer, $question);

		// Flashbag
		$this->addFlash('success', $translator->trans('qa.answer.admin.alert.converttocomment_success', array( '%title%' => $answer->getTitle() )));

		return $this->redirect($this->generateUrl('core_qa_question_show', array( 'id' => $question->getSluggedId() )));
	}

}
