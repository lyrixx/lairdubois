<?php

namespace Ladb\CoreBundle\Controller\Faq;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\ExpressionLanguage\Expression;
use Ladb\CoreBundle\Entity\Faq\Question;
use Ladb\CoreBundle\Form\Type\Faq\QuestionType;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\ExplorableUtils;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Manager\Faq\QuestionManager;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Model\HiddableInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/faq')]
class QuestionController extends AbstractController {

	use PublicationControllerTrait;

	#[Route(path: '/new', name: 'core_faq_question_new')]
	#[Template('@LadbCore/Faq/Question/new.html.twig')]
	#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_FAQ')"), statusCode: 404, message: 'Not allowed (core_faq_question_new)')]
	public function newAction(Request $request, TagUtils $tagUtils) {

		$question = new Question();
		$question->addBodyBlock(new \Ladb\CoreBundle\Entity\Core\Block\Text());	// Add a default Text body block
		$form = $this->createForm(QuestionType::class, $question);

		return array(
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($question),
		);
	}

	#[Route(path: '/create', methods: ['POST'], name: 'core_faq_question_create')]
	#[Template('@LadbCore/Faq/Question/new.html.twig')]
	#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_FAQ')"), statusCode: 404, message: 'Not allowed (core_faq_question_create)')]
	public function createAction(Request $request, BlockBodiedUtils $blockBodiedUtils, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, TagUtils $tagUtils, TranslatorInterface $translator) {

		$this->createLock('core_faq_question_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$question = new Question();
		$form = $this->createForm(QuestionType::class, $question);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$blockBodiedUtils->preprocessBlocks($question);

			$fieldPreprocessorUtils->preprocessFields($question);

			if ($question->getBodyBlockPictureCount() > 0) {
				$question->setMainPicture($blockBodiedUtils->getFirstPicture($question));
			}

			$question->setUser($this->getUser());

			$om->persist($question);
			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($question), PublicationListener::PUBLICATION_CREATED);

			return $this->redirect($this->generateUrl('core_faq_question_show', array( 'id' => $question->getSluggedId() )));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'question'     => $question,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($question),
		);
	}

	#[Route(path: '/{id}/lock', requirements: ['id' => '\d+'], defaults: ['lock' => true], name: 'core_faq_question_lock')]
	#[Route(path: '/{id}/unlock', requirements: ['id' => '\d+'], defaults: ['lock' => false], name: 'core_faq_question_unlock')]
	public function lockUnlockAction($id, $lock, QuestionManager $postManager, TranslatorInterface $translator) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertLockUnlockable($question, $lock);

		// Lock or Unlock
		if ($lock) {
			$postManager->lock($question);
		} else {
			$postManager->unlock($question);
		}

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.creation.form.alert.'.($lock ? 'lock' : 'unlock').'_success', array( '%title%' => $question->getTitle() )));

		return $this->redirect($this->generateUrl('core_faq_question_show', array( 'id' => $question->getSluggedId() )));
	}

	#[Route(path: '/{id}/publish', requirements: ['id' => '\d+'], name: 'core_faq_question_publish')]
	#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_FAQ')"), statusCode: 404, message: 'Not allowed (core_faq_question_publish)')]
	public function publishAction($id, QuestionManager $questionManager, TranslatorInterface $translator) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertPublishable($question);

		// Publish
		$questionManager->publish($question);

		// Flashbag
		$this->addFlash('success', $translator->trans('faq.question.form.alert.publish_success', array( '%title%' => $question->getTitle() )));

		return $this->redirect($this->generateUrl('core_faq_question_show', array( 'id' => $question->getSluggedId() )));
	}

	#[Route(path: '/{id}/unpublish', requirements: ['id' => '\d+'], name: 'core_faq_question_unpublish')]
	public function unpublishAction(Request $request, $id, QuestionManager $questionManager, TranslatorInterface $translator) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertUnpublishable($question);

		// Unpublish
		$questionManager->unpublish($question);

		// Flashbag
		$this->addFlash('success', $translator->trans('faq.question.form.alert.unpublish_success', array( '%title%' => $question->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $this->getRefererUrl($request);
		}

		return $this->redirect($returnToUrl);
	}

	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'], name: 'core_faq_question_edit')]
	#[Template('@LadbCore/Faq/Question/edit.html.twig')]
	#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_FAQ')"), statusCode: 404, message: 'Not allowed (core_faq_question_edit)')]
	public function editAction($id, TagUtils $tagUtils) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertEditabable($question);

		$form = $this->createForm(QuestionType::class, $question);

		return array(
			'question'     => $question,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($question),
		);
	}

	#[Route(path: '/{id}/update', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_faq_question_update')]
	#[Template('@LadbCore/Faq/Question/edit.html.twig')]
	#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_FAQ')"), statusCode: 404, message: 'Not allowed (core_faq_question_update)')]
	public function updateAction(Request $request, $id, BlockBodiedUtils $blockBodiedUtils, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, TagUtils $tagUtils, TranslatorInterface $translator) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertEditabable($question);

		$originalBodyBlocks = $question->getBodyBlocks()->toArray();	// Need to be an array to copy values
		$previouslyUsedTags = $question->getTags()->toArray();	// Need to be an array to copy values

		$question->resetBodyBlocks(); // Reset bodyBlocks array to consider form bodyBlocks order

		$form = $this->createForm(QuestionType::class, $question);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$blockBodiedUtils->preprocessBlocks($question, $originalBodyBlocks);

			$fieldPreprocessorUtils->preprocessFields($question);

			if ($question->getBodyBlockPictureCount() > 0) {
				$question->setMainPicture($blockBodiedUtils->getFirstPicture($question));
			}

			if ($question->getUser() == $this->getUser()) {
				$question->setUpdatedAt(new \DateTime());
			}

			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($question, array( 'previouslyUsedTags' => $previouslyUsedTags )), PublicationListener::PUBLICATION_UPDATED);

			// Flashbag
			$this->addFlash('success', $translator->trans('faq.question.form.alert.update_success', array( '%title%' => $question->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(QuestionType::class, $question);

		} else {

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'question'     => $question,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($question),
		);
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], name: 'core_faq_question_delete')]
	public function deleteAction($id, QuestionManager $questionManager, TranslatorInterface $translator) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertDeletable($question);

		// Delete
		$questionManager->delete($question);

		// Flashbag
		$this->addFlash('success', $translator->trans('faq.question.form.alert.delete_success', array( '%title%' => $question->getTitle() )));

		return $this->redirect($this->generateUrl('core_faq_question_list'));
	}

	#[Route(path: '/{id}/widget', requirements: ['id' => '\d+'], name: 'core_faq_question_widget')]
	#[Template('@LadbCore/Faq/Question/widget-xhr.html.twig')]
	public function widgetAction($id) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertShowable($question, true);

		return array(
			'question' => $question,
		);
	}

	#[Route(path: '/{filter}', requirements: ['filter' => '[a-z-]+'], name: 'core_faq_question_list_filter')]
	#[Route(path: '/{filter}/{page}', requirements: ['filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_faq_question_list_filter_page')]
	public function goneListAction(Request $request, $filter, $page = 0) {
		throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException();
	}

	#[Route(path: '/', name: 'core_faq_question_list')]
	#[Route(path: '/{page}', requirements: ['page' => '\d+'], name: 'core_faq_question_list_page')]
	#[Template('@LadbCore/Faq/Question/list.html.twig')]
	public function listAction(Request $request, EventDispatcherInterface $dispatcher, SearchUtils $searchUtils, $page = 0) {

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_faq_question_list_page)');
		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils) {
				switch ($facet->name) {

					// Filters /////

					case 'admin-all':
						if ($this->isGranted('ROLE_ADMIN')) {

							$filters[] = new \Elastica\Query\MatchAll();

							$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

							$noGlobalFilters = true;
						}
						break;

					case 'mine':

						if ($this->isGranted('ROLE_USER')) {

							if (isset($facet->value) && $facet->value == 'draft') {

								$filter = (new \Elastica\Query\BoolQuery())
									->addFilter(new \Elastica\Query\MatchPhrase('user.username', $this->getUser()->getUsername()))
									->addFilter(new \Elastica\Query\Range('visibility', array( 'lt' => HiddableInterface::VISIBILITY_PUBLIC )))
								;

							} else {

								$filter = new \Elastica\Query\MatchPhrase('user.username', $this->getUser()->getUsernameCanonical());
							}

							$filters[] = $filter;

						}

						break;

					case 'period':

						if ($facet->value == 'last7days') {

							$filters[] = new \Elastica\Query\Range('createdAt', array( 'gte' => 'now-7d/d' ));

						} elseif ($facet->value == 'last30days') {

							$filters[] = new \Elastica\Query\Range('createdAt', array( 'gte' => 'now-30d/d' ));

						}

						break;

					case 'tag':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'tags.label^2' ));
						$filters[] = $filter;

						break;

					// Sorters /////

					case 'sort-recent':
						$sort = array( 'changedAt' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-views':
						$sort = array( 'viewCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-likes':
						$sort = array( 'likeCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-comments':
						$sort = array( 'commentCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-random':
						$sort = array( 'randomSeed' => isset($facet->value) ? $facet->value : '' );
						break;

					case 'sort-important':
						$sort = array( 'weight' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'title^300', 'body', 'tags.label^2' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) {

				if ($this->isGranted('ROLE_USER') && $this->getUser()->getMeta()->getUnlistedFaqQuestionCount() > 0) {
					$sort = array('changedAt' => array('order' => 'desc'));
				} else {
					$sort = array( 'weight' => array( 'order' => 'desc' ) );
				}

			},
			function(&$filters) {

				$this->pushGlobalVisibilityFilter($filters, true, true);

			},
			'fos_elastica.index.faq_question',
			\Ladb\CoreBundle\Entity\Faq\Question::CLASS_NAME,
			'core_faq_question_list_page'
		);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()), PublicationListener::PUBLICATIONS_LISTED);

		$parameters = array_merge($searchParameters, array(
			'questions' => $searchParameters['entities'],
		));

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Faq/Question/list-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

	#[Route(path: '/{id}.html', requirements: ['slug' => '[a-z-]+'], name: 'core_faq_question_show')]
	#[Template('@LadbCore/Faq/Question/show.html.twig')]
	public function showAction(Request $request, $id, CollectionnableUtils $collectionnableUtils, CommentableUtils $commentableUtils, EventDispatcherInterface $dispatcher, ExplorableUtils $explorableUtils, FollowerUtils $followerUtils, LikableUtils $likableUtils, EntityManagerInterface $om, WatchableUtils $watchableUtils, WitnessManager $witnessManager) {
		$questionRepository = $om->getRepository(Question::CLASS_NAME);

		if (intval($id) == 0) {
			$question = $questionRepository->findOneBySlugJoinedOnAll($id);
		} else {
			$id = intval($id);
			$question = $questionRepository->findOneById($id);
		}
		if (is_null($question)) {
			if ($response = $witnessManager->checkResponse(Question::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Question entity (id='.$id.').');
		}
		$this->assertShowable($question);

		$similarQuestions = $explorableUtils->getSimilarExplorables($question, 'fos_elastica.index.faq_question', Question::CLASS_NAME, null, 10);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationEvent($question), PublicationListener::PUBLICATION_SHOWN);

		return array(
			'question'          => $question,
			'permissionContext' => $this->getPermissionContext($question),
			'similarQuestions'  => $similarQuestions,
			'likeContext'       => $likableUtils->getLikeContext($question, $this->getUser()),
			'watchContext'      => $watchableUtils->getWatchContext($question, $this->getUser()),
			'commentContext'    => $commentableUtils->getCommentContext($question),
			'collectionContext' => $collectionnableUtils->getCollectionContext($question),
			'followerContext'   => $followerUtils->getFollowerContext($question->getUser(), $this->getUser()),
		);
	}

}
