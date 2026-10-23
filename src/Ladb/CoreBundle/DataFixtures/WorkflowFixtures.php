<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\License;
use Ladb\CoreBundle\Entity\Workflow\Label;
use Ladb\CoreBundle\Entity\Workflow\Part;
use Ladb\CoreBundle\Entity\Workflow\Task;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Manager\Workflow\WorkflowManager;

/**
 * A workflow (id 1) of "user", with two tasks, a label and a part.
 */
final class WorkflowFixtures extends Fixture implements DependentFixtureInterface {

	public const WORKFLOW = 'workflow';

	private $factory;
	private $workflowManager;

	public function __construct(
		FixtureFactory $factory,
		WorkflowManager $workflowManager
	) {
		$this->factory = $factory;
		$this->workflowManager = $workflowManager;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager) {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		// As WorkflowController::createAction

		$this->factory->actAs($user);

		$workflow = new Workflow();
		$workflow->setTitle('fabrication d\'une table basse');
		$workflow->setBody('Le processus complet, du débit à la finition.');
		$workflow->setMainPicture($this->factory->picture($user, 'wood-5.jpg', 'workflow'));
		$workflow->setLicense(new License());
		foreach ($this->factory->tags('table,processus') as $tag) {
			$workflow->addTag($tag);
		}
		$this->factory->preprocess($workflow);
		$workflow->setUser($user);
		$user->getMeta()->incrementPrivateWorkflowCount();

		$label = new Label();
		$label->setName('Débit');
		$label->setColor('#1abc9c');
		$workflow->addLabel($label);

		$part = new Part();
		$part->setNumber('A');
		$part->setName('Plateau');
		$part->setCount(1);
		$workflow->addPart($part);

		$debit = new Task();
		$debit->setTitle('Débiter les planches');
		$debit->setStatus(Task::STATUS_WORKABLE);
		$debit->setPositionLeft(20);
		$debit->setPositionTop(20);
		$debit->addLabel($label);
		$debit->addPart($part);
		$workflow->addTask($debit);
		$workflow->incrementTaskCount();

		$finish = new Task();
		$finish->setTitle('Poncer et huiler');
		$finish->setStatus(Task::STATUS_PENDING);
		$finish->setPositionLeft(20);
		$finish->setPositionTop(120);
		$debit->addTargetTask($finish);
		$workflow->addTask($finish);
		$workflow->incrementTaskCount();

		$this->factory->create($workflow);

		$this->workflowManager->publish($workflow);

		$this->factory->actAs($user2);
		$this->factory->comment($user2, $workflow, 'Combien de temps pour le plateau ?');
		$this->factory->like($user2, $workflow);

		$this->addReference(self::WORKFLOW, $workflow);
	}

}
