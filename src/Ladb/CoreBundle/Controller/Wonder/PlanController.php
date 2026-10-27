<?php

namespace Ladb\CoreBundle\Controller\Wonder;

use Ladb\CoreBundle\Controller\CustomOwnerControllerTrait;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Utils\ResourceUtils;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Form\Type\Wonder\PlanType;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\PlanUtils;
use Ladb\CoreBundle\Utils\ExplorableUtils;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\PicturedUtils;
use Ladb\CoreBundle\Utils\EmbeddableUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Manager\Wonder\PlanManager;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Utils\StripableUtils;
use Liip\ImagineBundle\Controller\ImagineController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/plans")
 */
class PlanController extends AbstractController {

	use PublicationControllerTrait;

	/**
	 * @Route("/new", name="core_plan_new")
	 * @Template("@LadbCore/Wonder/Plan/new.html.twig")
	 */
	public function newAction(Request $request, TagUtils $tagUtils) {

		$plan = new Plan();
		$form = $this->createForm(PlanType::class, $plan);

		return array(
			'form'         => $form->createView(),
			'owner'        => $this->retrieveOwner($request),
			'tagProposals' => $tagUtils->getProposals($plan),
		);
	}

	/**
	 * @Route("/create", methods={"POST"}, name="core_plan_create")
	 * @Template("@LadbCore/Wonder/Plan/new.html.twig")
	 */
	public function createAction(Request $request, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, PlanUtils $planUtils, TagUtils $tagUtils, TranslatorInterface $translator) {

		$owner = $this->retrieveOwner($request);

		$this->createLock('core_plan_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$om = $this->getDoctrine()->getManager();

		$plan = new Plan();
		$form = $this->createForm(PlanType::class, $plan);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($plan);

			$plan->setUser($owner);
			$plan->setMainPicture($plan->getPictures()->first());
			$owner->getMeta()->incrementPrivatePlanCount();

			$planUtils->generateKinds($plan);
			$planUtils->processSketchup3DWarehouseUrl($plan);
			$planUtils->processA360Url($plan);

			$om->persist($plan);
			$om->flush();

			// Create zip archive after inserting plan into database to be sure we have an ID
			$planUtils->createZipArchive($plan);

			$om->flush();	// Resave to store file size

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($plan), PublicationListener::PUBLICATION_CREATED);

			return $this->redirect($this->generateUrl('core_plan_show', array('id' => $plan->getSluggedId())));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'plan'         => $plan,
			'form'         => $form->createView(),
			'owner'        => $owner,
			'tagProposals' => $tagUtils->getProposals($plan),
			'hideWarning'  => true,
		);
	}

	/**
	 * @Route("/{id}/lock", requirements={"id" = "\d+"}, defaults={"lock" = true}, name="core_plan_lock")
	 * @Route("/{id}/unlock", requirements={"id" = "\d+"}, defaults={"lock" = false}, name="core_plan_unlock")
	 */
	public function lockUnlockAction($id, $lock, PlanManager $planManager, TranslatorInterface $translator) {

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertLockUnlockable($plan, $lock);

		// Lock or Unlock
		if ($lock) {
			$planManager->lock($plan);
		} else {
			$planManager->unlock($plan);
		}

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.plan.form.alert.'.($lock ? 'lock' : 'unlock').'_success', array( '%title%' => $plan->getTitle() )));

		return $this->redirect($this->generateUrl('core_plan_show', array( 'id' => $plan->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/publish", requirements={"id" = "\d+"}, name="core_plan_publish")
	 */
	public function publishAction($id, PlanManager $planManager, TranslatorInterface $translator) {

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertPublishable($plan);

		// Publish
		$planManager->publish($plan);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.plan.form.alert.publish_success', array( '%title%' => $plan->getTitle() )));

		return $this->redirect($this->generateUrl('core_plan_show', array( 'id' => $plan->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/unpublish", requirements={"id" = "\d+"}, name="core_plan_unpublish")
	 */
	public function unpublishAction(Request $request, $id, PlanManager $planManager, TranslatorInterface $translator) {

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertUnpublishable($plan);

		// Unpublish
		$planManager->unpublish($plan);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.plan.form.alert.unpublish_success', array( '%title%' => $plan->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $this->getRefererUrl($request);
		}

		return $this->redirect($returnToUrl);
	}

	/**
	 * @Route("/{id}/edit", requirements={"id" = "\d+"}, name="core_plan_edit")
	 * @Template("@LadbCore/Wonder/Plan/edit.html.twig")
	 */
	public function editAction($id, TagUtils $tagUtils) {

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertEditabable($plan);

		$form = $this->createForm(PlanType::class, $plan);

		return array(
			'plan'         => $plan,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($plan),
		);
	}

	/**
	 * @Route("/{id}/update", requirements={"id" = "\d+"}, methods={"POST"}, name="core_plan_update")
	 * @Template("@LadbCore/Wonder/Plan/edit.html.twig")
	 */
	public function updateAction(Request $request, $id, EventDispatcherInterface $dispatcher, EmbeddableUtils $embaddableUtils, FieldPreprocessorUtils $fieldPreprocessorUtils, PicturedUtils $picturedUtils, PlanUtils $planUtils, StripableUtils $stripableUtils, TagUtils $tagUtils, TranslatorInterface $translator) {

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertEditabable($plan);

		$picturedUtils->resetPictures($plan); // Reset pictures array to consider form pictures order

		$previouslyUsedTags = $plan->getTags()->toArray();	// Need to be an array to copy values

		$form = $this->createForm(PlanType::class, $plan);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($plan);

			$embaddableUtils->resetSticker($plan);

			$stripableUtils->resetStrip($plan);

			$planUtils->generateKinds($plan);
			$planUtils->processSketchup3DWarehouseUrl($plan);
			$planUtils->processA360Url($plan);
			$planUtils->createZipArchive($plan);

			$plan->setMainPicture($plan->getPictures()->first());
			if ($plan->getUser() == $this->getUser()) {
				$plan->setUpdatedAt(new \DateTime());
			}

			$om = $this->getDoctrine()->getManager();
			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($plan, array( 'previouslyUsedTags' => $previouslyUsedTags )), PublicationListener::PUBLICATION_UPDATED);

			// Flashbag
			$this->addFlash('success', $translator->trans('wonder.plan.form.alert.update_success', array( '%title%' => $plan->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(PlanType::class, $plan);

		} else {

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'plan'         => $plan,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($plan),
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_plan_delete")
	 */
	public function deleteAction($id, PlanManager $planManager, TranslatorInterface $translator) {

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertDeletable($plan);

		// Delete
		$planManager->delete($plan);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.plan.form.alert.delete_success', array( '%title%' => $plan->getTitle() )));

		return $this->redirect($this->generateUrl('core_plan_list'));
	}

	/**
	 * @Route("/{id}/chown", requirements={"id" = "\d+"}, name="core_plan_chown")
	 */
	public function chownAction(Request $request, $id, PlanManager $planManager, TranslatorInterface $translator) {

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertChownable($plan);

		$targetUser = $this->retrieveOwner($request);

		// Change owner
		$planManager->changeOwner($plan, $targetUser);

		// Flashbag
		$this->addFlash('success', $translator->trans('wonder.plan.form.alert.chown_success', array( '%title%' => $plan->getTitle() )));

		return $this->redirect($this->generateUrl('core_plan_show', array( 'id' => $plan->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/download", requirements={"id" = "\d+"}, name="core_plan_download")
	 */
	public function downloadAction($id, PlanUtils $planUtils, SearchUtils $searchUtils) {
		$om = $this->getDoctrine()->getManager();

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		$zipAbsolutePath = $planUtils->getZipAbsolutePath($plan);
		if (!file_exists($zipAbsolutePath)) {
			if (!$planUtils->createZipArchive($plan)) {
				throw $this->createNotFoundException('Zip archive not found (core_plan_download)');
			}
		}

		$plan->incrementDownloadCount(1);

		$om->flush();

		// Update index
		$searchUtils->replaceEntityInIndex($plan);

		$content = file_get_contents($zipAbsolutePath);

		$response = new Response();
		$response->headers->set('Content-Type', 'mime/type');
		$response->headers->set('Content-Length', filesize($zipAbsolutePath));
		$response->headers->set('Content-Disposition', 'attachment;filename="lairdubois_'.$plan->getUser()->getUsernameCanonical().'_'.$plan->getSlug().'.zip"');
		$response->headers->set('Expires', 0);
		$response->headers->set('Cache-Control', 'no-cache, must-revalidate');
		$response->headers->set('Pragma', 'no-cache');

		$response->setContent($content);

		return $response;
	}

	/**
	 * @Route("/{id}/questions", requirements={"id" = "\d+"}, name="core_plan_questions")
	 * @Route("/{id}/questions/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_plan_questions_filter")
	 * @Route("/{id}/questions/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_questions_filter_page")
	 * @Template("@LadbCore/Wonder/Plan/questions.html.twig")
	 */
	public function questionsAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		// Questions

		$questionRepository = $om->getRepository(Question::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $questionRepository->findPaginedByPlan($plan, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_plan_questions_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'questions'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Qa/Question/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'plan' => $plan,
		));
	}

	/**
	 * @Route("/{id}/pas-a-pas", requirements={"id" = "\d+"}, name="core_plan_howtos")
	 * @Route("/{id}/pas-a-pas/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_plan_howtos_filter")
	 * @Route("/{id}/pas-a-pas/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_howtos_filter_page")
	 * @Template("@LadbCore/Wonder/Plan/howtos.html.twig")
	 */
	public function howtosAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		// Howtos

		$howtoRepository = $om->getRepository(Howto::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $howtoRepository->findPaginedByPlan($plan, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_plan_howtos_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

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
			'plan' => $plan,
		));
	}

	/**
	 * @Route("/{id}/creations", requirements={"id" = "\d+"}, name="core_plan_creations")
	 * @Route("/{id}/creations/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_plan_creations_filter")
	 * @Route("/{id}/creations/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_creations_filter_page")
	 * @Template("@LadbCore/Wonder/Plan/creations.html.twig")
	 */
	public function creationsAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		// Creations

		$creationRepository = $om->getRepository(Creation::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $creationRepository->findPaginedByPlan($plan, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_plan_creations_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

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
			'plan' => $plan,
		));
    }

	/**
	 * @Route("/{id}/processus", requirements={"id" = "\d+"}, name="core_plan_workflows")
	 * @Route("/{id}/processus/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_plan_workflows_filter")
	 * @Route("/{id}/processus/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_workflows_filter_page")
	 * @Template("@LadbCore/Wonder/Plan/workflows.html.twig")
	 */
	public function workflowsAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		// Workflows

		$workflowRepository = $om->getRepository(Workflow::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workflowRepository->findPaginedByPlan($plan, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_plan_workflows_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

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
			'plan' => $plan,
		));
	}

	/**
	 * @Route("/{id}/ateliers", requirements={"id" = "\d+"}, name="core_plan_workshops")
	 * @Route("/{id}/ateliers/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_plan_workshops_filter")
	 * @Route("/{id}/ateliers/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_workshops_filter_page")
	 * @Template("@LadbCore/Wonder/Plan/workshops.html.twig")
	 */
	public function workshopsAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		// Workshops

		$workshopRepository = $om->getRepository(Workshop::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workshopRepository->findPaginedByPlan($plan, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_plan_workshops_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

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
			'plan' => $plan,
		));
    }

	/**
	 * @Route("/{id}/ecoles", requirements={"id" = "\d+"}, name="core_plan_schools")
	 * @Route("/{id}/ecoles/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_plan_schools_filter")
	 * @Route("/{id}/ecoles/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_schools_filter_page")
	 * @Template("@LadbCore/Wonder/Plan/schools.html.twig")
	 */
	public function schoolsAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		// Schools

		$schoolRepository = $om->getRepository(School::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $schoolRepository->findPaginedByPlan($plan, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_plan_schools_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'schools'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Knowledge/School/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'plan' => $plan,
		));
	}

	/**
	 * @Route("/{id}/inspirations", requirements={"id" = "\d+"}, name="core_plan_inspirations")
	 * @Route("/{id}/inspirations/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_plan_inspirations_filter")
	 * @Route("/{id}/inspirations/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_inspirations_filter_page")
	 * @Template("@LadbCore/Wonder/Plan/inspirations.html.twig")
	 */
	public function inspirationsAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();
		$planRepository = $om->getRepository(Plan::CLASS_NAME);

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		// Inspirations

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $planRepository->findPaginedByRebound($plan, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_plan_inspirations_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

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
			'plan' => $plan,
		));
	}

	/**
	 * @Route("/{id}/rebonds", requirements={"id" = "\d+"}, name="core_plan_rebounds")
	 * @Route("/{id}/rebonds/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_plan_rebounds_filter")
	 * @Route("/{id}/rebonds/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_rebounds_filter_page")
	 * @Template("@LadbCore/Wonder/Plan/rebounds.html.twig")
	 */
	public function reboundsAction(Request $request, $id, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();
		$planRepository = $om->getRepository(Plan::CLASS_NAME);

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan);

		// Rebounds

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $planRepository->findPaginedByInspiration($plan, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_plan_rebounds_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

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
			'plan' => $plan,
		));
	}

	/**
	 * @Route("/{id}/sticker.png", requirements={"id" = "\d+"}, name="core_plan_sticker_bc")
	 */
	public function bcStickerAction(Request $request, $id) {
		return $this->redirect($this->generateUrl('core_plan_sticker', array( 'id' => $id )));
	}

	/**
	 * @Route("/{id}/sticker", requirements={"id" = "\d+"}, name="core_plan_sticker")
	 */
	public function stickerAction(Request $request, $id, EmbeddableUtils $embeddableUtils, ImagineController $imagineController) {
		$om = $this->getDoctrine()->getManager();

		$id = intval($id);

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan, true);

		$sticker = $plan->getSticker();
		if (is_null($sticker)) {
			$sticker = $embeddableUtils->generateSticker($plan);
			if (!is_null($sticker)) {
				$om->flush();
			} else {
				throw $this->createNotFoundException('Error creating sticker (core_plan_sticker)');
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
	 * @Route("/{id}/strip", requirements={"id" = "\d+"}, name="core_plan_strip")
	 */
	public function stripAction(Request $request, $id, ImagineController $imagineController, StripableUtils $stripableUtils) {
		$om = $this->getDoctrine()->getManager();

		$id = intval($id);

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan, true);

		$strip = $plan->getStrip();
		if (is_null($strip)) {
			$strip = $stripableUtils->generateStrip($plan);
			if (!is_null($strip)) {
				$om->flush();
			} else {
				throw $this->createNotFoundException('Error creating strip (core_plan_strip)');
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
	 * @Route("/{id}/widget", requirements={"id" = "\d+"}, name="core_plan_widget")
	 * @Template("@LadbCore/Wonder/Plan/widget-xhr.html.twig")
	 */
	public function widgetAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();

		$id = intval($id);

		$plan = $this->retrievePublication($id, Plan::CLASS_NAME);
		$this->assertShowable($plan, true);

		return array(
			'plan' => $plan,
		);
	}

	/**
	 * @Route("/{filter}", requirements={"filter" = "[a-z-]+"}, name="core_plan_list_filter")
	 * @Route("/{filter}/{page}", requirements={"filter" = "[a-z-]+", "page" = "\d+"}, name="core_plan_list_filter_page")
	 */
	public function goneListAction(Request $request, $filter, $page = 0) {
		throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException();
	}

	/**
	 * @Route("/", name="core_plan_list")
	 * @Route("/{page}", requirements={"page" = "\d+"}, name="core_plan_list_page")
	 * @Template("@LadbCore/Wonder/Plan/list.html.twig")
	 */
	public function listAction(Request $request, EventDispatcherInterface $dispatcher, ResourceUtils $resourceUtils, SearchUtils $searchUtils, TranslatorInterface $translator, $page = 0) {

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_plan_list_page)');
		}

		$layout = $request->get('layout', 'view');

		$routeParameters = array();
		if ($layout != 'view') {
			$routeParameters['layout'] = $layout;
		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils, $resourceUtils) {
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

					case 'kind':

						$kind = $resourceUtils->getKindFromStrippedName($facet->value);
						$filter = new \Elastica\Query\Term(['kinds' => ['value' => $kind, 'boost' => 1.0]]);
						$filters[] = $filter;

						break;

					case 'file-extension':

						$filters[] = new \Elastica\Query\MatchQuery('resources.fileExtension', $facet->value);

						break;

					case 'content-questions':

						$filter = new \Elastica\Query\Range('questionCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-creations':

						$filter = new \Elastica\Query\Range('creationCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-workshops':

						$filter = new \Elastica\Query\Range('workshopCount', array( 'gt' => 0 ));
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

					case 'content-schools':

						$filter = new \Elastica\Query\Range('schoolCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'with-inspiration':

						$filter = new \Elastica\Query\Range('inspirationCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'with-rebound':

						$filter = new \Elastica\Query\Range('reboundCount', array( 'gt' => 0 ));
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

					case 'sort-popular-downloads':
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
			function(&$filters) use ($layout) {

				$this->pushGlobalVisibilityFilter($filters, $layout != 'choice', true);

			},
			'fos_elastica.index.ladb.wonder_plan',
			\Ladb\CoreBundle\Entity\Wonder\Plan::CLASS_NAME,
			'core_plan_list_page',
			$routeParameters
		);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()), PublicationListener::PUBLICATIONS_LISTED);

		$parameters = array_merge($searchParameters, array(
			'plans'           => $searchParameters['entities'],
			'layout'          => $layout,
			'routeParameters' => $routeParameters
		));

		if ($request->isXmlHttpRequest()) {
			if ($layout == 'choice') {
				return $this->render('@LadbCore/Wonder/Plan/list-choice-xhr.html.twig', $parameters);
			} else {
				return $this->render('@LadbCore/Wonder/Plan/list-xhr.html.twig', $parameters);
			}
		}

		if ($layout == 'choice') {
			return $this->render('@LadbCore/Wonder/Plan/list-choice.html.twig', $parameters);
		}

		if ($this->isGranted('ROLE_USER') && $this->getUser()->getMeta()->getPrivatePlanCount() > 0) {

			$draftPath = $this->generateUrl('core_plan_list', array( 'q' => '@mine:draft' ));
			$draftCount = $this->getUser()->getMeta()->getPrivatePlanCount();

			// Flashbag
			$this->addFlash('info', '<i class="ladb-icon-warning"></i> '.$translator->trans('wonder.plan.choice.draft_alert', array( '%count%' => $draftCount )).' <small><a href="'.$draftPath.'" class="alert-link">('.$translator->trans('default.show_my_drafts').')</a></small>');

		}

		return $parameters;
	}

	/**
	 * @Route("/{id}.html", name="core_plan_show")
	 * @Template("@LadbCore/Wonder/Plan/show.html.twig")
	 */
	public function showAction(Request $request, $id, CollectionnableUtils $collectionnableUtils, CommentableUtils $commentableUtils, EventDispatcherInterface $dispatcher, EmbeddableUtils $embaddableUtils, ExplorableUtils $explorableUtils, FollowerUtils $followerUtils, LikableUtils $likableUtils, SearchUtils $searchUtils, WatchableUtils $watchableUtils, WitnessManager $witnessManager) {
		$om = $this->getDoctrine()->getManager();
		$planRepository = $om->getRepository(Plan::CLASS_NAME);

		$id = intval($id);

		$plan = $planRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($plan)) {
			if ($response = $witnessManager->checkResponse(Plan::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Plan entity (id='.$id.').');
		}
		$this->assertShowable($plan);

		$referral = $embaddableUtils->processReferer($plan, $request);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationEvent($plan), PublicationListener::PUBLICATION_SHOWN);

		$searchableSoftwareCount = $searchUtils->searchEntitiesCount(array(
			new \Elastica\Query\MatchQuery('supportedFiles', implode(',', $plan->getResourceFileExtensions()))
		), 'fos_elastica.index.ladb.knowledge_software');

		$userPlans = $explorableUtils->getPreviousAndNextPublishedUserExplorables($plan, $planRepository, $plan->getUser()->getMeta()->getPublicPlanCount());
		$similarPlans = $explorableUtils->getSimilarExplorables($plan, 'fos_elastica.index.ladb.wonder_plan', Plan::CLASS_NAME, $userPlans);

		return array(
			'plan'                    => $plan,
			'permissionContext'       => $this->getPermissionContext($plan),
			'searchableSoftwareCount' => $searchableSoftwareCount,
			'userPlans'               => $userPlans,
			'similarPlans'            => $similarPlans,
			'likeContext'             => $likableUtils->getLikeContext($plan, $this->getUser()),
			'watchContext'            => $watchableUtils->getWatchContext($plan, $this->getUser()),
			'commentContext'          => $commentableUtils->getCommentContext($plan),
			'collectionContext'       => $collectionnableUtils->getCollectionContext($plan),
			'followerContext'         => $followerUtils->getFollowerContext($plan->getUser(), $this->getUser()),
			'referral'                => $referral,
		);
	}

}