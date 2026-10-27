<?php

namespace Tests\Message;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Message\Message;
use Ladb\CoreBundle\Entity\Message\Thread;
use Ladb\CoreBundle\Utils\MessageUtils;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The private messages: the thread of the fixtures (id 1) from "user2" to "user", unread by
 * "user". "admin" is no participant: a third party. Only the participants (or the members of a
 * team that is one) see a thread. Replies and edits are XHR, as on the site.
 */
final class ThreadTest extends WebTestCase
{
    use XhrTrait;

    private const THREAD_ID = 1;

    /**
     * @dataProvider provideAnonymousRoutes
     */
    public function testSendsAnonymousToLogin(string $route, array $parameters): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath($route, $parameters));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, array<string, int|string>}>
     */
    public static function provideAnonymousRoutes(): iterable
    {
        yield 'mailbox' => ['core_message_mailbox', []];
        yield 'new thread' => ['core_message_thread_new_recipientusername', ['recipientUsername' => UserFixtures::USER]];
        yield 'thread' => ['core_message_thread_show', ['id' => self::THREAD_ID]];
        yield 'delete' => ['core_message_thread_delete', ['id' => self::THREAD_ID]];
    }

    /**
     * @dataProvider provideMailboxes
     */
    public function testMailbox(string $username, string $filter, bool $listed): void
    {
        $client = static::createClientAs($username);
        // The filter "all" is the mailbox itself
        $path = 'all' === $filter ? static::generatePath('core_message_mailbox') : static::generatePath('core_message_mailbox_filter', ['filter' => $filter]);
        $crawler = $client->request('GET', $path);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount($listed ? 1 : 0, $crawler->filter(\sprintf('a[href="%s"]', static::generatePath('core_message_thread_show', ['id' => self::THREAD_ID]))));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideMailboxes(): iterable
    {
        yield 'recipient' => [UserFixtures::USER, 'all', true];
        yield 'sender' => [UserFixtures::USER2, 'all', true];
        yield 'sent by the sender' => [UserFixtures::USER2, 'sent', true];
        yield 'sent by the recipient' => [UserFixtures::USER, 'sent', false];
        yield 'third party' => [UserFixtures::ADMIN, 'all', false];
    }

    /**
     * Reading a thread flags its messages as read for the reader.
     */
    public function testShow(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $unreadMessageCount = static::findUser(UserFixtures::USER)->getMeta()->getUnreadMessageCount();
        self::assertGreaterThan(0, $unreadMessageCount);

        $crawler = $client->request('GET', $this->getShowPath());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Ta table basse', $crawler->filter('h2.ladb-subject')->text(null, false));
        self::assertSame($unreadMessageCount - 1, static::findUser(UserFixtures::USER)->getMeta()->getUnreadMessageCount());
    }

    public function testShowAsThirdParty(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->getShowPath());

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testNewToUnknownRecipient(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_message_thread_new_recipientusername', ['recipientUsername' => 'inconnu']));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * A new thread notifies its recipient: email, webpush notification and count of unread
     * messages.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $unreadMessageCount = static::findUser(UserFixtures::USER2)->getMeta()->getUnreadMessageCount();

        $this->submitNewThread($client, UserFixtures::USER2, 'Du chêne', 'Je l\'ai acheté à la scierie du lac.');

        $thread = $this->findLastThread();
        self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_message_thread_show', ['id' => $thread->getId()])));
        self::assertSame('Du chêne', $thread->getSubject());
        self::assertSame([UserFixtures::USER, UserFixtures::USER2], array_map(fn ($user) => $user->getUsername(), $thread->getParticipants()->toArray()));

        $emails = static::getSentEmails($client);
        self::assertCount(1, $emails);
        self::assertSame([UserFixtures::USER2.'@lairdubois.test'], static::getEmailRecipients($emails[0]));
        $webpushMessages = $client->getContainer()->get('tests.webpush_notification_producer')->getMessages();
        self::assertCount(1, $webpushMessages);
        self::assertStringStartsWith('Nouveau message de ', unserialize($webpushMessages[0]['body'])['body']);

        self::assertSame($unreadMessageCount + 1, static::findUser(UserFixtures::USER2)->getMeta()->getUnreadMessageCount());
    }

    /**
     * A thread to a team notifies its members.
     */
    public function testCreateToTeam(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);

        $this->submitNewThread($client, UserFixtures::TEAM, 'Portes ouvertes', 'Votre atelier sera-t-il ouvert samedi ?');

        $thread = $this->findLastThread();
        self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_message_thread_show', ['id' => $thread->getId()])));
        $recipients = array_merge(...array_map(fn (\Swift_Message $email) => static::getEmailRecipients($email), static::getSentEmails($client)));
        sort($recipients);
        self::assertSame([UserFixtures::USER2.'@lairdubois.test', UserFixtures::USER.'@lairdubois.test'], $recipients);

        // The members read the thread of their team, a third party does not
        $this->assertShowStatus(200, UserFixtures::USER, $thread->getId());
        $this->assertShowStatus(200, UserFixtures::USER2, $thread->getId());
    }

    public function testCreateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $lastId = $this->findLastThread()->getId();

        $this->submitNewThread($client, 'inconnu', 'Du chêne', '');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $client->getCrawler()->filter('.alert-danger'));
        self::assertSame($lastId, $this->findLastThread()->getId());
    }

    /**
     * The same subject and body from the same sender is spam: refused, and the team of the site
     * gets an email.
     */
    public function testCreateSpam(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $user = static::findUser(UserFixtures::USER);
        static::$container->get(MessageUtils::NAME)->composeThread($user, [static::findUser(UserFixtures::ADMIN)], 'Du chêne', 'Je l\'ai acheté à la scierie du lac.');
        $lastId = $this->findLastThread()->getId();

        $this->submitNewThread($client, UserFixtures::USER2, 'Du chêne', 'Je l\'ai acheté à la scierie du lac.');

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame($lastId, $this->findLastThread()->getId());
        $emails = static::getSentEmails($client);
        self::assertCount(1, $emails);
        self::assertSame(['contact@lairdubois.fr'], static::getEmailRecipients($emails[0]));
    }

    /**
     * A reply notifies the other participants.
     */
    public function testReply(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $unreadMessageCount = static::findUser(UserFixtures::USER2)->getMeta()->getUnreadMessageCount();

        $this->reply($client, 'Je l\'ai acheté à la scierie du lac.');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $message = $this->findLastMessage();
        self::assertSame(UserFixtures::USER, $message->getSender()->getUsername());
        self::assertSame(self::THREAD_ID, $message->getThread()->getId());
        self::assertSame($unreadMessageCount + 1, static::findUser(UserFixtures::USER2)->getMeta()->getUnreadMessageCount());
        $emails = static::getSentEmails($client);
        self::assertCount(1, $emails);
        self::assertSame([UserFixtures::USER2.'@lairdubois.test'], static::getEmailRecipients($emails[0]));
    }

    public function testReplyAsThirdParty(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $lastId = $this->findLastMessage()->getId();

        static::xhr($client, 'POST', static::generatePath('core_message_create', ['threadId' => self::THREAD_ID]), ['ladb_message_replymessage' => ['body' => 'Bonjour !']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame($lastId, $this->findLastMessage()->getId());
    }

    public function testReplyWithoutXhr(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_message_new', ['threadId' => self::THREAD_ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * Only its sender edits a message.
     *
     * @dataProvider provideEditors
     */
    public function testUpdate(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $message = $this->findLastMessage();
        $parameters = ['id' => $message->getId()];

        $crawler = static::xhr($client, 'GET', static::generatePath('core_message_edit', $parameters));
        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (200 === $status) {
            $form = $this->selectForm($crawler, static::generatePath('core_message_update', $parameters));
            $form['ladb_message_replymessage[body]'] = 'Bonjour Jeanne, où as-tu acheté ton chêne ?';
            static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
        } else {
            static::xhr($client, 'POST', static::generatePath('core_message_update', $parameters), ['ladb_message_replymessage' => ['body' => 'Modifié']]);
        }

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        $expected = 200 === $status ? 'Bonjour Jeanne, où as-tu acheté ton chêne ?' : 'Bonjour Jeanne, où as-tu acheté ton **chêne** ?';
        self::assertSame($expected, $this->findLastMessage()->getBody());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideEditors(): iterable
    {
        yield 'sender' => [UserFixtures::USER2, 200];
        yield 'recipient' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 404];
    }

    /**
     * A participant deletes the thread from their mailbox only; it is removed when all of them
     * did.
     */
    public function testDelete(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_message_thread_delete', ['id' => self::THREAD_ID]));

        self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_message_mailbox')));
        // The unread message is not counted any more
        self::assertSame(0, static::findUser(UserFixtures::USER)->getMeta()->getUnreadMessageCount());
        $this->assertMailboxLists(false, UserFixtures::USER);
        $this->assertMailboxLists(true, UserFixtures::USER2);

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', static::generatePath('core_message_thread_delete', ['id' => self::THREAD_ID]));

        self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_message_mailbox')));
        static::getEntityManager()->clear();
        self::assertNull(static::getEntityManager()->getRepository(Thread::class)->find(self::THREAD_ID));
    }

    public function testDeleteAsThirdParty(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', static::generatePath('core_message_thread_delete', ['id' => self::THREAD_ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertMailboxLists(true, UserFixtures::USER);
    }

    /**
     * A reply to a thread deleted by a participant puts it back in their mailbox.
     */
    public function testReplyRestoresTheThread(): void
    {
        static::createClientAs(UserFixtures::USER)->request('GET', static::generatePath('core_message_thread_delete', ['id' => self::THREAD_ID]));

        $this->reply(static::createClientAs(UserFixtures::USER2), 'Tu as reçu mon message ?');

        $this->assertMailboxLists(true, UserFixtures::USER);
    }

    /**
     * ladb:reset:unreadmessagecount counts the unread messages as reading the threads does,
     * replies and threads to a team included: they have no meta for their recipients.
     */
    public function testResetUnreadMessageCount(): void
    {
        $this->reply(static::createClientAs(UserFixtures::USER2), 'Tu as reçu mon message ?');
        // "user" gets it twice, directly and through the team: one unread message
        $this->submitNewThread(static::createClientAs(UserFixtures::ADMIN), UserFixtures::TEAM.','.UserFixtures::USER, 'Portes ouvertes', 'Votre atelier sera-t-il ouvert samedi ?');
        $usernames = [UserFixtures::ADMIN, UserFixtures::USER, UserFixtures::USER2, UserFixtures::TEAM];
        foreach ($usernames as $username) {
            static::findUser($username)->getMeta()->setUnreadMessageCount(42);
        }
        static::getEntityManager()->flush();

        [$exitCode, $output] = static::runCommand('ladb:reset:unreadmessagecount --force');

        self::assertSame(0, $exitCode, $output);
        static::getEntityManager()->clear();
        $unreadMessageCounts = [];
        foreach ($usernames as $username) {
            $unreadMessageCounts[$username] = static::findUser($username)->getMeta()->getUnreadMessageCount();
        }
        self::assertSame([UserFixtures::ADMIN => 0, UserFixtures::USER => 3, UserFixtures::USER2 => 1, UserFixtures::TEAM => 0], $unreadMessageCounts);
    }

    /**
     * Fills the form of a new thread to $recipient and submits it, with the profiler.
     */
    private function submitNewThread(Client $client, string $recipient, string $subject, string $body): void
    {
        $crawler = $client->request('GET', static::generatePath('core_message_thread_new'));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = $this->selectForm($crawler, static::generatePath('core_message_thread_create'));
        $form->setValues([
            'ladb_message_newthread[recipients]' => $recipient,
            'ladb_message_newthread[subject]' => $subject,
            'ladb_message_newthread[body]' => $body,
        ]);
        $client->enableProfiler();
        $client->submit($form);
    }

    /**
     * Replies $body to the thread of the fixtures, from the form of the site, with the profiler.
     */
    private function reply(Client $client, string $body): void
    {
        $parameters = ['threadId' => self::THREAD_ID];
        $crawler = static::xhr($client, 'GET', static::generatePath('core_message_new', $parameters));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = $this->selectForm($crawler, static::generatePath('core_message_create', $parameters));
        $form['ladb_message_replymessage[body]'] = $body;
        $client->enableProfiler();
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
    }

    private function assertShowStatus(int $status, string $username, int $id): void
    {
        $client = static::createClientAs($username);
        $client->request('GET', static::generatePath('core_message_thread_show', ['id' => $id]));

        self::assertSame($status, $client->getResponse()->getStatusCode(), \sprintf('Thread #%d as %s.', $id, $username));
    }

    private function assertMailboxLists(bool $listed, string $username): void
    {
        $client = static::createClientAs($username);
        $crawler = $client->request('GET', static::generatePath('core_message_mailbox'));

        self::assertCount($listed ? 1 : 0, $crawler->filter(\sprintf('a[href="%s"]', $this->getShowPath())), \sprintf('Mailbox of %s.', $username));
    }

    private function selectForm(Crawler $crawler, string $action): Form
    {
        return $crawler->filter(\sprintf('form[action="%s"]', $action))->form();
    }

    private function getShowPath(): string
    {
        return static::generatePath('core_message_thread_show', ['id' => self::THREAD_ID]);
    }

    private function findLastThread(): Thread
    {
        return static::getEntityManager()->getRepository(Thread::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function findLastMessage(): Message
    {
        return static::getEntityManager()->getRepository(Message::class)->findOneBy([], ['id' => 'DESC']);
    }
}
