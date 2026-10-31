<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Blog\Post;
use Ladb\CoreBundle\Entity\Core\Tip;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Faq\Question;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\Blog\PostManager;
use Ladb\CoreBundle\Manager\Faq\QuestionManager;

/**
 * The contents only the admin writes: a blog post, a FAQ question and a tip (id 1 each).
 */
final class EditorialFixtures extends Fixture implements DependentFixtureInterface {

	private $factory;
	private $faqQuestionManager;
	private $postManager;

	public function __construct(
		FixtureFactory $factory,
		QuestionManager $faqQuestionManager,
		PostManager $postManager
	) {
		$this->factory = $factory;
		$this->faqQuestionManager = $faqQuestionManager;
		$this->postManager = $postManager;
	}

	public function getDependencies(): array {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager): void {
		$admin = $this->getReference(UserFixtures::ADMIN, User::class);
		$user = $this->getReference(UserFixtures::USER, User::class);

		$this->factory->actAs($admin);

		// Blog post, as PostController::createAction

		$post = new Post();
		$post->setTitle('bienvenue sur L\'Air du Bois');
		$post->addBodyBlock($this->factory->textBlock('L\'Air du Bois est une plateforme **collaborative** de partage autour du travail du bois.'));
		$post->setMainPicture($this->factory->picture($admin, 'wood-1.jpg', 'blog-post'));
		foreach ($this->factory->tags('annonce') as $tag) {
			$post->addTag($tag);
		}
		$this->factory->preprocess($post);
		$post->setUser($admin);
		$this->factory->create($post);

		$this->postManager->publish($post);

		// FAQ question, as Faq\QuestionController::createAction

		$question = new Question();
		$question->setTitle('comment publier une création ?');
		$question->setIcon('creation');
		$question->addBodyBlock($this->factory->textBlock('Depuis le menu **Créer**, choisissez « Création » et ajoutez vos photos.'));
		$question->addBodyBlock($this->factory->galleryBlock(array( $this->factory->picture($admin, 'wood-2.jpg', 'faq-question') )));
		foreach ($this->factory->tags('aide') as $tag) {
			$question->addTag($tag);
		}
		$this->factory->preprocess($question);
		$question->setMainPicture($this->factory->firstPicture($question));
		$question->setUser($admin);
		$this->factory->create($question);

		$this->faqQuestionManager->publish($question);

		// Tip, as TipController::createAction

		$tip = new Tip();
		$tip->setBody('Pour éviter les éclats, collez un **ruban de masquage** sur la ligne de coupe.');
		$tip->setUrl('https://www.lairdubois.fr');
		$this->factory->preprocess($tip);
		$this->factory->create($tip);
		$this->factory->dispatch(PublicationListener::PUBLICATION_PUBLISHED, $tip);

		$this->factory->actAs($user);
		$this->factory->comment($user, $post, 'Merci pour ce site !');
		$this->factory->like($user, $post);
		$this->factory->like($user, $question);
	}

}
