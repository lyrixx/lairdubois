<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Manager\Core\TipManager;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Entity\Core\Tip;
use Ladb\CoreBundle\Form\Type\Core\TipType;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/astuces')]
class TipController extends AbstractController {

	/**
	 * @Template("@LadbCore/Core/Tip/new.html.twig")
	 * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_TIP')", statusCode=404, message="Not allowed (core_tip_new)")
	 */
	#[Route(path: '/new', name: 'core_tip_new')]
	public function newAction() {

		$tip = new Tip();
		$form = $this->createForm(TipType::class, $tip);

		return array(
			'form' => $form->createView(),
		);
	}

	/**
	 * @Template("@LadbCore/Core/Tip/new.html.twig")
	 * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_TIP')", statusCode=404, message="Not allowed (core_tip_create)")
	 */
	#[Route(path: '/create', methods: ['POST'], name: 'core_tip_create')]
	public function createAction(Request $request, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, TranslatorInterface $translator) {

		$this->createLock('core_tip_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$tip = new Tip();
		$form = $this->createForm(TipType::class, $tip);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($tip);

			$om->persist($tip);
			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($tip), PublicationListener::PUBLICATION_CREATED);
			$dispatcher->dispatch(new PublicationEvent($tip), PublicationListener::PUBLICATION_PUBLISHED);

			return $this->redirect($this->generateUrl('core_tip_list'));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'tip'         => $tip,
			'form'         => $form->createView(),
			'hideWarning'  => true,
		);
	}

	/**
	 * @Template("@LadbCore/Core/Tip/edit.html.twig")
	 * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_TIP')", statusCode=404, message="Not allowed (core_tip_edit)")
	 */
	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'], name: 'core_tip_edit')]
	public function editAction($id, EntityManagerInterface $om) {
		$tipRepository = $om->getRepository(Tip::CLASS_NAME);

		$tip = $tipRepository->findOneById($id);
		if (is_null($tip)) {
			throw $this->createNotFoundException('Unable to find Tip entity (id='.$id.').');
		}

		$form = $this->createForm(TipType::class, $tip);

		return array(
			'tip'  => $tip,
			'form' => $form->createView(),
		);
	}

	/**
	 * @Template("@LadbCore/Core/Tip/edit.html.twig")
	 * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_TIP')", statusCode=404, message="Not allowed (core_tip_update)")
	 */
	#[Route(path: '/{id}/update', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_tip_update')]
	public function updateAction(Request $request, $id, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, TranslatorInterface $translator) {
		$tipRepository = $om->getRepository(Tip::CLASS_NAME);

		$tip = $tipRepository->findOneById($id);
		if (is_null($tip)) {
			throw $this->createNotFoundException('Unable to find Tip entity (id='.$id.').');
		}

		$form = $this->createForm(TipType::class, $tip);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($tip);

			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($tip), PublicationListener::PUBLICATION_UPDATED);

			// Flashbag
			$this->addFlash('success', $translator->trans('core.tip.form.alert.update_success', array( '%title%' => $tip->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(TipType::class, $tip);

		} else {

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'tip'  => $tip,
			'form' => $form->createView(),
		);
	}

	/**
	 * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_TIP')", statusCode=404, message="Not allowed (core_tip_delete)")
	 */
	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], name: 'core_tip_delete')]
	public function deleteAction($id, EntityManagerInterface $om, TipManager $tipManager, TranslatorInterface $translator) {
		$tipRepository = $om->getRepository(Tip::CLASS_NAME);

		$tip = $tipRepository->findOneById($id);
		if (is_null($tip)) {
			throw $this->createNotFoundException('Unable to find Tip entity (id='.$id.').');
		}

		// Delete
		$tipManager->delete($tip);

		// Flashbag
		$this->addFlash('success', $translator->trans('core.tip.form.alert.delete_success', array( '%title%' => $tip->getTitle() )));

		return $this->redirect($this->generateUrl('core_tip_list'));
	}

	/**
	 * @Template("@LadbCore/Core/Tip/list.html.twig")
	 */
	#[Route(path: '/', name: 'core_tip_list')]
	#[Route(path: '/{page}', requirements: ['page' => '\d+'], name: 'core_tip_list_page')]
	public function listAction(Request $request, EventDispatcherInterface $dispatcher, SearchUtils $searchUtils, $page = 0) {

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_tip_list_page)');
		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) {
				switch ($facet->name) {

					// Filters /////

					// Sorters /////

					case 'sort-recent':
						$sort = array( 'changedAt' => array( 'order' => 'desc' ) );
						break;

					case 'sort-popular-views':
						$sort = array( 'viewCount' => array( 'order' => 'desc' ) );
						break;

					case 'sort-random':
						$sort = array( 'randomSeed' => isset($facet->value) ? $facet->value : '' );
						break;

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'body' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) {

				$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

			},
			null,
			'fos_elastica.index.core_tip',
			\Ladb\CoreBundle\Entity\Core\Tip::CLASS_NAME,
			'core_tip_list_page'
		);

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationsEvent($searchParameters['entities'], false), PublicationListener::PUBLICATIONS_LISTED);

		$parameters = array_merge($searchParameters, array(
			'tips' => $searchParameters['entities'],
		));

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Tip/list-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

	/**
	 * @Template("@LadbCore/Core/Tip/show.html.twig")
	 */
	#[Route(path: '/{id}.html', name: 'core_tip_show')]
	public function showAction(Request $request, $id, EventDispatcherInterface $dispatcher, EntityManagerInterface $om, WitnessManager $witnessManager) {
		$tipRepository = $om->getRepository(Tip::CLASS_NAME);

		$id = intval($id);

		$tip = $tipRepository->findOneById($id);
		if (is_null($tip)) {
			if ($response = $witnessManager->checkResponse(Tip::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Tip entity (id='.$id.').');
		}

		// Dispatch publication event
		$dispatcher->dispatch(new PublicationEvent($tip), PublicationListener::PUBLICATION_SHOWN);

		return $this->redirect($tip->getUrl());
	}

}