<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Like as LikeActivity;
use Ladb\CoreBundle\Entity\Core\Notification;
use Ladb\CoreBundle\Entity\Wonder\Creation;

/**
 * The notifications of a user, that ladb:cron:notification:populate makes from the activities
 * (castor app:db:fixtures runs it): the list (in a modal of the site) and the redirection of a
 * notification to its content. "user" is notified of the comments, likes, reviews... of "user2".
 */
final class NotificationTest extends InteractionTestCase
{
    public function testListSendsAnonymousToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_notification_list'));

        self::assertRedirectsToLogin($client);
    }

    /**
     * The list flags the notifications as listed, and resets the count of the fresh ones (the
     * badge of the menu).
     */
    public function testList(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        static::findUser(UserFixtures::USER)->getMeta()->setFreshNotificationCount(3);
        static::getEntityManager()->flush();
        $notification = $this->findLikeNotification();
        self::assertFalse($notification->getIsListed());

        $crawler = $client->request('GET', static::generatePath('core_notification_list'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('#ladb_notification_'.$notification->getId()));
        self::assertTrue($this->findLikeNotification()->getIsListed());
        self::assertSame(0, static::findUser(UserFixtures::USER)->getMeta()->getFreshNotificationCount());
    }

    /**
     * @dataProvider provideFilters
     */
    public function testListFilter(string $filter, bool $listed): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', static::generatePath('core_notification_list_filter', ['filter' => $filter]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount($listed ? 1 : 0, $crawler->filter('#ladb_notification_'.$this->findLikeNotification()->getId()));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideFilters(): iterable
    {
        yield 'likes' => ['activity-like', true];
        yield 'comments' => ['activity-comment', false];
    }

    public function testListOfOtherUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $crawler = $client->request('GET', static::generatePath('core_notification_list'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('#ladb_notification_'.$this->findLikeNotification()->getId()));
    }

    /**
     * A notification redirects to its content, and is flagged as shown.
     */
    public function testShow(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $notification = $this->findLikeNotification();
        $client->request('GET', static::generatePath('core_notification_show', ['id' => $notification->getId()]));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith(static::findCreation()->getSluggedId().'.html', $client->getResponse()->headers->get('Location'));
        self::assertTrue($this->findLikeNotification()->getIsShown());
    }

    public function testShowSendsAnonymousToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_notification_show', ['id' => $this->findLikeNotification()->getId()]));

        self::assertRedirectsToLogin($client);
    }

    /**
     * The action does not check whose notification it is: #68.
     */
    public function testShowAsOtherUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', static::generatePath('core_notification_show', ['id' => $this->findLikeNotification()->getId()]));

        self::assertTrue($client->getResponse()->isRedirect(), 'The action refuses it now: remove the known failure (#68).');
        self::markTestIncomplete('Known failure, see #68.');
    }

    /**
     * The notification of "user" for the like of "user2" on the creation.
     */
    private function findLikeNotification(): Notification
    {
        $notifications = static::getEntityManager()->getRepository(Notification::class)->findBy(['user' => static::findUser(UserFixtures::USER)]);
        foreach ($notifications as $notification) {
            $activity = $notification->getActivity();
            if ($activity instanceof LikeActivity && Creation::TYPE === $activity->getLike()->getEntityType()) {
                return $notification;
            }
        }

        throw new \LogicException('The fixtures have no notification of the like of the creation.');
    }
}
