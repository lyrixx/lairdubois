<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Feedback as FeedbackActivity;
use Ladb\CoreBundle\Entity\Core\Feedback;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

/**
 * The feedbacks ("retours d'expérience") of a creation, a workshop or an event, in XHR as on the
 * site: only the author of the content (or a participant of the event) writes one. On the
 * creation of the fixtures, which has a feedback of "user2".
 */
final class FeedbackTest extends InteractionTestCase
{
    public function testShowRedirectsToTheFeedback(): void
    {
        $client = static::createClient();
        $feedback = $this->findFeedbackOf(UserFixtures::USER2);
        $client->request('GET', static::generatePath('core_feedback_show', ['id' => $feedback->getId()]));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith(static::findCreation()->getSluggedId().'.html#_feedback_'.$feedback->getId(), $client->getResponse()->headers->get('Location'));
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $route, string $method): void
    {
        $client = static::createClient();
        $parameters = \in_array($route, ['core_feedback_new', 'core_feedback_create'], true) ? $this->getNewParameters() : ['id' => $this->findFeedbackOf(UserFixtures::USER2)->getId()];
        static::xhr($client, $method, static::generatePath($route, $parameters));

        self::assertRedirectsToLogin($client);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideActions(): iterable
    {
        yield 'new' => ['core_feedback_new', 'GET'];
        yield 'create' => ['core_feedback_create', 'POST'];
        yield 'edit' => ['core_feedback_edit', 'GET'];
        yield 'update' => ['core_feedback_update', 'POST'];
        yield 'delete' => ['core_feedback_delete', 'GET'];
    }

    public function testNewWithoutXhr(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_feedback_new', $this->getNewParameters()));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * A feedback counts on the content and its author, and makes an activity.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $feedbackCount = static::findCreation()->getFeedbackCount();
        $userFeedbackCount = static::findUser(UserFixtures::USER)->getMeta()->getFeedbackCount();

        $this->submitNewForm($client);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $feedback = $this->findFeedbackOf(UserFixtures::USER);
        self::assertSame('Deux ans plus tard', $feedback->getTitle());
        self::assertSame($feedbackCount + 1, static::findCreation()->getFeedbackCount());
        self::assertSame($userFeedbackCount + 1, static::findUser(UserFixtures::USER)->getMeta()->getFeedbackCount());
        self::assertNotNull(static::getEntityManager()->getRepository(FeedbackActivity::class)->findOneBy(['feedback' => $feedback]));
    }

    /**
     * @dataProvider provideOtherUsers
     */
    public function testCreateAsOtherUser(string $username): void
    {
        $client = static::createClientAs($username);
        $feedbackCount = static::findCreation()->getFeedbackCount();

        $this->submitNewForm($client);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame($feedbackCount, static::findCreation()->getFeedbackCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOtherUsers(): iterable
    {
        yield 'other user' => [UserFixtures::USER2];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    public function testNewOnDraft(): void
    {
        static::unpublishCreation();
        $client = static::createClientAs(UserFixtures::USER);

        static::xhr($client, 'GET', static::generatePath('core_feedback_new', $this->getNewParameters()));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideEditors
     */
    public function testUpdate(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $feedback = $this->findFeedbackOf(UserFixtures::USER2);
        $parameters = ['id' => $feedback->getId()];

        $crawler = static::xhr($client, 'GET', static::generatePath('core_feedback_edit', $parameters));
        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (200 !== $status) {
            static::xhr($client, 'POST', static::generatePath('core_feedback_update', $parameters), ['ladb_core_feedback' => ['title' => 'Trois ans plus tard']]);
            self::assertSame(404, $client->getResponse()->getStatusCode());

            return;
        }

        $form = $this->selectForm($crawler, static::generatePath('core_feedback_update', $parameters));
        $form['ladb_core_feedback[title]'] = 'Trois ans plus tard';
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        self::assertSame('Trois ans plus tard', $this->findFeedbackOf(UserFixtures::USER2)->getTitle());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 200];
        yield 'other user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * @dataProvider provideEditors
     */
    public function testDelete(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $feedbackCount = static::findCreation()->getFeedbackCount();

        $client->request('GET', static::generatePath('core_feedback_delete', ['id' => $this->findFeedbackOf(UserFixtures::USER2)->getId()]));

        static::getEntityManager()->clear();
        if (404 === $status) {
            self::assertSame(404, $client->getResponse()->getStatusCode());
            self::assertNotNull($this->findFeedbackOf(UserFixtures::USER2));

            return;
        }
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith(static::findCreation()->getSluggedId().'.html', $client->getResponse()->headers->get('Location'));
        self::assertNull($this->findFeedbackOf(UserFixtures::USER2));
        self::assertSame($feedbackCount - 1, static::findCreation()->getFeedbackCount());
    }

    /**
     * Fills the form of a new feedback on the creation and submits it.
     */
    private function submitNewForm(KernelBrowser $client): void
    {
        $crawler = static::xhr($client, 'GET', static::generatePath('core_feedback_new', $this->getNewParameters()));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = $this->selectForm($crawler, static::generatePath('core_feedback_create', $this->getNewParameters()));
        $form->setValues([
            'ladb_core_feedback[title]' => 'Deux ans plus tard',
            'ladb_core_feedback[bodyBlocks][0][body]' => 'Toujours aussi solide.',
        ]);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
    }

    private function selectForm(Crawler $crawler, string $action): Form
    {
        return $crawler->filter(\sprintf('form[action="%s"]', $action))->form();
    }

    /**
     * @return array{entityType: int, entityId: int}
     */
    private function getNewParameters(): array
    {
        return ['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID];
    }

    private function findFeedbackOf(string $username): ?Feedback
    {
        return static::getEntityManager()->getRepository(Feedback::class)->findOneBy([
            'entityType' => Creation::TYPE,
            'entityId' => self::CREATION_ID,
            'user' => static::findUser($username),
        ]);
    }
}
