<?php

namespace Ladb\CoreBundle\Controller\Knowledge;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Utils\KnowledgeUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Manager\Knowledge\SchoolManager;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Utils\LocalisableUtils;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\PropertyUtils;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Form\Type\Knowledge\NewSchoolType;
use Ladb\CoreBundle\Form\Model\NewSchool;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\KnowledgeEvent;
use Ladb\CoreBundle\Event\KnowledgeListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/ecoles')]
class SchoolController extends AbstractController {

	use PublicationControllerTrait;

	#[Route(path: '/new', name: 'core_school_new')]
	#[Template('@LadbCore/Knowledge/School/new.html.twig')]
	public function newAction(KnowledgeUtils $knowledgeUtils) {

		// Exclude if user is not email confirmed
		if (!$this->getUser()->getEmailConfirmed()) {
			throw $this->createNotFoundException('Not allowed - User email not confirmed (core_school_new)');
		}

		$newSchool = new NewSchool();
		$form = $this->createForm(NewSchoolType::class, $newSchool);

		return array(
			'form' => $form->createView(),
			'sourcesHistory' => $knowledgeUtils->getValueSourcesHistory(),
		);
	}

	#[Route(path: '/create', methods: ['POST'], name: 'core_school_create')]
	#[Template('@LadbCore/Knowledge/School/new.html.twig')]
	public function createAction(Request $request, ActivityUtils $activityUtils, EventDispatcherInterface $dispatcher, EntityManagerInterface $om, TranslatorInterface $translator) {

		// Exclude if user is not email confirmed
		if (!$this->getUser()->getEmailConfirmed()) {
			throw $this->createNotFoundException('Not allowed - User email not confirmed (core_school_new)');
		}

		$this->createLock('core_school_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$newSchool = new NewSchool();
		$form = $this->createForm(NewSchoolType::class, $newSchool);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$nameValue = $newSchool->getNameValue();
			$logoValue = $newSchool->getLogoValue();
			$user = $this->getUser();

			$school = new School();
			$school->setName($nameValue->getData());
			$school->incrementContributorCount();

			$om->persist($school);
			$om->flush();	// Need to save school to be sure ID is generated

			$school->addNameValue($nameValue);
			$school->addLogoValue($logoValue);

			// Dispatch knowledge events
			$dispatcher->dispatch(new KnowledgeEvent($school, array( 'field' => School::FIELD_NAME, 'value' => $nameValue )), KnowledgeListener::FIELD_VALUE_ADDED);
			$dispatcher->dispatch(new KnowledgeEvent($school, array( 'field' => School::FIELD_LOGO, 'value' => $logoValue )), KnowledgeListener::FIELD_VALUE_ADDED);

			$nameValue->setParentEntity($school);
			$nameValue->setParentEntityField(School::FIELD_NAME);
			$nameValue->setUser($user);

			$logoValue->setParentEntity($school);
			$logoValue->setParentEntityField(School::FIELD_LOGO);
			$logoValue->setUser($user);

			$user->getMeta()->incrementProposalCount(2);	// Name and Logo of this new school

			// Create activity
			$activityUtils->createContributeActivity($nameValue, false);
			$activityUtils->createContributeActivity($logoValue, false);

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($school), PublicationListener::PUBLICATION_CREATED);

			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($school), PublicationListener::PUBLICATION_PUBLISHED);

