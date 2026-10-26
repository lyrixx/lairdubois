<?php

namespace Ladb\CoreBundle\Controller\Wonder;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\CustomOwnerControllerTrait;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Utils\FeedbackableUtils;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Form\Type\Wonder\WorkshopType;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\ExplorableUtils;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;
use Ladb\CoreBundle\Utils\PicturedUtils;
use Ladb\CoreBundle\Utils\EmbeddableUtils;
use Ladb\CoreBundle\Utils\StripableUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Manager\Wonder\WorkshopManager;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Utils\LocalisableUtils;
use Liip\ImagineBundle\Controller\ImagineController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * @Route("/ateliers")
 */
class WorkshopController extends AbstractController {

	use PublicationControllerTrait;

	/**
	 * @Route("/new", name="core_workshop_new")
	 * @Template("LadbCoreBundle:Wonder/Workshop:new.html.twig")
	 */
	public function newAction(Request $request, TagUtils $tagUtils) {

		$workshop = new Workshop();
		$workshop->addBodyBlock(new \Ladb\CoreBundle\Entity\Core\Block\Text());	// Add a default Text body block
		$form = $this->createForm(WorkshopType::class, $workshop);

		return array(
			'form'         => $form->createView(),
			'owner'        => $this->retrieveOwner($request),
			'tagProposals' => $tagUtils->getProposals($workshop),
		);
	}

	/**
	 * @Route("/create", methods={"POST"}, name="core_workshop_create")
	 * @Template("LadbCoreBundle:Wonder/Workshop:new.html.twig")
	 */
	public function createAction(Request $request, BlockBodiedUtils $blockUtils, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, TagUtils $tagUtils, TranslatorInterface $translator) {

		$owner = $this->retrieveOwner($request);

		$this->createLock('core_workshop_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$om = $this->getDoctrine()->getManager();

		$workshop = new Workshop();
		$form = $this->createForm(WorkshopType::class, $workshop);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$blockUtils->preprocessBlocks($workshop);

			$fieldPreprocessorUtils->preprocessFields($workshop);

			$workshop->setUser($owner);
			$workshop->setMainPicture($workshop->getPictures()->first());
			$owner->getMeta()->incrementPrivateWorkshopCount();

			$om->persist($workshop);
			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(PublicationListener::PUBLICATION_CREATED, new PublicationEvent($workshop));

			return $this->redirect($this->generateUrl('core_workshop_show', array('id' => $workshop->getSluggedId())));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'workshop'     => $workshop,
			'form'         => $form->createView(),
			'owner'        => $owner,
			'tagProposals' => $tagUtils->getProposals($workshop),
			'hideWarning'  => true,
		);
	}

	/**
	 * @Route("/{id}/lock", requirements={"id" = "\d+"}, defaults={"lock" = true}, name="core_workshop_lock")
	 * @Route("/{id}/unlock", requirements={"id" = "\d+"}, defaults={"lock" = false}, name="core_workshop_unlock")
	 */
	public function lockUnlockAction($id, $lock, TranslatorInterface $translator, WorkshopManager $workshopManager) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertLockUnlockable($workshop, $lock);

		// Lock or Unlock
		if ($lock) {
			$workshopManager->lock($workshop);
		} else {
			$workshopManager->unlock($workshop);
		}

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.creation.form.alert.'.($lock ? 'lock' : 'unlock').'_success', array( '%title%' => $workshop->getTitle() )));

