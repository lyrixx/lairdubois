<?php

namespace Ladb\CoreBundle\Controller\Howto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Twig\Attribute\Template;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Utils\StripableUtils;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Form\Type\Howto\HowtoType;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\EmbeddableUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Manager\Howto\HowtoManager;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Liip\ImagineBundle\Controller\ImagineController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/pas-a-pas')]
class HowtoController extends AbstractHowtoBasedController {

	use PublicationControllerTrait;

	#[Route(path: '/new', name: 'core_howto_new')]
	#[Template('@LadbCore/Howto/Howto/new.html.twig')]
	public function newAction(Request $request, TagUtils $tagUtils) {

		$howto = new Howto();
		$form = $this->createForm(HowtoType::class, $howto);

		return array(
			'form'         => $form->createView(),
			'owner'        => $this->retrieveOwner($request),
			'tagProposals' => $tagUtils->getProposals($howto),
		);
	}

	#[Route(path: '/create', methods: ['POST'], name: 'core_howto_create')]
	#[Template('@LadbCore/Howto/Howto/new.html.twig')]
	public function createAction(Request $request, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, TagUtils $tagUtils, TranslatorInterface $translator) {

		$owner = $this->retrieveOwner($request);

		$this->createLock('core_howto_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$howto = new Howto();
		$form = $this->createForm(HowtoType::class, $howto);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($howto);

			$howto->setUser($owner);
			$owner->getMeta()->incrementPrivateHowtoCount();

			$om->persist($howto);
			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($howto), PublicationListener::PUBLICATION_CREATED);

			return $this->redirect($this->generateUrl('core_howto_article_new', array( 'id' => $howto->getId(), 'owner' => $owner->getUsernameCanonical() )));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'howto'        => $howto,
			'form'         => $form->createView(),
			'owner'		   => $owner,
			'tagProposals' => $tagUtils->getProposals($howto),
			'hideWarning'  => true,
		);
	}

	/////
	#[Route(path: '/{id}/lock', requirements: ['id' => '\d+'], defaults: ['lock' => true], name: 'core_howto_lock')]
	#[Route(path: '/{id}/unlock', requirements: ['id' => '\d+'], defaults: ['lock' => false], name: 'core_howto_unlock')]
	public function lockUnlockAction($id, $lock, HowtoManager $howtoManager, TranslatorInterface $translator) {

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertLockUnlockable($howto, $lock);

		// Lock or Unlock
		if ($lock) {
			$howtoManager->lock($howto);
		} else {
			$howtoManager->unlock($howto);
		}

		// Flashbag
		$this->addFlash('success', $translator->trans('howto.howto.form.alert.'.($lock ? 'lock' : 'unlock').'_success', array( '%title%' => $howto->getTitle() )));

		return $this->redirect($this->generateUrl('core_howto_show', array( 'id' => $howto->getSluggedId() )));
	}

	#[Route(path: '/{id}/publish', requirements: ['id' => '\d+'], name: 'core_howto_publish')]
	public function publishAction($id, HowtoManager $howtoManager, TranslatorInterface $translator) {

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertPublishable($howto);

		// Publish
		$howtoManager->publish($howto);

		// Flashbag
		$this->addFlash('success', $translator->trans('howto.howto.form.alert.publish_success', array( '%title%' => $howto->getTitle() )));

		return $this->redirect($this->generateUrl('core_howto_show', array( 'id' => $howto->getSluggedId() )));
	}

	#[Route(path: '/{id}/unpublish', requirements: ['id' => '\d+'], name: 'core_howto_unpublish')]
	public function unpublishAction(Request $request, $id, HowtoManager $howtoManager, TranslatorInterface $translator) {

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertUnpublishable($howto);

		// Unpublish
		$howtoManager->unpublish($howto);

		// Flashbag
		$this->addFlash('success', $translator->trans('howto.howto.form.alert.unpublish_success', array( '%title%' => $howto->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $this->getRefererUrl($request);
		}

		return $this->redirect($returnToUrl);
	}

	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'], name: 'core_howto_edit')]
	#[Template('@LadbCore/Howto/Howto/edit.html.twig')]
	public function editAction($id, TagUtils $tagUtils) {

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertEditabable($howto);

		$form = $this->createForm(HowtoType::class, $howto);

		return array(
			'howto'        => $howto,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($howto),
		);
	}

	#[Route(path: '/{id}/update', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_howto_update')]
	#[Template('@LadbCore/Howto/Howto/edit.html.twig')]
	public function updateAction(Request $request, $id, EventDispatcherInterface $dispatcher, EmbeddableUtils $embaddableUtils, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, TagUtils $tagUtils, TranslatorInterface $translator) {

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertEditabable($howto);

		$howto->resetArticles();	// Reset articles array to consider form articles order

		$previouslyUsedTags = $howto->getTags()->toArray();	// Need to be an array to copy values

		$form = $this->createForm(HowtoType::class, $howto);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($howto);

			$embaddableUtils->resetSticker($howto);

			if ($howto->getUser() == $this->getUser()) {
				$howto->setUpdatedAt(new \DateTime());
			}

			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($howto, array( 'previouslyUsedTags' => $previouslyUsedTags )), PublicationListener::PUBLICATION_UPDATED);

			// Flashbag
			$this->addFlash('success', $translator->trans('howto.howto.form.alert.update_success', array( '%title%' => $howto->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(HowtoType::class, $howto);

		} else {

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'howto'        => $howto,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($howto),
		);
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], name: 'core_howto_delete')]
	public function deleteAction($id, HowtoManager $howtoManager, TranslatorInterface $translator) {

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertDeletable($howto);

		// Delete
		$howtoManager->delete($howto);

		// Flashbag
		$this->addFlash('success', $translator->trans('howto.howto.form.alert.delete_success', array( '%title%' => $howto->getTitle() )));

		return $this->redirect($this->generateUrl('core_howto_list'));
    }

	#[Route(path: '/{id}/chown', requirements: ['id' => '\d+'], name: 'core_howto_chown')]
	public function chownAction(Request $request, $id, HowtoManager $howtoManager, TranslatorInterface $translator) {

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertChownable($howto);

		$targetUser = $this->retrieveOwner($request);

		// Change owner
		$howtoManager->changeOwner($howto, $targetUser);

		// Flashbag
		$this->addFlash('success', $translator->trans('howto.howto.form.alert.chown_success', array( '%title%' => $howto->getTitle() )));

		return $this->redirect($this->generateUrl('core_howto_show', array( 'id' => $howto->getSluggedId() )));
	}

	#[Route(path: '/{id}/sticker.png', requirements: ['id' => '\d+'], name: 'core_howto_sticker_png')]
	#[Route(path: '/{id}/sticker', requirements: ['id' => '\d+'], name: 'core_howto_sticker')]
	public function stickerAction(Request $request, $id, EmbeddableUtils $embeddableUtils, ImagineController $imagineController, EntityManagerInterface $om) {
		$id = intval($id);

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto, true);

		$sticker = $howto->getSticker();
		if (is_null($sticker)) {
			$sticker = $embeddableUtils->generateSticker($howto);
			if (!is_null($sticker)) {
				$om->flush();
			} else {
				throw $this->createNotFoundException('Error creating sticker (core_howto_sticker)');
			}
		}

		if (!is_null($sticker)) {

			$response = $imagineController->filterAction($request, $sticker->getWebPath(), '598w');
			return $response;

		} else {
			throw $this->createNotFoundException('No sticker');
		}

	}

	#[Route(path: '/{id}/strip', requirements: ['id' => '\d+'], name: 'core_howto_strip')]
	public function stripAction(Request $request, $id, ImagineController $imagineController, EntityManagerInterface $om, StripableUtils $stripableUtils) {
		$id = intval($id);

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto, true);

		$strip = $howto->getStrip();
		if (is_null($strip)) {
			$strip = $stripableUtils->generateStrip($howto);
			if (!is_null($strip)) {
				$om->flush();
			} else {
				throw $this->createNotFoundException('Error creating strip (core_howto_strip)');
			}
		}

		if (!is_null($strip)) {

			$response = $imagineController->filterAction($request, $strip->getWebPath(), '564w');
			return $response;

		} else {
			throw $this->createNotFoundException('No strip');
		}

	}

	#[Route(path: '/{id}/widget', requirements: ['id' => '\d+'], name: 'core_howto_widget')]
	#[Template('@LadbCore/Howto/Howto/widget-xhr.html.twig')]
	public function widgetAction(Request $request, $id) {

		$id = intval($id);

		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto, true);

		return array(
			'howto' => $howto,
		);
	}

	#[Route(path: '/{filter}', requirements: ['filter' => '[a-z-]+'], name: 'core_howto_list_filter')]
	#[Route(path: '/{filter}/{page}', requirements: ['filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_howto_list_filter_page')]
	public function goneListAction(Request $request, $filter, $page = 0) {
		throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException();
	}

	#[Route(path: '/', name: 'core_howto_list')]
	#[Route(path: '/{page}', requirements: ['page' => '\d+'], name: 'core_howto_list_page')]
	#[Template('@LadbCore/Howto/Howto/list.html.twig')]
	public function listAction(Request $request, EventDispatcherInterface $dispatcher, SearchUtils $searchUtils, TranslatorInterface $translator, $page = 0) {

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_howto_list_page)');
		}

		$layout = $request->get('layout', 'view');

		$routeParameters = array();
		if ($layout != 'view') {
			$routeParameters['layout'] = $layout;
		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils) {
				switch ($facet->name) {

					// Filters /////

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

					case 'author':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'user.displayname', 'user.fullname', 'user.username'  ));
						$filters[] = $filter;

						break;

					case 'kind':

						$filter = new \Elastica\Query\MatchPhrase('kind', $facet->value);
						$filters[] = $filter;

						break;

					case 'license':

						$filter = new \Elastica\Query\Term([ 'license.strippedname' => [ 'value' => $facet->value, 'boost' => 1.0 ] ]);
						$filters[] = $filter;

						break;

					case 'content-questions':

						$filter = new \Elastica\Query\Range('questionCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-creations':

						$filter = new \Elastica\Query\Range('creationCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-plans':

						$filter = new \Elastica\Query\Range('planCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-workshops':

						$filter = new \Elastica\Query\Range('workshopCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-workflows':

						$filter = new \Elastica\Query\Range('workflowCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-providers':

						$filter = new \Elastica\Query\Range('providerCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-schools':

						$filter = new \Elastica\Query\Range('schoolCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-videos':

						$filter = new \Elastica\Query\Range('bodyBlockVideoCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'wip':

						$filter = new \Elastica\Query\Range('isWorkInProgress', array( 'gt' => false ));
						$filters[] = $filter;

						break;

					case 'with-spotlight':

						$filter = new \Elastica\Query\Range('withEnabledSpotlight', array( 'gt' => false ));
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

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'title^300', 'body', 'articles.title^150', 'articles.body', 'tags.label^2' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) {

				$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

			},
			function(&$filters) use ($layout) {

				$this->pushGlobalVisibilityFilter($filters, $layout != 'choice', true);

			},
			'fos_elastica.index.howto_howto',
			\Ladb\CoreBundle\Entity\Howto\Howto::CLASS_NAME,
			'core_howto_list_page',
			$routeParameters
		);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()), PublicationListener::PUBLICATIONS_LISTED);

		$parameters = array_merge($searchParameters, array(
			'howtos'          => $searchParameters['entities'],
			'layout'          => $layout,
			'routeParameters' => $routeParameters,
		));

		if ($request->isXmlHttpRequest()) {
			if ($layout == 'choice') {
				return $this->render('@LadbCore/Howto/Howto/list-choice-xhr.html.twig', $parameters);
			} else {
				return $this->render('@LadbCore/Howto/Howto/list-xhr.html.twig', $parameters);
			}
		}

		if ($layout == 'choice') {
			return $this->render('@LadbCore/Howto/Howto/list-choice.html.twig', $parameters);
		}

		if ($this->isGranted('ROLE_USER') && $this->getUser()->getMeta()->getPrivateHowtoCount() > 0) {

			$draftPath = $this->generateUrl('core_howto_list', array( 'q' => '@mine:draft' ));
			$draftCount = $this->getUser()->getMeta()->getPrivateHowtoCount();

			// Flashbag
			$this->addFlash('info', '<i class="ladb-icon-warning"></i> '.$translator->trans('howto.howto.choice.draft_alert', array( '%count%' => $draftCount )).' <small><a href="'.$draftPath.'" class="alert-link">('.$translator->trans('default.show_my_drafts').')</a></small>');

		}

		return $parameters;
	}

	#[Route(path: '/{id}/creations', requirements: ['id' => '\d+'], name: 'core_howto_creations')]
	#[Route(path: '/{id}/creations/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_howto_creations_filter')]
	#[Route(path: '/{id}/creations/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_howto_creations_filter_page')]
	#[Template('@LadbCore/Howto/Howto/creations.html.twig')]
	public function creationsAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto);

		// Creations

		$creationRepository = $om->getRepository(Creation::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $creationRepository->findPaginedByHowto($howto, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_howto_creations_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'creations'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Wonder/Creation/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'howto' => $howto,
		));
    }

	#[Route(path: '/{id}/ateliers', requirements: ['id' => '\d+'], name: 'core_howto_workshops')]
	#[Route(path: '/{id}/ateliers/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_howto_workshops_filter')]
	#[Route(path: '/{id}/ateliers/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_howto_workshops_filter_page')]
	#[Template('@LadbCore/Howto/Howto/workshops.html.twig')]
	public function workshopsAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto);

		// Workshops

		$workshopRepository = $om->getRepository(Workshop::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workshopRepository->findPaginedByHowto($howto, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_howto_workshops_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'workshops'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Wonder/Workshop/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'howto' => $howto,
		));
	}

	#[Route(path: '/{id}/plans', requirements: ['id' => '\d+'], name: 'core_howto_plans')]
	#[Route(path: '/{id}/plans/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_howto_plans_filter')]
	#[Route(path: '/{id}/plans/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_howto_plans_filter_page')]
	#[Template('@LadbCore/Howto/Howto/plans.html.twig')]
	public function plansAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto);

		// Plans

		$planRepository = $om->getRepository(Plan::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $planRepository->findPaginedByHowto($howto, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_howto_plans_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'plans'       => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Wonder/Plan/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'howto' => $howto,
		));
	}

	#[Route(path: '/{id}/questions', requirements: ['id' => '\d+'], name: 'core_howto_questions')]
	#[Route(path: '/{id}/questions/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_howto_questions_filter')]
	#[Route(path: '/{id}/questions/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_howto_questions_filter_page')]
	#[Template('@LadbCore/Howto/Howto/questions.html.twig')]
	public function questionsAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto);

		// Plans

		$questionRepository = $om->getRepository(Question::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $questionRepository->findPaginedByHowto($howto, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_howto_questions_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'questions'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Wonder/Plan/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'howto' => $howto,
		));
	}

	#[Route(path: '/{id}/processus', requirements: ['id' => '\d+'], name: 'core_howto_workflows')]
	#[Route(path: '/{id}/processus/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_howto_workflows_filter')]
	#[Route(path: '/{id}/processus/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_howto_workflows_filter_page')]
	#[Template('@LadbCore/Howto/Howto/workflows.html.twig')]
	public function workflowsAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto);

		// Workflows

		$workflowRepository = $om->getRepository(Workflow::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workflowRepository->findPaginedByHowto($howto, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_howto_workflows_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'workflows'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Howto/Howto/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'howto' => $howto,
		));
	}

	#[Route(path: '/{id}/fournisseurs', requirements: ['id' => '\d+'], name: 'core_howto_providers')]
	#[Route(path: '/{id}/fournisseurs/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_howto_providers_filter')]
	#[Route(path: '/{id}/fournisseurs/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_howto_providers_filter_page')]
	#[Template('@LadbCore/Howto/Howto/providers.html.twig')]
	public function providersAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto);

		// Providers

		$providerRepository = $om->getRepository(Provider::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $providerRepository->findPaginedByHowto($howto, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_howto_providers_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'providers'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Howto/Howto/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'howto' => $howto,
		));
	}

	#[Route(path: '/{id}/ecoles', requirements: ['id' => '\d+'], name: 'core_howto_schools')]
	#[Route(path: '/{id}/ecoles/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_howto_schools_filter')]
	#[Route(path: '/{id}/ecoles/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_howto_schools_filter_page')]
	#[Template('@LadbCore/Howto/Howto/schools.html.twig')]
	public function schoolsAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$howto = $this->retrievePublication($id, Howto::CLASS_NAME);
		$this->assertShowable($howto);

		// Schools

		$schoolRepository = $om->getRepository(School::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $schoolRepository->findPaginedByHowto($howto, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_howto_schools_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'schools'     => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Howto/Howto/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'howto' => $howto,
		));
	}

	#[Route(path: '/{id}.html', name: 'core_howto_show')]
	#[Template('@LadbCore/Howto/Howto/show.html.twig')]
	public function showAction(Request $request, $id, EventDispatcherInterface $dispatcher, EntityManagerInterface $om, WitnessManager $witnessManager) {
		$howtoRepository = $om->getRepository(Howto::CLASS_NAME);

		$id = intval($id);

		$howto = $howtoRepository->findOneByIdJoinedOnOptimized($id);
        if (is_null($howto)) {
			if ($response = $witnessManager->checkResponse(Howto::TYPE, $id)) {
				return $response;
			}
            throw $this->createNotFoundException('Unable to find Howto entity (id='.$id.').');
        }
		$this->assertShowable($howto);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationEvent($howto), PublicationListener::PUBLICATION_SHOWN);

		return $this->computeShowParameters($howto, $request);
	}

}