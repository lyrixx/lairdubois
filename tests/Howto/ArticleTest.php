<?php

namespace Tests\Howto;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Howto\Article;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\WebTestCase;

/**
 * The articles of a howto. They have no author of their own: the rights are the ones of their
 * howto, except for the unpublication, by an admin only. Article 1 belongs to howto 1, both
 * published, by "user".
 */
final class ArticleTest extends WebTestCase
{
    private const ID = 1;
    private const HOWTO_ID = 1;

    /**
     * @dataProvider provideUsers
     */
    public function testShow(?string $username): void
    {
        $client = null === $username ? static::createClient() : static::createClientAs($username);
        $crawler = $client->request('GET', $this->path('core_howto_article_show', ['id' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsStringIgnoringCase($this->findArticle()->getTitle(), $crawler->filter('title')->text());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideUsers(): iterable
    {
        yield 'anonymous' => [null];
        yield 'author' => [UserFixtures::USER];
        yield 'other user' => [UserFixtures::USER2];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    /**
     * An article is shown as its howto: gone with its unpublication, whatever its own state.
     */
    public function testShowOfUnpublishedHowto(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('core_howto_unpublish', ['id' => self::HOWTO_ID, 'rtu' => '/']));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        foreach ([UserFixtures::USER => 200, UserFixtures::USER2 => 410] as $username => $status) {
            $client = static::createClientAs($username);
            $client->request('GET', $this->path('core_howto_article_show', ['id' => self::ID]));
            self::assertSame($status, $client->getResponse()->getStatusCode(), $username);
        }
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $method, string $route, int $id): void
    {
        $client = static::createClient();
        $client->request($method, $this->path($route, ['id' => $id]));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideActions(): iterable
    {
        yield 'new' => ['GET', 'core_howto_article_new', self::HOWTO_ID];
        yield 'create' => ['POST', 'core_howto_article_create', self::HOWTO_ID];
        yield 'edit' => ['GET', 'core_howto_article_edit', self::ID];
        yield 'update' => ['POST', 'core_howto_article_update', self::ID];
        yield 'publish' => ['GET', 'core_howto_article_publish', self::ID];
        yield 'unpublish' => ['GET', 'core_howto_article_unpublish', self::ID];
        yield 'delete' => ['GET', 'core_howto_article_delete', self::ID];
    }

    /**
     * The new articles of a published howto are drafts.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $draftCount = $this->findHowto()->getDraftArticleCount();

        $this->submitNewForm($client);

        self::assertTrue($client->getResponse()->isRedirect($this->path('core_howto_edit', ['id' => self::HOWTO_ID]).'#articles'));
        $article = $this->findLastArticle();
        self::assertSame(self::HOWTO_ID, $article->getHowto()->getId());
        self::assertTrue($article->getIsDraft());
        self::assertSame($draftCount + 1, $this->findHowto()->getDraftArticleCount());
    }

    /**
     * The new articles of a draft howto are published with it.
     */
    public function testCreateInDraftHowto(): void
    {
        $this->unpublish('core_howto_unpublish', self::HOWTO_ID);
        $publishedCount = $this->findHowto()->getPublishedArticleCount();

        $this->submitNewForm($client = static::createClientAs(UserFixtures::USER));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertFalse($this->findLastArticle()->getIsDraft());
        self::assertSame($publishedCount + 1, $this->findHowto()->getPublishedArticleCount());
    }

    public function testNewAsOtherUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', $this->path('core_howto_article_new', ['id' => self::HOWTO_ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideEditors
     */
    public function testEdit(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('core_howto_article_edit', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER, 200];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * @dataProvider provideUpdaters
     */
    public function testUpdate(string $username): void
    {
        $client = static::createClientAs($username);
        $crawler = $client->request('GET', $this->path('core_howto_article_edit', ['id' => self::ID]));
        $form = $crawler->filter(\sprintf('form[action^="%s"]', $this->path('core_howto_article_update', ['id' => self::ID])))->form();
        $form['ladb_howto_howtoarticle[title]'] = 'Scier les queues';

        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
        self::assertSame('Scier les queues', $this->findArticle()->getTitle());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUpdaters(): iterable
    {
        yield 'author' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    public function testUpdateAsOtherUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('POST', $this->path('core_howto_article_update', ['id' => self::ID]), ['ladb_howto_howtoarticle' => ['title' => 'Scier les queues']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotSame('Scier les queues', $this->findArticle()->getTitle());
    }

    /**
     * @dataProvider provideUnpublishers
     */
    public function testUnpublish(string $username, int $status): void
    {
        $publishedCount = $this->findHowto()->getPublishedArticleCount();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('core_howto_article_unpublish', ['id' => self::ID, 'rtu' => '/']));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(302 === $status, $this->findArticle()->getIsDraft());
        self::assertSame(302 === $status ? $publishedCount - 1 : $publishedCount, $this->findHowto()->getPublishedArticleCount());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideUnpublishers(): iterable
    {
        yield 'author' => [UserFixtures::USER, 404];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    /**
     * @dataProvider providePublishers
     */
    public function testPublish(string $username, int $status): void
    {
        $this->unpublish('core_howto_article_unpublish', self::ID);
        $publishedCount = $this->findHowto()->getPublishedArticleCount();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('core_howto_article_publish', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(302 !== $status, $this->findArticle()->getIsDraft());
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect($this->path('core_howto_show', ['id' => $this->findHowto()->getSluggedId()]).'#'.$this->findArticle()->getSluggedId()));
            self::assertSame($publishedCount + 1, $this->findHowto()->getPublishedArticleCount());
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function providePublishers(): iterable
    {
        yield 'author' => [UserFixtures::USER, 302];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    /**
     * The author deletes an article of a published howto, unlike the howto itself.
     *
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, int $status): void
    {
        $publishedCount = $this->findHowto()->getPublishedArticleCount();

        $client = static::createClientAs($username);
        $client->request('GET', $this->path('core_howto_article_delete', ['id' => self::ID]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect($this->path('core_howto_edit', ['id' => self::HOWTO_ID])));
            self::assertNull($this->findArticle());
            self::assertSame($publishedCount - 1, $this->findHowto()->getPublishedArticleCount());
            $client = static::createClient();
            $client->request('GET', $this->path('core_howto_article_show', ['id' => self::ID]));
            self::assertSame(410, $client->getResponse()->getStatusCode());
        } else {
            self::assertNotNull($this->findArticle());
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER, 302];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    private function path(string $route, array $parameters): string
    {
        return static::$kernel->getContainer()->get('router')->generate($route, $parameters);
    }

    /**
     * From the database: the entity manager of a request which deleted the article still has it.
     */
    private function findArticle(): ?Article
    {
        static::getEntityManager()->clear();

        return static::getEntityManager()->getRepository(Article::class)->find(self::ID);
    }

    private function findLastArticle(): Article
    {
        return static::getEntityManager()->getRepository(Article::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function findHowto(): Howto
    {
        return static::getEntityManager()->getRepository(Howto::class)->find(self::HOWTO_ID);
    }

    private function unpublish(string $route, int $id): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path($route, ['id' => $id, 'rtu' => '/']));
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    private function submitNewForm(Client $client): void
    {
        $crawler = $client->request('GET', $this->path('core_howto_article_new', ['id' => self::HOWTO_ID]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', $this->path('core_howto_article_create', ['id' => self::HOWTO_ID])))->form();
        $form->setValues([
            'ladb_howto_howtoarticle[title]' => 'ajuster l\'assemblage',
            'ladb_howto_howtoarticle[bodyBlocks][0][body]' => 'Ajuster au ciseau, sans forcer.',
        ]);

        $client->submit($form);
    }
}
