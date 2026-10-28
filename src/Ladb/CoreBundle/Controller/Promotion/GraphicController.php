<?php

namespace Ladb\CoreBundle\Controller\Promotion;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Manager\Promotion\GraphicManager;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Entity\Promotion\Graphic;
use Ladb\CoreBundle\Form\Type\Promotion\GraphicType;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\GraphicUtils;
use Ladb\CoreBundle\Utils\ExplorableUtils;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\PicturedUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @Route("/promouvoir")
 */
class GraphicController extends AbstractController {

	use PublicationControllerTrait;

	/**
	 * @Route("/new", name="core_promotion_graphic_new")
	 * @Template("@LadbCore/Promotion/Graphic/new.html.twig")
	 */
	public function newAction(Request $request, TagUtils $tagUtils) {

		$graphic = new Graphic();
		$form = $this->createForm(GraphicType::class, $graphic);

		return array(
			'form'         => $form->createView(),
			'owner'        => $this->retrieveOwner($request),
			'tagProposals' => $tagUtils->getProposals($graphic),
		);
	}

	/**
	 * @Route("/create", methods={"POST"}, name="core_promotion_graphic_create")
	 * @Template("@LadbCore/Promotion/Graphic/new.html.twig")
	 */
	public function createAction(Request $request, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, GraphicUtils $graphicUtils, EntityManagerInterface $om, TagUtils $tagUtils, TranslatorInterface $translator) {

		$owner = $this->retrieveOwner($request);

		$this->createLock('core_promotion_graphic_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$graphic = new Graphic();
		$form = $this->createForm(GraphicType::class, $graphic);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($graphic);

			$graphic->setUser($owner);
			$graphic->setMainPicture($graphic->getResource()->getThumbnail());
			$owner->getMeta()->incrementPrivateGraphicCount();

			$om->persist($graphic);
			$om->flush();

			// Create zip archive after inserting graphic into database to be sure we have an ID
			$graphicUtils->createZipArchive($graphic);

			$om->flush();	// Resave to store file size

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($graphic), PublicationListener::PUBLICATION_CREATED);

			return $this->redirect($this->generateUrl('core_promotion_graphic_show', array('id' => $graphic->getSluggedId())));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'graphic'         => $graphic,
			'form'         => $form->createView(),
			'owner'        => $owner,
			'tagProposals' => $tagUtils->getProposals($graphic),
			'hideWarning'  => true,
		);
	}

	/**
	 * @Route("/{id}/lock", requirements={"id" = "\d+"}, defaults={"lock" = true}, name="core_promotion_graphic_lock")
	 * @Route("/{id}/unlock", requirements={"id" = "\d+"}, defaults={"lock" = false}, name="core_promotion_graphic_unlock")
	 */
	public function lockUnlockAction($id, $lock, GraphicManager $graphicManager, TranslatorInterface $translator) {

		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertLockUnlockable($graphic, $lock);

		// Lock or Unlock
		if ($lock) {
			$graphicManager->lock($graphic);
		} else {
			$graphicManager->unlock($graphic);
		}

		// Flashbag
		$this->addFlash('success', $translator->trans('promotion.graphic.form.alert.'.($lock ? 'lock' : 'unlock').'_success', array( '%title%' => $graphic->getTitle() )));

		return $this->redirect($this->generateUrl('core_promotion_graphic_show', array( 'id' => $graphic->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/publish", requirements={"id" = "\d+"}, name="core_promotion_graphic_publish")
	 */
	public function publishAction($id, GraphicManager $graphicManager, TranslatorInterface $translator) {

		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertPublishable($graphic);

		// Publish
		$graphicManager->publish($graphic);

		// Flashbag
		$this->addFlash('success', $translator->trans('promotion.graphic.form.alert.publish_success', array( '%title%' => $graphic->getTitle() )));

		return $this->redirect($this->generateUrl('core_promotion_graphic_show', array( 'id' => $graphic->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/unpublish", requirements={"id" = "\d+"}, name="core_promotion_graphic_unpublish")
	 */
	public function unpublishAction(Request $request, $id, GraphicManager $graphicManager, TranslatorInterface $translator) {

		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertUnpublishable($graphic);

		// Unpublish
		$graphicManager->unpublish($graphic);

		// Flashbag
		$this->addFlash('success', $translator->trans('promotion.graphic.form.alert.unpublish_success', array( '%title%' => $graphic->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $this->getRefererUrl($request);
		}

		return $this->redirect($returnToUrl);
	}

	/**
	 * @Route("/{id}/edit", requirements={"id" = "\d+"}, name="core_promotion_graphic_edit")
	 * @Template("@LadbCore/Promotion/Graphic/edit.html.twig")
	 */
	public function editAction($id, TagUtils $tagUtils) {

		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertEditabable($graphic);

		$form = $this->createForm(GraphicType::class, $graphic);

		return array(
			'graphic'         => $graphic,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($graphic),
		);
	}

	/**
	 * @Route("/{id}/update", requirements={"id" = "\d+"}, methods={"POST"}, name="core_promotion_graphic_update")
	 * @Template("@LadbCore/Promotion/Graphic/edit.html.twig")
	 */
	public function updateAction(Request $request, $id, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, GraphicUtils $graphicUtils, EntityManagerInterface $om, PicturedUtils $picturedUtils, TagUtils $tagUtils, TranslatorInterface $translator) {

		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertEditabable($graphic);

		$picturedUtils->resetPictures($graphic); // Reset pictures array to consider form pictures order

		$previouslyUsedTags = $graphic->getTags()->toArray();	// Need to be an array to copy values

		$form = $this->createForm(GraphicType::class, $graphic);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($graphic);

			$graphicUtils->createZipArchive($graphic);

			$graphic->setMainPicture($graphic->getResource()->getThumbnail());
			if ($graphic->getUser() == $this->getUser()) {
				$graphic->setUpdatedAt(new \DateTime());
			}

			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($graphic, array( 'previouslyUsedTags' => $previouslyUsedTags )), PublicationListener::PUBLICATION_UPDATED);

			// Flashbag
			$this->addFlash('success', $translator->trans('promotion.graphic.form.alert.update_success', array( '%title%' => $graphic->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(GraphicType::class, $graphic);

		} else {

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'graphic'         => $graphic,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($graphic),
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_promotion_graphic_delete")
	 */
	public function deleteAction($id, GraphicManager $graphicManager, TranslatorInterface $translator) {

		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertDeletable($graphic);

		// Delete
		$graphicManager->delete($graphic);

		// Flashbag
		$this->addFlash('success', $translator->trans('promotion.graphic.form.alert.delete_success', array( '%title%' => $graphic->getTitle() )));

		return $this->redirect($this->generateUrl('core_promotion_graphic_list'));
	}

	/**
	 * @Route("/{id}/chown", requirements={"id" = "\d+"}, name="core_promotion_graphic_chown")
	 */
	public function chownAction(Request $request, $id, GraphicManager $graphicManager, TranslatorInterface $translator) {

		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertChownable($graphic);

		$targetUser = $this->retrieveOwner($request);

		// Change owner
		$graphicManager->changeOwner($graphic, $targetUser);

		// Flashbag
		$this->addFlash('success', $translator->trans('promotion.graphic.form.alert.chown_success', array( '%title%' => $graphic->getTitle() )));

		return $this->redirect($this->generateUrl('core_promotion_graphic_show', array( 'id' => $graphic->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/widget", requirements={"id" = "\d+"}, name="core_promotion_graphic_widget")
	 * @Template("@LadbCore/Promotion/Graphic/widget-xhr.html.twig")
	 */
	public function widgetAction($id) {

		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertShowable($graphic, true);

		return array(
			'graphic' => $graphic,
		);
	}

	/**
	 * @Route("/{id}/download", requirements={"id" = "\d+"}, name="core_promotion_graphic_download")
	 */
	public function downloadAction($id, GraphicUtils $graphicUtils, EntityManagerInterface $om, SearchUtils $searchUtils) {
		$graphic = $this->retrievePublication($id, Graphic::CLASS_NAME);
		$this->assertShowable($graphic);

		$zipAbsolutePath = $graphicUtils->getZipAbsolutePath($graphic);
		if (!file_exists($zipAbsolutePath)) {
			if (!$graphicUtils->createZipArchive($graphic)) {
				throw $this->createNotFoundException('Zip archive not found (core_promotion_graphic_download)');
			}
		}

		$graphic->incrementDownloadCount(1);

		$om->flush();

		// Update index
		$searchUtils->replaceEntityInIndex($graphic);

		$content = file_get_contents($zipAbsolutePath);

		$response = new Response();
		$response->headers->set('Content-Type', 'mime/type');
		$response->headers->set('Content-Length', filesize($zipAbsolutePath));
		$response->headers->set('Content-Disposition', 'attachment;filename="lairdubois_'.$graphic->getUser()->getUsernameCanonical().'_'.$graphic->getSlug().'.zip"');
		$response->headers->set('Expires', 0);
		$response->headers->set('Cache-Control', 'no-cache, must-revalidate');
		$response->headers->set('Pragma', 'no-cache');

		$response->setContent($content);

		return $response;
	}

	/**
	 * @Route("/", name="core_promotion_graphic_list")
	 * @Route("/{page}", requirements={"page" = "\d+"}, name="core_promotion_graphic_list_page")
	 * @Template("@LadbCore/Promotion/Graphic/list.html.twig")
	 */
	public function listAction(Request $request, EventDispatcherInterface $dispatcher, SearchUtils $searchUtils, TranslatorInterface $translator, $page = 0) {

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_qa_question_list_page)');
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

							$couldUseDefaultSort = true;

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

					case 'license':

						$filter = new \Elastica\Query\Term([ 'license.strippedname' => [ 'value' => $facet->value, 'boost' => 1.0 ] ]);
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

					case 'popular-downloads':
						$sort = array( 'downloadCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
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
			'fos_elastica.index.promotion_graphic',
			\Ladb\CoreBundle\Entity\Promotion\Graphic::CLASS_NAME,
			'core_promotion_graphic_list_page',
			$routeParameters
		);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()), PublicationListener::PUBLICATIONS_LISTED);

		$parameters = array_merge($searchParameters, array(
			'graphics'        => $searchParameters['entities'],
			'layout'          => $layout,
			'routeParameters' => $routeParameters
		));

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Promotion/Graphic/list-xhr.html.twig', $parameters);
		}

		if ($this->isGranted('ROLE_USER') && $this->getUser()->getMeta()->getPrivateGraphicCount() > 0) {

			$draftPath = $this->generateUrl('core_promotion_graphic_list', array( 'q' => '@mine:draft' ));
			$draftCount = $this->getUser()->getMeta()->getPrivateGraphicCount();

			// Flashbag
			$this->addFlash('info', '<i class="ladb-icon-warning"></i> '.$translator->trans('promotion.graphic.choice.draft_alert', array( '%count%' => $draftCount )).' <small><a href="'.$draftPath.'" class="alert-link">('.$translator->trans('default.show_my_drafts').')</a></small>');

		}

		return $parameters;
	}

	/**
	 * @Route("/{id}.html", name="core_promotion_graphic_show")
	 * @Template("@LadbCore/Promotion/Graphic/show.html.twig")
	 */
	public function showAction(Request $request, $id, CollectionnableUtils $collectionnableUtils, CommentableUtils $commentableUtils, EventDispatcherInterface $dispatcher, ExplorableUtils $explorableUtils, FollowerUtils $followerUtils, LikableUtils $likableUtils, EntityManagerInterface $om, WatchableUtils $watchableUtils, WitnessManager $witnessManager) {
		$graphicRepository = $om->getRepository(Graphic::CLASS_NAME);

		$id = intval($id);

		$graphic = $graphicRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($graphic)) {
			if ($response = $witnessManager->checkResponse(Graphic::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Graphic entity (id='.$id.').');
		}
		$this->assertShowable($graphic);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationEvent($graphic), PublicationListener::PUBLICATION_SHOWN);

		$userGraphics = $explorableUtils->getPreviousAndNextPublishedUserExplorables($graphic, $graphicRepository, $graphic->getUser()->getMeta()->getPublicGraphicCount());
		$similarGraphics = $explorableUtils->getSimilarExplorables($graphic, 'fos_elastica.index.promotion_graphic', Graphic::CLASS_NAME, $userGraphics);

		return array(
			'graphic'           => $graphic,
			'permissionContext' => $this->getPermissionContext($graphic),
			'userGraphics'      => $userGraphics,
			'similarGraphics'   => $similarGraphics,
			'likeContext'       => $likableUtils->getLikeContext($graphic, $this->getUser()),
			'watchContext'      => $watchableUtils->getWatchContext($graphic, $this->getUser()),
			'commentContext'    => $commentableUtils->getCommentContext($graphic),
			'collectionContext' => $collectionnableUtils->getCollectionContext($graphic),
			'followerContext'   => $followerUtils->getFollowerContext($graphic->getUser(), $this->getUser()),
		);
	}

}