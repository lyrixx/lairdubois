<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\View;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Bundle\FrameworkBundle\Client;

/**
 * The views of a content: a page sends them to the "view" queue, and its consumer counts them, on
 * the creation of the fixtures (by "user").
 */
final class ViewTest extends InteractionTestCase
{
    public function testShowByAnAnonymousVisitor(): void
    {
        $client = static::createClient();
        $client->request('GET', '/creations/1.html');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $messages = self::getViewMessages($client);
        self::assertSame([['kind' => View::KIND_SHOWN, 'entityType' => Creation::TYPE, 'entityIds' => [self::CREATION_ID], 'userId' => null]], $messages);

        // Once per session
        $client->request('GET', '/creations/1.html');
        self::assertSame([], self::getViewMessages($client));

        $viewCount = static::findCreation()->getViewCount();
        self::consume($messages[0]);
        self::assertSame($viewCount + 1, static::findCreation()->getViewCount());
    }

    public function testShowByAnotherAccount(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', '/creations/1.html');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $user2 = static::findUser(UserFixtures::USER2);
        $messages = self::getViewMessages($client);
        self::assertSame([['kind' => View::KIND_SHOWN, 'entityType' => Creation::TYPE, 'entityIds' => [self::CREATION_ID], 'userId' => $user2->getId()]], $messages);

        $viewCount = static::findCreation()->getViewCount();
        self::consume($messages[0]);
        self::assertSame($viewCount + 1, static::findCreation()->getViewCount());
        $view = self::findShownView($user2->getId());
        self::assertNotNull($view);

        // Counted once a day
        self::consume($messages[0]);
        self::assertSame($viewCount + 1, static::findCreation()->getViewCount());

        $view->setCreatedAt(new \DateTime('-2 days'));
        static::getEntityManager()->flush();
        self::consume($messages[0]);
        self::assertSame($viewCount + 2, static::findCreation()->getViewCount());
        static::getEntityManager()->refresh($view);
        self::assertGreaterThan(new \DateTime('-1 hour'), $view->getCreatedAt());
    }

    public function testShowByItsAuthor(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', '/creations/1.html');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $messages = self::getViewMessages($client);
        self::assertCount(1, $messages);

        // Not counted
        $viewCount = static::findCreation()->getViewCount();
        self::consume($messages[0]);
        self::assertSame($viewCount, static::findCreation()->getViewCount());
    }

    public function testList(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', '/creations/');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $user2 = static::findUser(UserFixtures::USER2);
        $messages = self::getViewMessages($client);
        self::assertCount(1, $messages);
        self::assertSame(View::KIND_LISTED, $messages[0]['kind']);
        self::assertSame(Creation::TYPE, $messages[0]['entityType']);
        self::assertContains(self::CREATION_ID, $messages[0]['entityIds']);
        self::assertSame($user2->getId(), $messages[0]['userId']);

        $repository = static::getEntityManager()->getRepository(View::class);
        $criteria = ['entityType' => Creation::TYPE, 'entityId' => $messages[0]['entityIds'], 'user' => $user2->getId(), 'kind' => View::KIND_LISTED];
        self::consume($messages[0]);
        self::assertCount(\count($messages[0]['entityIds']), $repository->findBy($criteria));

        // Once per content
        self::consume($messages[0]);
        self::assertCount(\count($messages[0]['entityIds']), $repository->findBy($criteria));
    }

    public function testListByAnAnonymousVisitor(): void
    {
        $client = static::createClient();
        $client->request('GET', '/creations/');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], self::getViewMessages($client));
    }

    /**
     * The messages sent to the "view" queue by the last request of the client.
     *
     * @return list<array{kind: int, entityType: int, entityIds: list<int>, userId: ?int}>
     */
    private static function getViewMessages(Client $client): array
    {
        return array_map(
            fn (array $message) => unserialize($message['body']),
            $client->getContainer()->get('tests.view_producer')->getMessages(),
        );
    }

    /**
     * @param array{kind: int, entityType: int, entityIds: list<int>, userId: ?int} $message
     */
    private static function consume(array $message): void
    {
        self::assertTrue(static::$container->get('tests.view_consumer')->execute(new AMQPMessage(serialize($message))));
    }

    private static function findShownView(int $userId): ?View
    {
        return static::getEntityManager()->getRepository(View::class)->findOneBy(['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID, 'user' => $userId, 'kind' => View::KIND_SHOWN]);
    }
}
