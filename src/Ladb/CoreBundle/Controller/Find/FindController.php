<?php

namespace Ladb\CoreBundle\Controller\Find;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Utils\LocalisableUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Form\Type\Find\FindType;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Find\Content\Gallery;
use Ladb\CoreBundle\Model\LocalisableInterface;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\PicturedUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\ExplorableUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Manager\Find\FindManager;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;
use Ladb\CoreBundle\Utils\FindUtils;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/trouvailles')]
class FindController extends AbstractController {

	use PublicationControllerTrait;

	/**
	 * @Template("@LadbCore/Find/Find/new.html.twig")
	 */
	#[Route(path: '/new', name: 'core_find_new')]
	public function newAction(Request $request, TagUtils $tagUtils) {

		$find = new Find();
		$find->addBodyBlock(new \Ladb\CoreBundle\Entity\Core\Block\Text());	// Add a default Text body block
		$form = $this->createForm(FindType::class, $find);

		return array(
			'form'         => $form->createView(),
			'owner'        => $this->retrieveOwner($request),
			'tagProposals' => $tagUtils->getProposals($find),
		);
	}

	/**
	 * @Template("@LadbCore/Find/Find/new.html.twig")
	 */
	#[Route(path: '/create', methods: ['POST'], name: 'core_find_create')]
	public function createAction(Request $request, BlockBodiedUtils $blockUtils, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, FindUtils $findUtils, EntityManagerInterface $om, TagUtils $tagUtils, TranslatorInterface $translator) {

		$owner = $this->retrieveOwner($request);

		$this->createLock('core_find_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$find = new Find();
		$form = $this->createForm(FindType::class, $find);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$blockUtils->preprocessBlocks($find);

			$fieldPreprocessorUtils->preprocessFields($find);

			$findUtils->generateMainPicture($find);

			$find->setUser($owner);
			$owner->getMeta()->incrementPrivateFindCount();

			$om->persist($find);
			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($find), PublicationListener::PUBLICATION_CREATED);

			return $this->redirect($this->generateUrl('core_find_show', array('id' => $find->getSluggedId())));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'find'         => $find,
			'form'         => $form->createView(),
			'owner'        => $owner,
			'tagProposals' => $tagUtils->getProposals($find),
			'hideWarning'  => true,
		);
	}

	#[Route(path: '/{id}/lock', requirements: ['id' => '\d+'], defaults: ['lock' => true], name: 'core_find_lock')]
	#[Route(path: '/{id}/unlock', requirements: ['id' => '\d+'], defaults: ['lock' => false], name: 'core_find_unlock')]
	public function lockUnlockAction($id, $lock, FindManager $findManager, TranslatorInterface $translator) {

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertLockUnlockable($find, $lock);

		// Lock or Unlock
		if ($lock) {
			$findManager->lock($find);
		} else {
			$findManager->unlock($find);
		}

		// Flashbag
		$this->addFlash('success', $translator->trans('find.find.form.alert.'.($lock ? 'lock' : 'unlock').'_success', array( '%title%' => $find->getTitle() )));

		return $this->redirect($this->generateUrl('core_find_show', array( 'id' => $find->getSluggedId() )));
	}

	#[Route(path: '/{id}/publish', requirements: ['id' => '\d+'], name: 'core_find_publish')]
	public function publishAction($id, FindManager $findManager, TranslatorInterface $translator) {

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertPublishable($find);

		// Publish
		$findManager->publish($find);

		// Flashbag
		$this->addFlash('success', $translator->trans('find.find.form.alert.publish_success', array( '%title%' => $find->getTitle() )));

		return $this->redirect($this->generateUrl('core_find_show', array( 'id' => $find->getSluggedId() )));
	}

	#[Route(path: '/{id}/unpublish', requirements: ['id' => '\d+'], name: 'core_find_unpublish')]
	public function unpublishAction(Request $request, $id, FindManager $findManager, TranslatorInterface $translator) {

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertUnpublishable($find);

		// Unpublish
		$findManager->unpublish($find);

		// Flashbag
		$this->addFlash('success', $translator->trans('find.find.form.alert.unpublish_success', array( '%title%' => $find->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $this->getRefererUrl($request);
		}

		return $this->redirect($returnToUrl);
	}

	/**
	 * @Template("@LadbCore/Find/Find/edit.html.twig")
	 */
	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'], name: 'core_find_edit')]
	public function editAction($id, TagUtils $tagUtils) {

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertEditabable($find);

		$form = $this->createForm(FindType::class, $find);

		return array(
			'find'         => $find,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($find),
		);
	}

	/**
	 * @Template("@LadbCore/Find/Find/edit.html.twig")
	 */
	#[Route(path: '/{id}/update', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_find_update')]
	public function updateAction(Request $request, $id, BlockBodiedUtils $blockUtils, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, FindUtils $findUtils, EntityManagerInterface $om, PicturedUtils $picturedUtils, TagUtils $tagUtils, TranslatorInterface $translator) {

		$doUp = $request->get('ladb_do_up', false) && $this->isGranted('ROLE_ADMIN');

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertEditabable($find);

		if ($find->getContent() instanceof Gallery) {
			$picturedUtils->resetPictures($find->getContent()); // Reset pictures array to consider form pictures order
		}

		$originalBodyBlocks = $find->getBodyBlocks()->toArray();	// Need to be an array to copy values
		$previouslyUsedTags = $find->getTags()->toArray();	// Need to be an array to copy values

		$form = $this->createForm(FindType::class, $find);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$blockUtils->preprocessBlocks($find, $originalBodyBlocks);

			$fieldPreprocessorUtils->preprocessFields($find);

			$findUtils->generateMainPicture($find);

			if ($doUp) {
				$find->setChangedAt(new \DateTime());
			}
			if ($find->getUser() == $this->getUser()) {
				$find->setUpdatedAt(new \DateTime());
			}

			$om->flush();

			// Dispatch publication event
			if ($doUp) {
				$dispatcher->dispatch(new PublicationEvent($find), PublicationListener::PUBLICATION_CHANGED);
			}
			$dispatcher->dispatch(new PublicationEvent($find, array( 'previouslyUsedTags' => $previouslyUsedTags )), PublicationListener::PUBLICATION_UPDATED);

			// Flashbag
			$this->addFlash('success', $translator->trans('find.find.form.alert.update_success', array( '%title%' => $find->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(FindType::class, $find);

		} else {

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'find'         => $find,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($find),
		);
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], name: 'core_find_delete')]
	public function deleteAction($id, FindManager $findManager, TranslatorInterface $translator) {

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertDeletable($find);

		// Delete
		$findManager->delete($find);

		// Flashbag
		$this->addFlash('success', $translator->trans('find.find.form.alert.delete_success', array( '%title%' => $find->getTitle() )));

		return $this->redirect($this->generateUrl('core_find_list'));
	}

	#[Route(path: '/{id}/chown', requirements: ['id' => '\d+'], name: 'core_find_chown')]
	public function chownAction(Request $request, $id, FindManager $findManager, TranslatorInterface $translator) {

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertChownable($find);

		$targetUser = $this->retrieveOwner($request);

		// Change owner
		$findManager->changeOwner($find, $targetUser);

		// Flashbag
		$this->addFlash('success', $translator->trans('find.find.form.alert.chown_success', array( '%title%' => $find->getTitle() )));

		return $this->redirect($this->generateUrl('core_find_show', array( 'id' => $find->getSluggedId() )));
	}

	/**
	 * @Template("@LadbCore/Find/Find/widget-xhr.html.twig")
	 */
	#[Route(path: '/{id}/widget', requirements: ['id' => '\d+'], name: 'core_find_widget')]
	public function widgetAction($id) {

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertShowable($find, true);

		return array(
			'find' => $find,
		);
	}

	/**
	 * @Template("@LadbCore/Find/Find/location.geojson.twig")
	 */
	#[Route(path: '/{id}/location.geojson', name: 'core_find_location', defaults: ['_format' => 'json'])]
	public function locationAction($id) {

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertShowable($find);

		$features = array();
		$content = $find->getContent();
		if (!is_null($content->getLongitude()) && !is_null($content->getLatitude())) {
			$properties = array(
				'color' => 'orange',
			);
			$gerometry = new \GeoJson\Geometry\Point($content->getGeoPoint());
			$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
		}

		$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
		$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

		return array(
			'collection' => $collection,
		);
	}

	/**
	 * @Template("@LadbCore/Find/Find/card-xhr.html.twig")
	 */
	#[Route(path: '/{id}/card.xhr', name: 'core_find_card')]
	public function cardAction(Request $request, $id) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_find_card-');
		}

		$find = $this->retrievePublication($id, Find::CLASS_NAME);
		$this->assertShowable($find);

		return array(
			'find' => $find,
		);
	}

	#[Route(path: '/{filter}', requirements: ['filter' => '[a-z-]+'], name: 'core_find_list_filter')]
	#[Route(path: '/{filter}/{page}', requirements: ['filter' => '[a-z-]+', 'page' => '\d+'], name: 'core_find_list_filter_page')]
	public function goneListAction(Request $request, $filter, $page = 0) {
		throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException();
	}

	/**
	 * @Template("@LadbCore/Find/Find/list.html.twig")
	 */
	#[Route(path: '/', name: 'core_find_list')]
	#[Route(path: '/{page}', requirements: ['page' => '\d+'], name: 'core_find_list_page')]
	#[Route(path: '.geojson', defaults: ['_format' => 'json', 'page' => -1, 'layout' => 'geojson'], name: 'core_find_list_geojson')]
	public function listAction(Request $request, EventDispatcherInterface $dispatcher, LocalisableUtils $localisableUtils, SearchUtils $searchUtils, TranslatorInterface $translator, $page = 0, $layout = 'view') {

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_find_list_page)');
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

							$filters[] = new \Elastica\Query\Range('createdAt', array( 'gte' => 'now-7d/d' ));

						} elseif ($facet->value == 'last30days') {

							$filters[] = new \Elastica\Query\Range('createdAt', array( 'gte' => 'now-30d/d' ));

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

					case 'kind':

						$filter = new \Elastica\Query\MatchPhrase('kind', $facet->value);
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
			'fos_elastica.index.find_find',
			\Ladb\CoreBundle\Entity\Find\Find::CLASS_NAME,
			'core_find_list_page'
		);

		$parameters = array_merge($searchParameters, array(
			'finds' => $searchParameters['entities'],
		));

		if ($layout == 'geojson') {

			$features = array();
			foreach ($searchParameters['entities'] as $find) {
				$geoPoint = $find->getGeoPoint();
				if (is_null($geoPoint)) {
					continue;
				}
				$properties = array(
					'color'   => 'orange',
					'cardUrl' => $this->generateUrl('core_find_card', array('id' => $find->getId())),
				);
				$gerometry = new \GeoJson\Geometry\Point($geoPoint);
				$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
			}
			$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
			$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

			$parameters = array_merge($parameters, array(
				'collection' => $collection,
			));

			return $this->render('@LadbCore/Find/Find/list-xhr.geojson.twig', $parameters);
		}

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()), PublicationListener::PUBLICATIONS_LISTED);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Find/Find/list-xhr.html.twig', $parameters);
		}

		if ($this->isGranted('ROLE_USER') && $this->getUser()->getMeta()->getPrivateFindCount() > 0) {

			$draftPath = $this->generateUrl('core_find_list', array( 'q' => '@mine:draft' ));
			$draftCount = $this->getUser()->getMeta()->getPrivateFindCount();

			// Flashbag
			$this->addFlash('info', '<i class="ladb-icon-warning"></i> '.$translator->trans('find.find.choice.draft_alert', array( '%count%' => $draftCount )).' <small><a href="'.$draftPath.'" class="alert-link">('.$translator->trans('default.show_my_drafts').')</a></small>');

		}

		return $parameters;
	}

	/**
	 * @Template("@LadbCore/Find/Find/show.html.twig")
	 */
	#[Route(path: '/{id}.html', name: 'core_find_show')]
	public function showAction(Request $request, $id, CollectionnableUtils $collectionnableUtils, CommentableUtils $commentableUtils, EventDispatcherInterface $dispatcher, ExplorableUtils $explorableUtils, FollowerUtils $followerUtils, LikableUtils $likableUtils, EntityManagerInterface $om, WatchableUtils $watchableUtils, WitnessManager $witnessManager) {
		$findRepository = $om->getRepository(Find::CLASS_NAME);

		$id = intval($id);

		$find = $findRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($find)) {
			if ($response = $witnessManager->checkResponse(Find::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Find entity (id='.$id.').');
		}
		$this->assertShowable($find);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationEvent($find), PublicationListener::PUBLICATION_SHOWN);

		$userFinds = $explorableUtils->getPreviousAndNextPublishedUserExplorables($find, $findRepository, $find->getUser()->getMeta()->getPublicFindCount());
		$similarFinds = $explorableUtils->getSimilarExplorables($find, 'fos_elastica.index.find_find', Find::CLASS_NAME, $userFinds);

		if ($find->getContent() instanceof LocalisableInterface) {
			$hasMap = !is_null($find->getContent()->getLatitude()) && !is_null($find->getContent()->getLongitude());
		} else {
			$hasMap = false;
		}

		return array(
			'find'              => $find,
			'permissionContext' => $this->getPermissionContext($find),
			'userFinds'         => $userFinds,
			'similarFinds'      => $similarFinds,
			'likeContext'       => $likableUtils->getLikeContext($find, $this->getUser()),
			'watchContext'      => $watchableUtils->getWatchContext($find, $this->getUser()),
			'commentContext'    => $commentableUtils->getCommentContext($find),
			'collectionContext' => $collectionnableUtils->getCollectionContext($find),
			'followerContext'   => $followerUtils->getFollowerContext($find->getUser(), $this->getUser()),
			'hasMap'            => $hasMap,
		);
	}

}