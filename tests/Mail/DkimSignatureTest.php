<?php

namespace Tests\Mail;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Message;
use Tests\WebTestCase;

/**
 * Each email is signed with DKIM when the transport sends it (DkimSignedMessageListener), in the worker of the
 * queue "mailer": the one that Mailer puts in the queue, that static::getSentEmails() returns, is not.
 */
final class DkimSignatureTest extends WebTestCase
{
    /**
     * The confirmation of the email address: text and HTML.
     */
    public function testEmailIsSigned(): void
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['email_confirmed' => 0], ['username_canonical' => UserFixtures::USER]);
        $client = static::createClientAs(UserFixtures::USER);

        $client->enableProfiler();
        $client->request('GET', '/email/check');

        $this->assertSentSigned($client, 'Confirmation de votre adresse e-mail');
    }

    /**
     * Not signed by Swiftmailer, as FOSUserBundle sent it.
     */
    public function testForgottenPasswordEmailIsSigned(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/resetting/request');

        $client->enableProfiler();
        $client->submit($crawler->filter('form[action="/resetting/send-email"]')->form(['username' => UserFixtures::USER]));

        $this->assertSentSigned($client, 'Réinitialisation de votre mot de passe');
    }

    private function assertSentSigned(KernelBrowser $client, string $subject): void
    {
        $emails = static::getSentEmails($client);
        self::assertCount(1, $emails);
        self::assertFalse($emails[0]->getHeaders()->has('DKIM-Signature'));
        self::assertSame([], self::getSentByTransport());

        $queued = static::getContainer()->get('tests.mailer_transport')->getSent();
        self::assertCount(1, $queued);
        static::consumeMessage($queued[0]->getMessage(), 'mailer');

        $sent = self::getSentByTransport();
        self::assertCount(1, $sent);
        $headers = $sent[0]->getHeaders();
        self::assertSame($subject, $headers->getHeaderBody('Subject'));
        $tags = [];
        foreach (explode(';', $headers->get('DKIM-Signature')->getBodyAsString()) as $tag) {
            [$name, $value] = explode('=', trim($tag), 2);
            $tags[$name] = $value;
        }
        self::assertSame('rsa-sha256', $tags['a']);
        self::assertSame('relaxed/relaxed', $tags['c']);
        self::assertSame('lairdubois.fr', $tags['d']);
        self::assertSame('dkim', $tags['s']);
    }

    /**
     * @return list<Message>
     */
    private static function getSentByTransport(): array
    {
        $sent = [];
        foreach (static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents() as $event) {
            if (!$event->isQueued()) {
                $sent[] = $event->getMessage();
            }
        }

        return $sent;
    }
}