			return $this->redirect($this->generateUrl('core_school_show', array('id' => $school->getSluggedId())));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'newSchool' => $newSchool,
			'form'        => $form->createView(),
			'hideWarning' => true,
		);
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], name: 'core_school_delete')]
	#[IsGranted('ROLE_ADMIN', statusCode: 404, message: 'Not allowed (core_school_delete)')]
	public function deleteAction($id, SchoolManager $schoolManager, TranslatorInterface $translator) {

		$school = $this->retrievePublication($id, School::CLASS_NAME);
		$this->assertDeletable($school);

		// Delete
		$schoolManager->delete($school);

		// Flashbag
		$this->addFlash('success', $translator->trans('knowledge.school.form.alert.delete_success', array( '%title%' => $school->getTitle() )));

		return $this->redirect($this->generateUrl('core_school_list'));
	}

	#[Route(path: '/{id}/location.geojson', name: 'core_school_location', defaults: ['_format' => 'json'])]
	#[Template('@LadbCore/Knowledge/School/location.geojson.twig')]
	public function locationAction(Request $request, $id) {

		$school = $this->retrievePublication($id, School::CLASS_NAME);
		$this->assertShowable($school);

		$features = array();
		if (!is_null($school->getLongitude()) && !is_null($school->getLatitude())) {
			$properties = array(
				'color'   => 'green',
				'cardUrl' => $this->generateUrl('core_school_card', array('id' => $school->getId())),
			);
			$gerometry = new \GeoJson\Geometry\Point($school->getGeoPoint());
			$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
		}

		$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
		$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

		return array(
			'collection' => $collection,
		);
	}

	#[Route(path: '/{id}/widget', requirements: ['id' => '\d+'], name: 'core_school_widget')]
	#[Template('@LadbCore/Knowledge/School/widget-xhr.html.twig')]
	public function widgetAction(Request $request, $id) {

		$school = $this->retrievePublication($id, School::CLASS_NAME);
		$this->assertShowable($school, true);

		return array(
			'school' => $school,
		);
	}

	#[Route(path: '/', name: 'core_school_list')]
	#[Route(path: '/{page}', requirements: ['page' => '\d+'], name: 'core_school_list_page')]
	#[Route(path: '.geojson', defaults: ['_format' => 'json', 'page' => -1, 'layout' => 'geojson'], name: 'core_school_list_geojson')]
	#[Template('@LadbCore/Knowledge/School/list.html.twig')]
	public function listAction(Request $request, EventDispatcherInterface $dispatcher, LocalisableUtils $localisableUtils, SearchUtils $searchUtils, $page = 0, $layout = 'view') {

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_school_list_page)');
		}

		$layout = $request->get('layout', 'view');

		$routeParameters = array();
		if ($layout != 'view') {
			$routeParameters['layout'] = $layout;
		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils, $localisableUtils) {

				switch ($facet->name) {

					// Filters /////

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

					case 'around':

						if (isset($facet->value)) {
							$filter = new \Elastica\Query\GeoDistance('geoPoint', $facet->value, '100km');
							$filters[] = $filter;
						}

						break;

					case 'diplomas':

						$filter = new \Elastica\Query\MatchPhrase('diplomas', $facet->value);
						$filters[] = $filter;

						break;

					case 'training-types':

						$filter = new \Elastica\Query\QueryString('"'.$facet->value.'"');
						$filter->setFields(array( 'trainingTypes' ));
						$filters[] = $filter;

						break;

					case 'with-diploma':

						$filter = new \Elastica\Query\Exists('diplomas');
						$filters[] = $filter;

						break;

					case 'with-testimonial':

						$filter = new \Elastica\Query\Range('testimonialCount', array( 'gt' => 0 ));
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

					case 'content-howtos':

						$filter = new \Elastica\Query\Range('howtoCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'rejected':

						$filter = new \Elastica\Query\Range('nameRejected', array( 'gte' => true ));
						$filters[] = $filter;

						$noGlobalFilters = true;

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

					case 'sort-completion':
						$sort = array( 'completion100' => array( 'order' =>  $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-random':
						$sort = array( 'randomSeed' => isset($facet->value) ? $facet->value : '' );
						break;

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'name^300', 'geographicalAreas^50', 'description' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) {

				$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

			},
			function(&$filters) {

				$filters[] = new \Elastica\Query\Range('nameRejected', array( 'lt' => true ));

			},
			'fos_elastica.index.knowledge_school',
			\Ladb\CoreBundle\Entity\Knowledge\School::CLASS_NAME,
			'core_school_list_page',
			$routeParameters
		);

		$parameters = array_merge($searchParameters, array(
			'schools'         => $searchParameters['entities'],
			'layout'          => $layout,
			'routeParameters' => $routeParameters,
		));

		if ($layout == 'geojson') {

			$features = array();
			foreach ($searchParameters['entities'] as $school) {
				$geoPoint = $school->getGeoPoint();
				if (is_null($geoPoint)) {
					continue;
				}
				$properties = array(
					'color'   => 'green',
					'cardUrl' => $this->generateUrl('core_school_card', array('id' => $school->getId())),
				);
				$gerometry = new \GeoJson\Geometry\Point($geoPoint);
				$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
			}
			$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
			$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

			$parameters = array_merge($parameters, array(
				'collection' => $collection,
			));

			return $this->render('@LadbCore/Knowledge/School/list-xhr.geojson.twig', $parameters);
		}

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()), PublicationListener::PUBLICATIONS_LISTED);

		if ($request->isXmlHttpRequest()) {
			if ($layout == 'choice') {
				return $this->render('@LadbCore/Knowledge/School/list-choice-xhr.html.twig', $parameters);
			} else {
				return $this->render('@LadbCore/Knowledge/School/list-xhr.html.twig', $parameters);
			}
		}

		if ($layout == 'choice') {
			return $this->render('@LadbCore/Knowledge/School/list-choice.html.twig', $parameters);
		}

		return $parameters;
	}

	#[Route(path: '/{id}/creations', requirements: ['id' => '\d+'], name: 'core_school_creations')]
	#[Route(path: '/{id}/creations/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_school_creations_filter')]
	#[Route(path: '/{id}/creations/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_school_creations_filter_page')]
	#[Template('@LadbCore/Knowledge/School/creations.html.twig')]
	public function creationsAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$schoolRepository = $om->getRepository(School::CLASS_NAME);

		$school = $schoolRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($school)) {
			throw $this->createNotFoundException('Unable to find School entity (id='.$id.').');
		}

		// Creations

		$creationRepository = $om->getRepository(Creation::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $creationRepository->findPaginedBySchool($school, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_school_creations_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

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
			'school' => $school,
		));
	}

	#[Route(path: '/{id}/plans', requirements: ['id' => '\d+'], name: 'core_school_plans')]
	#[Route(path: '/{id}/plans/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_school_plans_filter')]
	#[Route(path: '/{id}/plans/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_school_plans_filter_page')]
	#[Template('@LadbCore/Knowledge/School/plans.html.twig')]
	public function plansAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$schoolRepository = $om->getRepository(School::CLASS_NAME);

		$school = $schoolRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($school)) {
			throw $this->createNotFoundException('Unable to find School entity (id='.$id.').');
		}

		// Plans

		$planRepository = $om->getRepository(Plan::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $planRepository->findPaginedBySchool($school, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_school_plans_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

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
			'school' => $school,
		));
	}

	#[Route(path: '/{id}/pas-a-pas', requirements: ['id' => '\d+'], name: 'core_school_howtos')]
	#[Route(path: '/{id}/pas-a-pas/{filter}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+'], name: 'core_school_howtos_filter')]
	#[Route(path: '/{id}/pas-a-pas/{filter}/{page}', requirements: ['id' => '\d+', 'filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_school_howtos_filter_page')]
	#[Template('@LadbCore/Knowledge/School/howtos.html.twig')]
	public function howtosAction(Request $request, $id, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$schoolRepository = $om->getRepository(School::CLASS_NAME);

		$school = $schoolRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($school)) {
			throw $this->createNotFoundException('Unable to find School entity (id='.$id.').');
		}

		// Howtos

		$howtoRepository = $om->getRepository(Howto::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $howtoRepository->findPaginedBySchool($school, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_school_howtos_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'howtos'      => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Howto/Howto/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'school' => $school,
		));
	}

	#[Route(path: '/{id}/card.xhr', name: 'core_school_card')]
	#[Template('@LadbCore/Knowledge/School/card-xhr.html.twig')]
	public function cardAction(Request $request, $id, EntityManagerInterface $om) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_school_card)');
		}

		$schoolRepository = $om->getRepository(School::CLASS_NAME);

		$id = intval($id);

		$school = $schoolRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($school)) {
			throw $this->createNotFoundException('Unable to find School entity (id='.$id.').');
		}

		return array(
			'school' => $school,
		);
	}

	#[Route(path: '/{id}.html', name: 'core_school_show')]
	#[Template('@LadbCore/Knowledge/School/show.html.twig')]
	public function showAction(Request $request, $id, CollectionnableUtils $collectionnableUtils, CommentableUtils $commentableUtils, EventDispatcherInterface $dispatcher, LikableUtils $likableUtils, EntityManagerInterface $om, WatchableUtils $watchableUtils, WitnessManager $witnessManager) {
		$schoolRepository = $om->getRepository(School::CLASS_NAME);

		$id = intval($id);

		$school = $schoolRepository->findOneById($id);
		if (is_null($school)) {
			if ($response = $witnessManager->checkResponse(School::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find School entity (id='.$id.').');
		}

		$user = $this->getUser();
		$userTestimonial = null;
		if ($this->isGranted('ROLE_USER')) {
			foreach ($school->getTestimonials() as $testimonial) {
				if ($testimonial->getUser()->getId() == $user->getId()) {
					$userTestimonial = $testimonial;
					break;
				}
			}
		}

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationEvent($school), PublicationListener::PUBLICATION_SHOWN);

		return array(
			'school'            => $school,
			'permissionContext' => $this->getPermissionContext($school),
			'likeContext'       => $likableUtils->getLikeContext($school, $this->getUser()),
			'watchContext'      => $watchableUtils->getWatchContext($school, $this->getUser()),
			'commentContext'    => $commentableUtils->getCommentContext($school),
			'collectionContext' => $collectionnableUtils->getCollectionContext($school),
			'hasMap'            => !is_null($school->getLatitude()) && !is_null($school->getLongitude()),
			'userTestimonial'   => $userTestimonial,
		);
	}

}
