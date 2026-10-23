<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\Feedback;
use Ladb\CoreBundle\Entity\Core\License;
use Ladb\CoreBundle\Entity\Core\Resource;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\Wonder\CreationManager;
use Ladb\CoreBundle\Manager\Wonder\PlanManager;
use Ladb\CoreBundle\Manager\Wonder\WorkshopManager;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\PlanUtils;

/**
 * A plan, a workshop and a creation (id 1 each) of "user". The creation is linked to the
 * other contents of "user" (plan, howto, workflow, question, provider, school).
 */
final class WonderFixtures extends Fixture implements DependentFixtureInterface {

	public const CREATION = 'wonder-creation';
	public const PLAN = 'wonder-plan';
	public const WORKSHOP = 'wonder-workshop';

	private $activityUtils;
	private $creationManager;
	private $factory;
	private $planManager;
	private $planUtils;
	private $workshopManager;

	public function __construct(
		ActivityUtils $activityUtils,
		CreationManager $creationManager,
		FixtureFactory $factory,
		PlanManager $planManager,
		PlanUtils $planUtils,
		WorkshopManager $workshopManager
	) {
		$this->activityUtils = $activityUtils;
		$this->creationManager = $creationManager;
		$this->factory = $factory;
		$this->planManager = $planManager;
		$this->planUtils = $planUtils;
		$this->workshopManager = $workshopManager;
	}

	public function getDependencies() {
		return array( HowtoFixtures::class, KnowledgeFixtures::class, QaFixtures::class, UserFixtures::class, WorkflowFixtures::class );
	}

	public function load(ObjectManager $manager) {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		$this->factory->actAs($user);

		// Plan, as PlanController::createAction

		$plan = new Plan();
		$plan->setTitle('plan d\'une table basse');
		$plan->setBody('Les cotes de la table basse en chêne, au format SVG.');
		$plan->addPicture($this->factory->picture($user, 'wood-6.jpg', 'plan'));
		$plan->addResource($this->factory->resource($user, 'plan.svg', 'plan', Resource::KIND_SVG, $this->factory->picture($user, 'wood-6.jpg', 'plan-resource')));
		$plan->setLicense(new License());
		foreach ($this->factory->tags('table,plan') as $tag) {
			$plan->addTag($tag);
		}
		$this->factory->preprocess($plan);
		$plan->setUser($user);
		$plan->setMainPicture($plan->getPictures()->first());
		$user->getMeta()->incrementPrivatePlanCount();
		$this->planUtils->generateKinds($plan);
		$this->factory->create($plan);
		$this->planUtils->createZipArchive($plan);
		$manager->flush();

		$this->planManager->publish($plan);

		// Workshop, as WorkshopController::createAction

		$workshop = new Workshop();
		$workshop->setTitle('mon atelier au fond du jardin');
		$workshop->addBodyBlock($this->factory->textBlock('Un atelier de **20 m²** avec un établi et une scie à ruban.'));
		$workshop->addPicture($this->factory->picture($user, 'wood-2.jpg', 'workshop'));
		$workshop->setArea(20);
		$workshop->setLocation('Lyon, France');
		$workshop->setLatitude(45.764043);
		$workshop->setLongitude(4.835659);
		$workshop->setLicense(new License());
		foreach ($this->factory->tags('atelier,jardin') as $tag) {
			$workshop->addTag($tag);
		}
		$this->factory->preprocess($workshop);
		$workshop->setUser($user);
		$workshop->setMainPicture($workshop->getPictures()->first());
		$user->getMeta()->incrementPrivateWorkshopCount();
		$this->factory->create($workshop);

		$this->workshopManager->publish($workshop);

		// Creation, as CreationController::createAction

		$creation = new Creation();
		$creation->setTitle('table basse en chêne');
		$creation->addBodyBlock($this->factory->textBlock('Une table basse en **chêne massif**, assemblée à tenons et mortaises.'));
		$creation->addBodyBlock($this->factory->galleryBlock(array( $this->factory->picture($user, 'wood-4.jpg', 'creation-block') )));
		$creation->addPicture($this->factory->picture($user, 'wood-1.jpg', 'creation-1'));
		$creation->addPicture($this->factory->picture($user, 'wood-3.jpg', 'creation-2'));
		$creation->setLicense(new License());
		foreach ($this->factory->tags('table,chêne,salon') as $tag) {
			$creation->addTag($tag);
		}
		foreach ($this->factory->woods('Chêne') as $wood) {
			$creation->addWood($wood);
		}
		foreach ($this->factory->tools('Rabot,Scie à ruban') as $tool) {
			$creation->addTool($tool);
		}
		foreach ($this->factory->finishes('Huile dure') as $finish) {
			$creation->addFinish($finish);
		}
		foreach ($this->factory->hardwares('Vis') as $hardware) {
			$creation->addHardware($hardware);
		}
		$creation->addPlan($plan);
		$creation->addHowto($this->getReference(HowtoFixtures::HOWTO));
		$creation->addWorkflow($this->getReference(WorkflowFixtures::WORKFLOW));
		$creation->addQuestion($this->getReference(QaFixtures::QUESTION));
		$creation->addProvider($this->getReference(KnowledgeFixtures::PROVIDER));
		$creation->addSchool($this->getReference(KnowledgeFixtures::SCHOOL));
		$this->factory->preprocess($creation);
		$creation->setUser($user);
		$creation->setMainPicture($creation->getPictures()->first());
		$user->getMeta()->incrementPrivateCreationCount();
		$this->factory->create($creation);

		$this->creationManager->publish($creation);

		// Interactions of "user2", and the answer of "user" to the comment

		$this->factory->actAs($user2);
		foreach (array( $plan, $workshop, $creation ) as $wonder) {
			$this->factory->comment($user2, $wonder, 'Superbe travail, bravo !');
			$this->factory->like($user2, $wonder);
		}
		$comment = $this->factory->comment($user2, $creation, 'Quelle huile as-tu utilisée ?');
		$this->feedback($manager, $user2, $creation);

		$this->factory->actAs($user);
		$this->factory->comment($user, $creation, 'Une huile dure, deux couches.', $comment);

		$this->addReference(self::CREATION, $creation);
		$this->addReference(self::PLAN, $plan);
		$this->addReference(self::WORKSHOP, $workshop);
	}

	/**
	 * As FeedbackController::createAction.
	 */
	private function feedback(ObjectManager $manager, User $user, Creation $creation): void {
		$feedback = new Feedback();
		$feedback->setEntityType($creation->getType());
		$feedback->setEntityId($creation->getId());
		$feedback->setUser($user);
		$feedback->setTitle('Un an plus tard');
		$feedback->addBodyBlock($this->factory->textBlock('La table n\'a pas bougé, l\'huile a bien tenu.'));
		$this->factory->preprocess($feedback);

		$creation->setChangedAt(new \DateTime());
		$creation->setUpdatedAt(new \DateTime());
		$creation->incrementFeedbackCount();
		$user->getMeta()->incrementFeedbackCount();

		$manager->persist($feedback);
		$manager->flush();

		$this->activityUtils->createFeedbackActivity($feedback, false);

		$this->factory->dispatch(PublicationListener::PUBLICATION_CHANGED, $creation);

		$manager->flush();
	}

}
