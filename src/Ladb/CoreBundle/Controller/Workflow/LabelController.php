<?php

namespace Ladb\CoreBundle\Controller\Workflow;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Ladb\CoreBundle\Entity\Workflow\Task;
use Ladb\CoreBundle\Entity\Workflow\Label;
use Ladb\CoreBundle\Form\Type\Workflow\LabelType;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/processus')]
class LabelController extends AbstractWorkflowBasedController {

	#[Route(path: '/{id}/label/new', requirements: ['id' => '\d+'], name: 'core_workflow_label_new')]
	#[Template('@LadbCore/Workflow/Label/new-xhr.html.twig')]
	public function newAction(Request $request, $id) {

		// Retrieve Workflow
		$workflow = $this->_retrieveWorkflow($id);
		$this->_assertAuthorizedWorkflow($workflow);

		$label = new Label();
		$label->setColor(Label::COLOR_SEQUENCE[count($workflow->getLabels()) % count(Label::COLOR_SEQUENCE)]);
		$form = $this->createForm(LabelType::class, $label);

		return array(
			'form'     => $form->createView(),
			'workflow' => $workflow,
		);
	}

	#[Route(path: '/{id}/label/create', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_workflow_label_create')]
	#[Template('@LadbCore/Workflow/Label/new-xhr.html.twig')]
	public function createAction(Request $request, $id, EntityManagerInterface $om) {

		$this->createLock('core_workflow_label_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		// Retrieve Workflow
		$workflow = $this->_retrieveWorkflow($id);
		$this->_assertAuthorizedWorkflow($workflow);

		$label = new Label();
		$form = $this->createForm(LabelType::class, $label);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$workflow->addLabel($label);

			$om->flush();

			return $this->render('@LadbCore/Workflow/Label/create-xhr.html.twig', array(
				'label' => $label,
			));
		}

		return array(
			'form'     => $form->createView(),
			'workflow' => $workflow,
		);
	}

	#[Route(path: '/label/{id}/edit', requirements: ['id' => '\d+'], name: 'core_workflow_label_edit')]
	#[Template('@LadbCore/Workflow/Label/edit-xhr.html.twig')]
	public function editAction(Request $request, $id, EntityManagerInterface $om) {
		$labelRepository = $om->getRepository(Label::CLASS_NAME);

		$label = $labelRepository->findOneById($id);
		if (is_null($label)) {
			throw $this->createNotFoundException('Unable to find Label entity (id='.$id.').');
		}
		$this->_assertAuthorizedWorkflow($label->getWorkflow());

		$form = $this->createForm(LabelType::class, $label);

		return array(
			'form'  => $form->createView(),
			'label' => $label,
		);
	}

	#[Route(path: '/label/{id}/update', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_workflow_label_update')]
	#[Template('@LadbCore/Workflow/Label/edit-xhr.html.twig')]
	public function updateAction(Request $request, $id, EntityManagerInterface $om) {
		$labelRepository = $om->getRepository(Label::CLASS_NAME);

		$label = $labelRepository->findOneById($id);
		if (is_null($label)) {
			throw $this->createNotFoundException('Unable to find Label entity (id='.$id.').');
		}
		$this->_assertAuthorizedWorkflow($label->getWorkflow());

		$form = $this->createForm(LabelType::class, $label);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$om->flush();

			// Retrieve updated tasks
			$taskRepository = $om->getRepository(Task::CLASS_NAME);
			$tasks = $taskRepository->findByLabel($label);

			// Push changes
			if (is_array($tasks) && count($tasks)) {
				$this->_push($label->getWorkflow(), array(
					'updatedTaskInfos'   => $this->_generateTaskInfos($tasks, self::TASKINFO_BOX),
				));
			}

			return $this->render('@LadbCore/Workflow/Label/update-xhr.html.twig', array(
				'label' => $label,
			));
		}

		return array(
			'form'  => $form->createView(),
			'label' => $label,
		);
	}

	#[Route(path: '/label/{id}/delete', requirements: ['id' => '\d+'], name: 'core_workflow_label_delete')]
	#[Template('@LadbCore/Workflow/Label/delete-xhr.html.twig')]
	public function deleteAction(Request $request, $id, EntityManagerInterface $om) {
		$labelRepository = $om->getRepository(Label::CLASS_NAME);

		$label = $labelRepository->findOneById($id);
		if (is_null($label)) {
			throw $this->createNotFoundException('Unable to find Label entity (id='.$id.').');
		}
		$this->_assertAuthorizedWorkflow($label->getWorkflow());

		// Update funding balance
		$workflow = $label->getWorkflow();
		$workflow->removeLabel($label);

		// Retrieve updated tasks
		$taskRepository = $om->getRepository(Task::CLASS_NAME);
		$tasks = $taskRepository->findByLabel($label);

		$om->remove($label);
		$om->flush();

		// Push changes
		if (is_array($tasks) && count($tasks)) {
			$this->_push($workflow, array(
				'updatedTaskInfos' => $this->_generateTaskInfos($tasks, self::TASKINFO_BOX),
			));
		}

		return;
	}

	#[Route(path: '/{id}/labels', requirements: ['id' => '\d+'], name: 'core_workflow_label_list')]
	#[Template('@LadbCore/Workflow/Label/list-xhr.html.twig')]
	public function listAction(Request $request, $id) {

		// Retrieve Workflow
		$workflow = $this->_retrieveWorkflow($id);
		$this->_assertAuthorizedWorkflow($workflow);

		return array(
			'workflow' => $workflow,
		);
	}

}