		return $this->redirect($this->generateUrl('core_workshop_show', array( 'id' => $workshop->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/publish", requirements={"id" = "\d+"}, name="core_workshop_publish")
	 */
	public function publishAction($id, TranslatorInterface $translator, WorkshopManager $workshopManager) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertPublishable($workshop);

		// Publish
		$workshopManager->publish($workshop);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.workshop.form.alert.publish_success', array( '%title%' => $workshop->getTitle() )));

		return $this->redirect($this->generateUrl('core_workshop_show', array( 'id' => $workshop->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/unpublish", requirements={"id" = "\d+"}, name="core_workshop_unpublish")
	 */
	public function unpublishAction(Request $request, $id, TranslatorInterface $translator, WorkshopManager $workshopManager) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertUnpublishable($workshop);

		// Unpublish
		$workshopManager->unpublish($workshop);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.workshop.form.alert.unpublish_success', array( '%title%' => $workshop->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $this->getRefererUrl($request);
		}

		return $this->redirect($returnToUrl);
	}

	/**
	 * @Route("/{id}/edit", requirements={"id" = "\d+"}, name="core_workshop_edit")
	 * @Template("LadbCoreBundle:Wonder/Workshop:edit.html.twig")
	 */
	public function editAction($id, TagUtils $tagUtils) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertEditabable($workshop);

		$form = $this->createForm(WorkshopType::class, $workshop);

		return array(
			'workshop'     => $workshop,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($workshop),
		);
	}

	/**
	 * @Route("/{id}/update", requirements={"id" = "\d+"}, methods={"POST"}, name="core_workshop_update")
	 * @Template("LadbCoreBundle:Wonder/Workshop:edit.html.twig")
	 */
	public function updateAction(Request $request, $id, BlockBodiedUtils $blockUtils, EventDispatcherInterface $dispatcher, EmbeddableUtils $embaddableUtils, FieldPreprocessorUtils $fieldPreprocessorUtils, PicturedUtils $picturedUtils, StripableUtils $stripableUtils, TagUtils $tagUtils, TranslatorInterface $translator) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertEditabable($workshop);

		$originalBodyBlocks = $workshop->getBodyBlocks()->toArray();	// Need to be an array to copy values
		$previouslyUsedTags = $workshop->getTags()->toArray();	// Need to be an array to copy values

		$picturedUtils->resetPictures($workshop); // Reset pictures array to consider form pictures order

		$workshop->resetBodyBlocks(); // Reset bodyBlocks array to consider form bodyBlocks order

		$form = $this->createForm(WorkshopType::class, $workshop);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$blockUtils->preprocessBlocks($workshop, $originalBodyBlocks);

			$fieldPreprocessorUtils->preprocessFields($workshop);

			$embaddableUtils->resetSticker($workshop);

			$stripableUtils->resetStrip($workshop);

			$workshop->setMainPicture($workshop->getPictures()->first());
			if ($workshop->getUser() == $this->getUser()) {
				$workshop->setUpdatedAt(new \DateTime());
			}

			$om = $this->getDoctrine()->getManager();
			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(PublicationListener::PUBLICATION_UPDATED, new PublicationEvent($workshop, array( 'previouslyUsedTags' => $previouslyUsedTags )));

			// Flashbag
			$this->addFlash('success', $translator->trans('wonder.workshop.form.alert.update_success', array( '%title%' => $workshop->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(WorkshopType::class, $workshop);

		} else {

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'workshop'     => $workshop,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($workshop),
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_workshop_delete")
	 */
	public function deleteAction($id, TranslatorInterface $translator, WorkshopManager $workshopManager) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertDeletable($workshop);

		// Delete
		$workshopManager->delete($workshop);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.workshop.form.alert.delete_success', array( '%title%' => $workshop->getTitle() )));

		return $this->redirect($this->generateUrl('core_workshop_list'));
	}

	/**
	 * @Route("/{id}/chown", requirements={"id" = "\d+"}, name="core_workshop_chown")
	 */
	public function chownAction(Request $request, $id, TranslatorInterface $translator, WorkshopManager $workshopManager) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertChownable($workshop);

		$targetUser = $this->retrieveOwner($request);

		// Change owner
		$workshopManager->changeOwner($workshop, $targetUser);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.workshop.form.alert.chown_success', array( '%title%' => $workshop->getTitle() )));

		return $this->redirect($this->generateUrl('core_workshop_show', array( 'id' => $workshop->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/plans", requirements={"id" = "\d+"}, name="core_workshop_plans")
	 * @Route("/{id}/plans/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workshop_plans_filter")
	 * @Route("/{id}/plans/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workshop_plans_filter_page")
	 * @Template("LadbCoreBundle:Wonder/Workshop:plans.html.twig")
	 */
	public function plansAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertShowable($workshop);

		// Plans

		$planRepository = $om->getRepository(Plan::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $planRepository->findPaginedByWorkshop($workshop, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workshop_plans_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'plans'       => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Plan:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workshop' => $workshop,
		));
	}

	/**
	 * @Route("/{id}/projets", requirements={"id" = "\d+"}, name="core_workshop_projects")
	 */
	public function projectsAction($id) {
		return $this->redirect($this->generateUrl('core_workshop_howtos', array( 'id' => $id )));
	}

	/**
	 * @Route("/{id}/pas-a-pas", requirements={"id" = "\d+"}, name="core_workshop_howtos")
	 * @Route("/{id}/pas-a-pas/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workshop_howtos_filter")
	 * @Route("/{id}/pas-a-pas/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workshop_howtos_filter_page")
	 * @Template("LadbCoreBundle:Wonder/Workshop:howtos.html.twig")
	 */
	public function howtosAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertShowable($workshop);

		// Howtos

		$howtoRepository = $om->getRepository(Howto::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $howtoRepository->findPaginedByWorkshop($workshop, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workshop_howtos_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'howtos'      => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Plan:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workshop' => $workshop,
		));
	}

	/**
	 * @Route("/{id}/processus", requirements={"id" = "\d+"}, name="core_workshop_workflows")
	 * @Route("/{id}/processus/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workshop_workflows_filter")
	 * @Route("/{id}/processus/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workshop_workflows_filter_page")
	 * @Template("LadbCoreBundle:Wonder/Workshop:workflows.html.twig")
	 */
	public function workflowsAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertShowable($workshop);

		// Howtos

		$workflowRepository = $om->getRepository(Workflow::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workflowRepository->findPaginedByWorkshop($workshop, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workshop_workflows_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'workflows'      => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Plan:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workshop' => $workshop,
		));
	}

	/**
	 * @Route("/{id}/sticker.png", requirements={"id" = "\d+"}, name="core_workshop_sticker_bc")
	 */
	public function bcStickerAction(Request $request, $id) {
		return $this->redirect($this->generateUrl('core_workshop_sticker', array( 'id' => $id )));
	}

	/**
	 * @Route("/{id}/sticker", requirements={"id" = "\d+"}, name="core_workshop_sticker")
	 */
	public function stickerAction(Request $request, $id, EmbeddableUtils $embeddableUtils, ImagineController $imagineController) {
		$om = $this->getDoctrine()->getManager();

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertShowable($workshop, true);

		$sticker = $workshop->getSticker();
		if (is_null($sticker)) {
			$sticker = $embeddableUtils->generateSticker($workshop);
			if (!is_null($sticker)) {
				$om->flush();
			} else {
				throw $this->createNotFoundException('Error creating sticker (core_workshop_sticker)');
			}
		}

		if (!is_null($sticker)) {

			$response = $imagineController->filterAction($request, $sticker->getWebPath(), '598w');
			return $response;

		} else {
			throw $this->createNotFoundException('No sticker');
		}

	}

	/**
	 * @Route("/{id}/strip", requirements={"id" = "\d+"}, name="core_workshop_strip")
	 */
	public function stripAction(Request $request, $id, ImagineController $imagineController, StripableUtils $stripableUtils) {
		$om = $this->getDoctrine()->getManager();

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertShowable($workshop, true);

		$strip = $workshop->getStrip();
		if (is_null($strip)) {
			$strip = $stripableUtils->generateStrip($workshop);
			if (!is_null($strip)) {
				$om->flush();
			} else {
				throw $this->createNotFoundException('Error creating strip (core_workshop_strip)');
			}
		}

		if (!is_null($strip)) {

			$response = $imagineController->filterAction($request, $strip->getWebPath(), '564w');
			return $response;

		} else {
			throw $this->createNotFoundException('No strip');
		}

	}

	/**
	 * @Route("/{id}/widget", requirements={"id" = "\d+"}, name="core_workshop_widget")
	 * @Template("LadbCoreBundle:Wonder/Workshop:widget-xhr.html.twig")
	 */
	public function widgetAction($id) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertShowable($workshop, true);

		return array(
			'workshop' => $workshop,
		);
	}

	/**
	 * @Route("/{id}/location.geojson", name="core_workshop_location", defaults={"_format" = "json"})
	 * @Template("LadbCoreBundle:Wonder/Workshop:location.geojson.twig")
	 */
	public function locationAction($id) {

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertShowable($workshop);

		$features = array();
		if (!is_null($workshop->getLongitude()) && !is_null($workshop->getLatitude())) {
			$properties = array(
				'color'   => 'orange',
				'cardUrl' => $this->generateUrl('core_workshop_card', array('id' => $workshop->getId())),
			);
			$gerometry = new \GeoJson\Geometry\Point($workshop->getGeoPoint());
			$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
		}

		$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
		$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

		return array(
			'collection' => $collection,
		);
	}

	/**
	 * @Route("/{id}/card.xhr", name="core_workshop_card")
	 * @Template("LadbCoreBundle:Wonder/Workshop:card-xhr.html.twig")
	 */
	public function cardAction(Request $request, $id) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_workshop_card)');
		}

		$workshop = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertShowable($workshop);

		return array(
			'workshop' => $workshop,
		);
	}

	/**
	 * @Route("/{filter}", requirements={"filter" = "[a-z-]+"}, name="core_workshop_list_filter")
	 * @Route("/{filter}/{page}", requirements={"filter" = "[a-z-]+", "page" = "\d+"}, name="core_workshop_list_filter_page")
	 */
	public function goneListAction(Request $request, $filter, $page = 0) {
		throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException();
	}

	/**
	 * @Route("/", name="core_workshop_list")
	 * @Route("/{page}", requirements={"page" = "\d+"}, name="core_workshop_list_page")
	 * @Route(".geojson", defaults={"_format" = "json", "page"=-1, "layout"="geojson"}, name="core_workshop_list_geojson")
	 * @Template("LadbCoreBundle:Wonder/Workshop:list.html.twig")
	 */
	public function listAction(Request $request, EventDispatcherInterface $dispatcher, LocalisableUtils $localisableUtils, SearchUtils $searchUtils, TranslatorInterface $translator, $page = 0, $layout = 'view') {

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_workshop_list_page)');
		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils, $localisableUtils) {
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

							$filters[] = new \Elastica\Query\Range('changedAt', array( 'gte' => 'now-7d/d' ));

						} elseif ($facet->value == 'last30days') {

							$filters[] = new \Elastica\Query\Range('changedAt', array( 'gte' => 'now-30d/d' ));

						}

