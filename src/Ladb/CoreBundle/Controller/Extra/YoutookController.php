<?php

namespace Ladb\CoreBundle\Controller\Extra;

use Ladb\CoreBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Twig\Attribute\Template;
use Ladb\CoreBundle\Entity\Youtook\Took;
use Ladb\CoreBundle\Form\Type\Youtook\NewTookType;
use Ladb\CoreBundle\Form\Type\Youtook\EditTookType;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\Youtook\TookManager;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/yt')]
class YoutookController extends AbstractController {

	#[Route(path: '/create', methods: ['POST'], name: 'core_youtook_create')]
	#[Template('@LadbCore/Extra/Youtook/create-xhr.html.twig')]
	public function createAction(Request $request, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, TranslatorInterface $translator) {

		$this->createLock('core_youtook_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$took = new Took();
		$form = $this->createForm(NewTookType::class, $took);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			// Check if took exists
			$tookRepository = $om->getRepository(Took::CLASS_NAME);
			if ($tookRepository->existsByEmbedIdentifierAndUser($took->getEmbedIdentifier(), $this->getUser())) {

				$took = $tookRepository->findOneByEmbedIdentifierAndUser($took->getEmbedIdentifier(), $this->getUser());
				$took->setChangedAt(new \DateTime());

				$om->flush();

			} else {

				$fieldPreprocessorUtils->preprocessFields($took);

				$took->setUser($this->getUser());

				$om->persist($took);
				$om->flush();

			}

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($took), PublicationListener::PUBLICATION_CREATED);

			// Flashbag
			$this->addFlash('success', $translator->trans('youtook.form.alert.create_success'));

			$success = true;
		}

		return array(
			'success' => isset($success) ? $success : false,
			'took'    => $took,
			'form'    => !isset($success) ? $form->createView() : null,
		);
	}

	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'], name: 'core_youtook_edit')]
	#[Template('@LadbCore/Extra/Youtook/edit.html.twig')]
	public function editAction($id, EntityManagerInterface $om) {
		$tookRepository = $om->getRepository(Took::CLASS_NAME);

		$took = $tookRepository->findOneById($id);
		if (is_null($took)) {
			throw $this->createNotFoundException('Unable to find Video entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $took->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_youtook_edit)');
		}

		$form = $this->createForm(EditTookType::class, $took);

		return array(
			'took' => $took,
			'form'  => $form->createView(),
		);
	}

	#[Route(path: '/{id}/update', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_youtook_update')]
	#[Template('@LadbCore/Extra/Youtook/edit.html.twig')]
	public function updateAction(Request $request, $id, EventDispatcherInterface $dispatcher, FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om, TranslatorInterface $translator) {
		$tookRepository = $om->getRepository(Took::CLASS_NAME);

		$took = $tookRepository->findOneById($id);
		if (is_null($took)) {
			throw $this->createNotFoundException('Unable to find Video entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $took->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_youtook_update)');
		}

		$form = $this->createForm(EditTookType::class, $took);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$fieldPreprocessorUtils->preprocessFields($took);

			if ($took->getUser()->getId() == $this->getUser()->getId()) {
				$took->setUpdatedAt(new \DateTime());
			}

			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(new PublicationEvent($took), PublicationListener::PUBLICATION_UPDATED);

			// Flashbag
			$this->addFlash('success', $translator->trans('youtook.form.alert.update_success', array( '%title%' => $took->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(EditTookType::class, $took);

		} else {

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'took' => $took,
			'form'  => $form->createView(),
		);
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], name: 'core_youtook_delete')]
	public function deleteAction($id, EntityManagerInterface $om, TookManager $tookManager, TranslatorInterface $translator) {
		$tookRepository = $om->getRepository(Took::CLASS_NAME);

		$took = $tookRepository->findOneById($id);
		if (is_null($took)) {
			throw $this->createNotFoundException('Unable to find Took entity (id='.$id.').');
		}
		if (!$this->isGranted('ROLE_ADMIN') && $took->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_youtook_delete)');
		}

		// Delete
		$tookManager->delete($took);

		// Flashbag
		$this->addFlash('success', $translator->trans('youtook.form.alert.delete_success', array( '%title%' => $took->getTitle() )));

		return $this->redirect($this->generateUrl('core_youtook_user_list'));
	}

	#[Route(path: '/', name: 'core_youtook_list')]
	#[Route(path: '/{filter}', requirements: ['filter' => '\w+'], name: 'core_youtook_list_filter')]
	#[Route(path: '/{filter}/{page}', requirements: ['filter' => '\w+', 'page' => '\d+'], name: 'core_youtook_list_filter_page')]
	#[Template('@LadbCore/Extra/Youtook/list.html.twig')]
	public function listAction(Request $request, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = 'all', $page = 0) {
		if (!$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Access denied');
		}

		$tookRepository = $om->getRepository(Took::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page, 20, 20);
		$limit = $paginatorUtils->computePaginatorLimit($page, 20, 20);
		$paginator = $tookRepository->findPagined($offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_youtook_list_filter_page', array( 'filter' => $filter ), $page, $paginator->count(), 20, 20);

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'tooks'       => $paginator,
			'tookCount' => $paginator->count(),
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Extra/Youtook/list-xhr.html.twig', $parameters);
		}
		return $parameters;
	}

	#[Route(path: '/mes-tooks', name: 'core_youtook_user_list')]
	#[Route(path: '/mes-tooks/{filter}', requirements: ['filter' => '\w+'], name: 'core_youtook_user_list_filter')]
	#[Route(path: '/mes-tooks/{filter}/{page}', requirements: ['filter' => '\w+', 'page' => '\d+'], name: 'core_youtook_user_list_filter_page')]
	#[Template('@LadbCore/Extra/Youtook/userList.html.twig')]
	public function userListAction(Request $request, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = 'all', $page = 0) {
		if (!$this->isGranted('ROLE_USER')) {
			throw $this->createNotFoundException('Access denied');
		}

		$tookRepository = $om->getRepository(Took::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page, 20, 20);
		$limit = $paginatorUtils->computePaginatorLimit($page, 20, 20);
		$paginator = $tookRepository->findPaginedByUser($this->getUser(), $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_youtook_user_list_filter_page', array( 'filter' => $filter ), $page, $paginator->count(), 20, 20);

		$took = new Took();
		$form = $this->createForm(NewTookType::class, $took);

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'tooks'       => $paginator,
			'tookCount'   => $paginator->count(),
			'form'        => $form->createView(),
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Extra/Youtook/list-xhr.html.twig', $parameters);
		}
		return $parameters;
	}

	#[Route(path: '/{id}.html', name: 'core_youtook_show')]
	#[Template('@LadbCore/Extra/Youtook/show.html.twig')]
	public function showAction(Request $request, $id, EntityManagerInterface $om, WitnessManager $witnessManager) {
		$tookRepository = $om->getRepository(Took::CLASS_NAME);

		$id = intval($id);

		$took = $tookRepository->findOneById($id);
		if (is_null($took)) {
			if ($response = $witnessManager->checkResponse(Took::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Took entity (id='.$id.').');
		}

		$referer = $request->headers->get('referer');
		$userAgent = $request->headers->get('User-Agent');

		$isFacebookBotUserAgent = preg_match('/facebookexternalhit/', $userAgent);
		$isLadbReferrer = preg_match('/lairdubois.fr/', $referer);

		if ($isFacebookBotUserAgent || $isLadbReferrer) {
			return array(
				'took' => $took,
			);
		}

		return $this->redirect($took->getUrl());
	}

}
