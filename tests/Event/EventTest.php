<?php

namespace Tests\Event;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Join;
use Ladb\CoreBundle\Entity\Event\Event;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\Double\OfflineLocalisableUtils;
use Tests\PublicationTestCase;
use Tests\XhrTrait;

/**
 * The event of the fixtures takes place in a month, in Lyon; "user2" joins it.
 */
final class EventTest extends PublicationTestCase
{
    use XhrTrait;

    // A form with a time answers 500, and the edit form of the event of the fixtures has its times
    protected const KNOWN_FAILURES = [
        'testCreateWithTimes' => 60,
        'testUpdate with data set "author"' => 60,
        'testUpdate with data set "admin"' => 60,
    ];

    protected static function getRoutePrefix(): string
    {
        return 'core_event';
    }

    protected static function getEntityClass(): string
    {
        return Event::class;
    }

    protected static function getCounterName(): string
    {
        return 'Event';
    }

    protected function getNewFormValues(string $owner): array
    {
        return [
            'ladb_event_event[title]' => 'stage de tournage',
            'ladb_event_event[pictures]' => static::createPicture($owner, 'test-event'),
            'ladb_event_event[bodyBlocks][0][body]' => 'Deux jours pour apprendre à tourner un bol.',
            'ladb_event_event[location]' => 'Bordeaux, France',
            'ladb_event_event[startDate]' => '15/06/2030',
            'ladb_event_event[endDate]' => '16/06/2030',
            'ladb_event_event[tags]' => 'tournage,stage',
        ];
    }

    /**
     * The form gives the dates and the times apart, EventType computes when the event starts and
     * ends: without times, it lasts whole days. The location is geocoded.
     */
    public function testCreateComputesTheDates(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getNewFormValues(UserFixtures::USER));

        $event = $this->findLast();
        self::assertSame('2030-06-15 00:00', $event->getStartAt()->format('Y-m-d H:i'));
        self::assertSame('2030-06-17 00:00', $event->getEndAt()->format('Y-m-d H:i'));
        self::assertSame(Event::STATUS_SCHEDULED, $event->getStatus());
        self::assertEqualsWithDelta(OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['latitude'], $event->getLatitude(), 0.000001);
    }

    public function testCreateWithTimes(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, [
            'ladb_event_event[startTime]' => '09:00',
            'ladb_event_event[endTime]' => '17:30',
        ] + $this->getNewFormValues(UserFixtures::USER));

        $event = $this->findLast();
        self::assertSame('2030-06-15 09:00', $event->getStartAt()->format('Y-m-d H:i'));
        self::assertSame('2030-06-16 17:30', $event->getEndAt()->format('Y-m-d H:i'));
    }

    /**
     * @dataProvider provideUsers
     */
    public function testLocation(?string $username): void
    {
        $client = $this->createClientFor($username);
        $client->request('GET', $this->path('location', ['id' => static::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $geoJson = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([4.835659, 45.764043], $geoJson['features'][0]['geometry']['coordinates']);
    }

    public function testJoin(): void
    {
        $joinCount = $this->find(static::ID)->getJoinCount();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->join($client);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($joinCount + 1, $this->find(static::ID)->getJoinCount());
        self::assertNotNull($this->findJoin(UserFixtures::ADMIN));

        // Only once
        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->join($client);
        self::assertSame($joinCount + 1, $this->find(static::ID)->getJoinCount());
    }

    public function testJoinDraft(): void
    {
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->join($client);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNull($this->findJoin(UserFixtures::ADMIN));
    }

    public function testJoinAsAnonymous(): void
    {
        $client = static::createClient();
        $this->join($client);

        self::assertRedirectsToLogin($client);
    }

    /**
     * @dataProvider provideUnjoiners
     */
    public function testUnjoin(string $username, int $status): void
    {
        $joinCount = $this->find(static::ID)->getJoinCount();
        $joinId = $this->findJoin(UserFixtures::USER2)->getId();

        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath('core_join_delete', ['id' => $joinId]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(200 === $status ? $joinCount - 1 : $joinCount, $this->find(static::ID)->getJoinCount());
        self::assertSame(200 !== $status, null !== $this->findJoin(UserFixtures::USER2));
    }

    /**
     * Only the one who joins leaves, even the admins cannot.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function provideUnjoiners(): iterable
    {
        yield 'the one who joins' => [UserFixtures::USER2, 200];
        yield 'author' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 404];
    }

    private function join(Client $client): void
    {
        static::xhr($client, 'GET', static::generatePath('core_join_create', ['entityType' => Event::TYPE, 'entityId' => static::ID]));
    }


    private function findJoin(string $username): ?Join
    {
        return static::getEntityManager()->getRepository(Join::class)->findOneBy([
            'entityType' => Event::TYPE,
            'entityId' => static::ID,
            'user' => static::findUser($username),
        ]);
    }
}