						break;

					case 'tag':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'tags.label' ));
						$filters[] = $filter;

						break;

					case 'author':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'user.displayname', 'user.fullname', 'user.username'  ));
						$filters[] = $filter;

						break;

					case 'license':

						$filter = new \Elastica\Query\Term([ 'license.strippedname' => [ 'value' => $facet->value, 'boost' => 1.0 ] ]);
						$filters[] = $filter;

						break;

					case 'content-plans':

						$filter = new \Elastica\Query\Range('planCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-howtos':

						$filter = new \Elastica\Query\Range('howtoCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-workflows':

						$filter = new \Elastica\Query\Range('workflowCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-videos':

						$filter = new \Elastica\Query\Range('bodyBlockVideoCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'with-feedback':

						$filter = new \Elastica\Query\Range('feedbackCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'area-lte':

						$filter = new \Elastica\Query\Range('area', array( 'lte' => intval($facet->value) ));
						$filters[] = $filter;

						break;

					case 'area-gte':

						$filter = new \Elastica\Query\Range('area', array( 'gte' => intval($facet->value) ));
						$filters[] = $filter;

						break;

					case 'around':

						if (isset($facet->value)) {
							$filter = new \Elastica\Query\GeoDistance('geoPoint', $facet->value, '100km');
							$filters[] = $filter;
						}

						break;

					case 'geocoded':

						$filter = new \Elastica\Query\Exists('geoPoint');
						$filters[] = $filter;

						break;

					case 'location':

						$boundsAndLocation = $localisableUtils->getBoundsAndLocation($facet->value);

						if (!is_null($boundsAndLocation)) {
							$filter = new \Elastica\Query\BoolQuery();
							if (isset($boundsAndLocation['bounds'])) {
								$geoQuery = new \Elastica\Query\GeoBoundingBox('geoPoint', $boundsAndLocation['bounds']);
								$filter->addShould($geoQuery);
							}
							if (isset($boundsAndLocation['location'])) {
								$geoQuery = new \Elastica\Query\GeoDistance('geoPoint', $boundsAndLocation['location'], '20km');
								$filter->addShould($geoQuery);
							}
							$filters[] = $filter;
						}

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

					case 'sort-largest':
						$sort = array( 'area' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'title^100', 'body', 'tags.label' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) {

				$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

			},
			function(&$filters) {

				$this->pushGlobalVisibilityFilter($filters, true, true);

			},
			'fos_elastica.index.ladb.wonder_workshop',
			\Ladb\CoreBundle\Entity\Wonder\Workshop::CLASS_NAME,
			'core_workshop_list_page'
		);

		$parameters = array_merge($searchParameters, array(
			'workshops' => $searchParameters['entities'],
		));

		if ($layout == 'geojson') {

			$features = array();
			foreach ($searchParameters['entities'] as $workshop) {
				$geoPoint = $workshop->getGeoPoint();
				if (is_null($geoPoint)) {
					continue;
				}
				$properties = array(
					'color'   => 'orange',
					'cardUrl' => $this->generateUrl('core_workshop_card', array('id' => $workshop->getId())),
				);
				$gerometry = new \GeoJson\Geometry\Point($geoPoint);
				$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
			}
			$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
			$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

			$parameters = array_merge($parameters, array(
				'collection' => $collection,
			));

			return $this->render('LadbCoreBundle:Wonder/Workshop:list-xhr.geojson.twig', $parameters);
		}

		// Dispatch publication event
		$dispatcher->dispatch(PublicationListener::PUBLICATIONS_LISTED, new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()));

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Workshop:list-xhr.html.twig', $parameters);
		}

		if ($this->isGranted('ROLE_USER') && $this->getUser()->getMeta()->getPrivateWorkshopCount() > 0) {

			$draftPath = $this->generateUrl('core_workshop_list', array( 'q' => '@mine:draft' ));
			$draftCount = $this->getUser()->getMeta()->getPrivateWorkshopCount();

			// Flashbag
			$this->addFlash('info', '<i class="ladb-icon-warning"></i> '.$translator->transchoice('wonder.workshop.choice.draft_alert', $draftCount, array( '%count%' => $draftCount )).' <small><a href="'.$draftPath.'" class="alert-link">('.$translator->trans('default.show_my_drafts').')</a></small>');

		}

		return $parameters;
	}

	/**
	 * @Route("/{id}.html", name="core_workshop_show")
	 * @Template("LadbCoreBundle:Wonder/Workshop:show.html.twig")
	 */
	public function showAction(Request $request, $id, CollectionnableUtils $collectionnableUtils, CommentableUtils $commentableUtils, EventDispatcherInterface $dispatcher, EmbeddableUtils $embaddableUtils, ExplorableUtils $explorableUtils, FeedbackableUtils $feedbackableUtils, FollowerUtils $followerUtils, LikableUtils $likableUtils, WatchableUtils $watchableUtils, WitnessManager $witnessManager) {
		$om = $this->getDoctrine()->getManager();
		$workshopRepository = $om->getRepository(Workshop::CLASS_NAME);

		$id = intval($id);

		$workshop = $workshopRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($workshop)) {
			if ($response = $witnessManager->checkResponse(Workshop::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Workshop entity (id='.$id.').');
		}
		$this->assertShowable($workshop);

		$referral = $embaddableUtils->processReferer($workshop, $request);

		// Dispatch publication event
		$dispatcher->dispatch(PublicationListener::PUBLICATION_SHOWN, new PublicationEvent($workshop));

		$userWorkshops = $explorableUtils->getPreviousAndNextPublishedUserExplorables($workshop, $workshopRepository, $workshop->getUser()->getMeta()->getPublicWorkshopCount());
		$similarWorkshops = $explorableUtils->getSimilarExplorables($workshop, 'fos_elastica.index.ladb.wonder_workshop', Workshop::CLASS_NAME, $userWorkshops);

		return array(
			'workshop'          => $workshop,
			'permissionContext' => $this->getPermissionContext($workshop),
			'userWorkshops'     => $userWorkshops,
			'similarWorkshops'  => $similarWorkshops,
			'likeContext'       => $likableUtils->getLikeContext($workshop, $this->getUser()),
			'watchContext'      => $watchableUtils->getWatchContext($workshop, $this->getUser()),
			'feedbackContext'   => $feedbackableUtils->getFeedbackContext($workshop),
			'commentContext'    => $commentableUtils->getCommentContext($workshop),
			'collectionContext' => $collectionnableUtils->getCollectionContext($workshop),
			'followerContext'   => $followerUtils->getFollowerContext($workshop->getUser(), $this->getUser()),
			'referral'          => $referral,
			'hasMap'            => !is_null($workshop->getLatitude()) && !is_null($workshop->getLongitude()),
		);
	}

	/**
	 * @Route("/{id}/admin/converttocreation", requirements={"id" = "\d+"}, name="core_workshop_admin_converttocreation")
	 * @Security("is_granted('ROLE_ADMIN')", statusCode=404, message="Not allowed (core_workshop_admin_converttocreation)")
	 */
	public function adminConvertToCreationAction($id, TranslatorInterface $translator, WorkshopManager $workshopManager) {
		$om = $this->getDoctrine()->getManager();
		$workshopRepository = $om->getRepository(Workshop::CLASS_NAME);

		$workshop = $workshopRepository->findOneById($id);
		if (is_null($workshop)) {
			throw $this->createNotFoundException('Unable to find Workshop entity (id='.$id.').');
		}

		// Convert
		$creation = $workshopManager->convertToCreation($workshop);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.workshop.admin.alert.converttocreation_success', array( '%title%' => $workshop->getTitle() )));

		return $this->redirect($this->generateUrl('core_creation_show', array( 'id' => $creation->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/admin/converttohowto", requirements={"id" = "\d+"}, name="core_workshop_admin_converttohowto")
	 * @Security("is_granted('ROLE_ADMIN')", statusCode=404, message="Not allowed (core_workshop_admin_converttohowto)")
	 */
	public function adminConvertToHowtoAction($id, TranslatorInterface $translator, WorkshopManager $workshopManager) {
		$om = $this->getDoctrine()->getManager();
		$workshopRepository = $om->getRepository(Workshop::CLASS_NAME);

		$workshop = $workshopRepository->findOneById($id);
		if (is_null($workshop)) {
			throw $this->createNotFoundException('Unable to find Workshop entity (id='.$id.').');
		}

		// Convert
		$howto = $workshopManager->convertToHowto($workshop);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.workshop.admin.alert.converttohowto_success', array( '%title%' => $workshop->getTitle() )));

		return $this->redirect($this->generateUrl('core_howto_show', array( 'id' => $howto->getSluggedId() )));
	}

}
