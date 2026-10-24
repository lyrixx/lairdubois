<?php

namespace Tests\Youtook;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Youtook\Took;
use Ladb\CoreBundle\Utils\VideoHostingUtils;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * Youtook: a short link (/yt/{id}.html) to a YouTube video, with its title and thumbnail from
 * the API of YouTube (Tests\Double\OfflineVideoHostingUtils). The took of the fixtures, of
 * "user", links to https://www.youtube.com/watch?v=aqz-KE-bpKQ.
 */
final class YoutookTest extends WebTestCase
{
    use XhrTrait;

    private const ID = 1;

    private const URL = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ';

    /**
     * The link redirects to the video, for anyone.
     *
     * @dataProvider provideUsers
     */
    public function testShow(?string $username): void
    {
        $client = $this->createClientFor($username);
        $client->request('GET', $this->path('show', ['id' => self::ID]));

        self::assertTrue($client->getResponse()->isRedirect(self::URL));
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
     * The page of the took, for the scraper of Facebook and from the site.
     *
     * @dataProvider provideShowPageClients
     */
    public function testShowPage(array $server): void
    {
        $client = static::createClient([], $server);
        $crawler = $client->request('GET', $this->path('show', ['id' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Fabriquer un maillet en frêne', $crawler->filter('title')->text());
        self::assertNotCount(0, $crawler->filter('iframe[src*="aqz-KE-bpKQ"]'));
        self::assertNotCount(0, $crawler->filter(\sprintf('a[href="%s"]', self::URL)));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideShowPageClients(): iterable
    {
        yield 'Facebook' => [['HTTP_USER_AGENT' => 'facebookexternalhit/1.1']];
        yield 'from the site' => [['HTTP_REFERER' => 'https://www.lairdubois.fr/creations/1.html']];
    }

    public function testShowUnknown(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->path('show', ['id' => 999999]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $method, string $action, array $parameters): void
    {
        $client = static::createClient();
        $client->request($method, $this->path($action, $parameters));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string, array<string, int>}>
     */
    public static function provideActions(): iterable
    {
        yield 'user list' => ['GET', 'user_list', []];
        yield 'create' => ['POST', 'create', []];
        yield 'edit' => ['GET', 'edit', ['id' => self::ID]];
        yield 'update' => ['POST', 'update', ['id' => self::ID]];
        yield 'delete' => ['GET', 'delete', ['id' => self::ID]];
    }

    /**
     * The tooks of the user, with the form of a new one.
     *
     * @dataProvider provideUserLists
     */
    public function testUserList(string $username, bool $listed): void
    {
        $client = static::createClientAs($username);
        $crawler = $client->request('GET', $this->path('user_list'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter(\sprintf('form[action="%s"]', $this->path('create'))));
        self::assertSame($listed, str_contains($crawler->text(), 'Fabriquer un maillet en frêne'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideUserLists(): iterable
    {
        yield 'author' => [UserFixtures::USER, true];
        yield 'other user' => [UserFixtures::USER2, false];
    }

    /**
     * All the tooks, for the admins only.
     *
     * @dataProvider provideListUsers
     */
    public function testList(?string $username, int $status): void
    {
        $client = $this->createClientFor($username);
        $crawler = $client->request('GET', $this->path('list'));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (200 === $status) {
            self::assertStringContainsString('Fabriquer un maillet en frêne', $crawler->text());
        }
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function provideListUsers(): iterable
    {
        yield 'anonymous' => [null, 404];
        yield 'user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * The video comes from the API of YouTube, its thumbnail becomes the picture of the took.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);

        $crawler = $this->submitNewForm($client, 'https://youtu.be/dQw4w9WgXcQ');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $took = $this->findLast();
        self::assertNotSame(self::ID, $took->getId());
        self::assertSame(UserFixtures::USER2, $took->getUser()->getUsername());
        self::assertSame([VideoHostingUtils::KIND_YOUTUBE, 'dQw4w9WgXcQ'], [$took->getKind(), $took->getEmbedIdentifier()]);
        self::assertSame('Affûter un ciseau à bois (dQw4w9WgXcQ)', $took->getTitle());
        self::assertSame('Une chaîne d\'ébénisterie', $took->getChannelTitle());
        self::assertSame('https://video.example/'.VideoHostingUtils::KIND_YOUTUBE.'/dQw4w9WgXcQ.jpg', $took->getThumbnailUrl());
        self::assertSame([$took->getThumbnailUrl()], $client->getContainer()->get('tests.picture_manager')->getUrls());
        self::assertNotNull($took->getMainPicture()->getWidth());
        // The JS follows the link to the page of the took
        self::assertStringEndsWith($this->path('show', ['id' => $took->getSluggedId()]), $crawler->filter('a')->attr('href'));
    }

    /**
     * The same video again only touches the took of the user.
     */
    public function testCreateExisting(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $lastId = $this->findLast()->getId();
        $changedAt = $this->find()->getChangedAt();

        $crawler = $this->submitNewForm($client, self::URL);

        self::assertSame($lastId, $this->findLast()->getId());
        self::assertGreaterThan($changedAt, $this->find()->getChangedAt());
        self::assertStringEndsWith($this->path('show', ['id' => $this->find()->getSluggedId()]), $crawler->filter('a')->attr('href'));
    }

    /**
     * Not a video of YouTube: the form comes back.
     */
    public function testCreateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $lastId = $this->findLast()->getId();

        $crawler = $this->submitNewForm($client, 'https://vimeo.com/76979871');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form'));
        self::assertSame($lastId, $this->findLast()->getId());
        self::assertSame([], $client->getContainer()->get('tests.picture_manager')->getUrls());
    }

    /**
     * @dataProvider provideEditors
     */
    public function testEdit(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('edit', ['id' => self::ID]));

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
        $crawler = $client->request('GET', $this->path('edit', ['id' => self::ID]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', $this->path('update', ['id' => self::ID])))->form([
            'ladb_youtook_edit_took[title]' => 'Un maillet en frêne, pas à pas',
        ]);

        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
        self::assertSame('Un maillet en frêne, pas à pas', $this->find()->getTitle());
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
        $client = static::createClientAs(UserFixtures::ADMIN);
        $crawler = $client->request('GET', $this->path('edit', ['id' => self::ID]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', $this->path('update', ['id' => self::ID])))->form([
            'ladb_youtook_edit_took[title]' => 'Un maillet en frêne, pas à pas',
        ]);

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('Fabriquer un maillet en frêne', $this->find()->getTitle());
    }

    /**
     * @dataProvider provideEditors
     */
    public function testDelete(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', $this->path('delete', ['id' => self::ID]));

        static::getEntityManager()->clear();
        if (200 === $status) {
            self::assertTrue($client->getResponse()->isRedirect($this->path('user_list')));
            self::assertNull($this->find());

            // Gone, as the other deleted publications
            $client = static::createClient();
            $client->request('GET', $this->path('show', ['id' => self::ID]));
            self::assertSame(410, $client->getResponse()->getStatusCode());
        } else {
            self::assertSame($status, $client->getResponse()->getStatusCode());
            self::assertNotNull($this->find());
        }
    }

    private function path(string $action, array $parameters = []): string
    {
        return static::generatePath('core_youtook_'.$action, $parameters);
    }

    private function createClientFor(?string $username): Client
    {
        return null === $username ? static::createClient() : static::createClientAs($username);
    }

    private function find(int $id = self::ID): ?Took
    {
        return static::getEntityManager()->getRepository(Took::class)->find($id);
    }

    private function findLast(): Took
    {
        return static::getEntityManager()->getRepository(Took::class)->findOneBy([], ['id' => 'DESC']);
    }

    /**
     * Posts a new took from the form of the list of the user, as its JS does (XHR).
     */
    private function submitNewForm(Client $client, string $url)
    {
        $crawler = $client->request('GET', $this->path('user_list'));
        $form = $crawler->filter(\sprintf('form[action="%s"]', $this->path('create')))->form(['ladb_youtook_new_took[url]' => $url]);

        return static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
    }
}
