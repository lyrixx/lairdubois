<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Report;
use Ladb\CoreBundle\Entity\Wonder\Creation;

/**
 * The reports of abuse, from the modal of every content: a GET form, whose entity the JS fills.
 * The team of the site gets an email.
 */
final class ReportTest extends InteractionTestCase
{
    public function testCreateSendsAnonymousToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_report_create', $this->getParameters()));

        self::assertRedirectsToLogin($client);
    }

    /**
     * @dataProvider provideReporters
     */
    public function testCreate(string $username): void
    {
        $client = static::createClientAs($username);
        $client->enableProfiler();
        $showPath = static::generatePath('core_creation_show', ['id' => static::findCreation()->getSluggedId()]);

        $client->request('GET', static::generatePath('core_report_create', $this->getParameters() + ['rtu' => $showPath]));

        self::assertTrue($client->getResponse()->isRedirect($showPath));
        $report = static::getEntityManager()->getRepository(Report::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame($username, $report->getUser()->getUsername());
        self::assertSame(Creation::TYPE, $report->getEntityType());
        self::assertSame(self::CREATION_ID, $report->getEntityId());
        self::assertSame(Report::REASON_SPAM, $report->getReason());

        $emails = static::getSentEmails($client);
        self::assertCount(1, $emails);
        self::assertSame(['contact@lairdubois.fr'], static::getEmailRecipients($emails[0]));

        $client->followRedirect();
        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideReporters(): iterable
    {
        yield 'author' => [UserFixtures::USER];
        yield 'other user' => [UserFixtures::USER2];
    }

    public function testCreateOnUnknownContent(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', static::generatePath('core_report_create', ['entity_id' => 999999] + $this->getParameters() + ['rtu' => '/']));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @return array{entity_type: int, entity_id: int, reason: int}
     */
    private function getParameters(): array
    {
        return ['entity_type' => Creation::TYPE, 'entity_id' => self::CREATION_ID, 'reason' => Report::REASON_SPAM];
    }
}
