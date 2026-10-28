<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\Qa\QuestionManager;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;

/**
 * A question (id 1) of "user", answered by "user2" (answer id 1).
 */
final class QaFixtures extends Fixture implements DependentFixtureInterface {

	public const QUESTION = 'qa-question';

	private $activityUtils;
	private $factory;
	private $qaQuestionManager;
	private $watchableUtils;

	public function __construct(
		ActivityUtils $activityUtils,
		FixtureFactory $factory,
		QuestionManager $qaQuestionManager,
		WatchableUtils $watchableUtils
	) {
		$this->activityUtils = $activityUtils;
		$this->factory = $factory;
		$this->qaQuestionManager = $qaQuestionManager;
		$this->watchableUtils = $watchableUtils;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager): void {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		// As QuestionController::createAction and publishAction

		$this->factory->actAs($user);

		$question = new Question();
		$question->setTitle('quelle essence pour un plan de travail ?');
		$question->addBodyBlock($this->factory->textBlock('Je cherche un bois **dur** et stable pour le plan de travail de ma cuisine.'));
		$question->addBodyBlock($this->factory->galleryBlock(array( $this->factory->picture($user, 'wood-2.jpg', 'qa-question') )));
		foreach ($this->factory->tags('cuisine,plan de travail') as $tag) {
			$question->addTag($tag);
		}
		$this->factory->preprocess($question);
		$question->setMainPicture($this->factory->firstPicture($question));
		$question->setUser($user);
		$user->getMeta()->incrementPrivateQuestionCount();
		$this->factory->create($question);

		$this->qaQuestionManager->publish($question);

		// As AnswerController::createAction

		$this->factory->actAs($user2);

		$answer = new Answer();
		$answer->setQuestion($question);
		$answer->setUser($user2);
		$answer->addBodyBlock($this->factory->textBlock('Le hêtre lamellé-collé fait très bien l\'affaire, avec une finition à l\'huile dure.'));
		$this->factory->preprocess($answer);
		$answer->setParentEntity($question);
		$answer->setParentEntityField('bestAnswer');
		$answer->setUpdatedAt(new \DateTime());
		$question->addAnswer($answer);
		$question->setChangedAt(new \DateTime());
		$question->incrementAnswerCount();
		$user2->getMeta()->incrementAnswerCount();
		$manager->persist($answer);
		$manager->flush();
		$this->qaQuestionManager->computeAnswerCounters($question);
		$this->activityUtils->createAnswerActivity($answer, false);
		$this->factory->dispatch(PublicationListener::PUBLICATION_CHANGED, $question);
		$this->watchableUtils->autoCreateWatch($question, $user2);
		$manager->flush();

		$this->factory->comment($user2, $question, 'Quelle épaisseur envisages-tu ?');
		$this->factory->like($user2, $question);

		$this->factory->actAs($user);
		$this->factory->vote($user, $answer, $question, 1);

		$this->addReference(self::QUESTION, $question);
	}

}
