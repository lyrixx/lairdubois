<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Vote as VoteActivity;
use Ladb\CoreBundle\Entity\Core\Vote;
use Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Tests\Knowledge\ValueTrait;

/**
 * The votes, the same for every votable content: their rights, the change of a vote and the
 * list of the votes of a content. On the name "Chêne" of the wood of the fixtures, proposed by
 * "user". The votes of tests/Knowledge/ValueTest.php (up, down with a reason, deletion, own
 * value) and the ones of the answers of a question are not tested again here.
 */
final class VoteTest extends InteractionTestCase
{
    use ValueTrait;

    private const WOOD_ID = 1;

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $route): void
    {
        $client = static::createClient();
        static::xhr($client, 'GET', static::generatePath($route, $this->getRouteParameters($route)));

        self::assertRedirectsToLogin($client);
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionWithoutXhr(string $route): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('POST', static::generatePath($route, $this->getRouteParameters($route)));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideActions(): iterable
    {
        yield 'new' => ['core_vote_new'];
        yield 'create' => ['core_vote_create'];
        yield 'delete' => ['core_vote_delete'];
    }

    /**
     * @dataProvider provideNewActions
     */
    public function testNewWithUnconfirmedEmail(string $route): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        static::findUser(UserFixtures::USER2)->setEmailConfirmed(false);
        static::getEntityManager()->flush();

        static::xhr($client, 'core_vote_new' === $route ? 'GET' : 'POST', static::generatePath($route, $this->getRouteParameters($route)));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->findNameValue()->getVoteCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNewActions(): iterable
    {
        yield 'new' => ['core_vote_new'];
        yield 'create' => ['core_vote_create'];
    }

    /**
     * A vote makes an activity, and lists its user in the votes of the content.
     */
    public function testVoteIsListed(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        static::vote($client, $this->findNameValue(), 'up');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $vote = $this->findVoteOf(UserFixtures::USER2);
        self::assertNotNull(static::getEntityManager()->getRepository(VoteActivity::class)->findOneBy(['vote' => $vote]));

        $client = static::createClient();
        $crawler = $client->request('GET', static::generatePath('core_vote_list_parent_entity', ['entityType' => Wood::TYPE, 'entityId' => self::WOOD_ID]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $crawler->filter('a[href="/@user2"]'));

        $crawler = $client->request('GET', static::generatePath('core_vote_list_parent_entity_filter', ['entityType' => Wood::TYPE, 'entityId' => self::WOOD_ID, 'filter' => 'negative']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('a[href="/@user2"]'));
    }

    public function testVoteTwice(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $this->voteWithFixtureFactory(UserFixtures::USER2, 1);

        static::xhr($client, 'POST', static::generatePath('core_vote_create', $this->getRouteParameters('core_vote_create')));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->findNameValue()->getVoteCount());
    }

    /**
     * A user changes their vote: it moves from the positive counts to the negative ones, and its
     * activity is made again.
     */
    public function testChangeVote(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $this->voteWithFixtureFactory(UserFixtures::USER2, 1);
        $positiveVoteCount = static::findUser(UserFixtures::USER2)->getMeta()->getPositiveVoteCount();
        $negativeVoteCount = static::findUser(UserFixtures::USER2)->getMeta()->getNegativeVoteCount();

        static::vote($client, $this->findNameValue(), 'down', 'C\'est le nom du genre, pas de l\'espèce.');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $value = $this->findNameValue();
        self::assertSame(-1, $value->getVoteScore());
        self::assertSame(1, $value->getVoteCount());
        self::assertSame(-1, $this->findVoteOf(UserFixtures::USER2)->getScore());
        self::assertSame($positiveVoteCount - 1, static::findUser(UserFixtures::USER2)->getMeta()->getPositiveVoteCount());
        self::assertSame($negativeVoteCount + 1, static::findUser(UserFixtures::USER2)->getMeta()->getNegativeVoteCount());
        self::assertCount(1, static::getEntityManager()->getRepository(VoteActivity::class)->findBy(['vote' => $this->findVoteOf(UserFixtures::USER2)]));
    }

    /**
     * A vote for the name, without the controller, whose lock against double submissions would
     * refuse the next vote of the same user for 3 seconds.
     */
    private function voteWithFixtureFactory(string $username, int $score): void
    {
        $value = $this->findNameValue();
        $wood = static::getEntityManager()->getRepository(Wood::class)->find(self::WOOD_ID);
        static::$kernel->getContainer()->get('tests.fixture_factory')->vote(static::findUser($username), $value, $wood, $score);
    }

    /**
     * @return array<string, int|string>
     */
    private function getRouteParameters(string $route): array
    {
        if ('core_vote_delete' === $route) {
            // The vote of "user" for an answer of the fixtures
            return ['id' => static::getEntityManager()->getRepository(Vote::class)->findOneBy([])->getId()];
        }

        return ['entityType' => $this->findNameValue()->getType(), 'entityId' => $this->findNameValue()->getId(), 'way' => 'up'];
    }

    /**
     * The name "Chêne" of the wood, proposed by "user".
     */
    private function findNameValue(): BaseValue
    {
        return static::getEntityManager()->getRepository(BaseValue::class)->findOneBy([
            'parentEntityType' => Wood::TYPE,
            'parentEntityId' => self::WOOD_ID,
            'parentEntityField' => Wood::FIELD_NAME,
        ]);
    }

    private function findVoteOf(string $username): ?Vote
    {
        $value = $this->findNameValue();

        return static::getEntityManager()->getRepository(Vote::class)->findOneBy([
            'entityType' => $value->getType(),
            'entityId' => $value->getId(),
            'user' => static::findUser($username),
        ]);
    }
}
