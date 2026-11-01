<?php

namespace Tests\Form;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Event\Event;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The defaults of Symfony 7 that Symfony 8 changes, kept by the site (#223): the URL field of an
 * event (a UrlType guessed from #[Assert\Url]) and the placeholder of a required choice.
 */
final class DefaultsTest extends WebTestCase
{
    use XhrTrait;

    private const EVENT_ID = 1;

    /**
     * default_protocol of UrlType: "http" (null in Symfony 8.0).
     */
    public function testUrlTypedWithoutProtocolGetsHttp(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->updateEventUrl($client, 'www.lairdubois.fr/evenements');

        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
        self::assertSame('http://www.lairdubois.fr/evenements', $this->findEvent()->getUrl());
    }

    /**
     * requireTld of the Url constraint: false (true in Symfony 8.0).
     */
    public function testUrlWithoutTopLevelDomainIsValid(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->updateEventUrl($client, 'http://atelier/stage');

        self::assertCount(1, $client->getCrawler()->filter('.alert-success'));
        self::assertSame('http://atelier/stage', $this->findEvent()->getUrl());
    }

    /**
     * placeholder_attr of ChoiceType: empty ("hidden" on the placeholder of a required collapsed
     * choice in Symfony 8.1). The source type of the values of a book.
     */
    public function testPlaceholderOfARequiredChoiceIsNotHidden(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', static::generatePath('core_book_new'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $placeholders = $crawler->filterXPath('//option[normalize-space(.) = "Choisissez un type de source"]');
        self::assertGreaterThan(0, $placeholders->count());
        $placeholders->each(static function (Crawler $placeholder): void {
            self::assertNull($placeholder->attr('hidden'));
        });
    }

    private function updateEventUrl(KernelBrowser $client, string $url): void
    {
        $crawler = $client->request('GET', static::generatePath('core_event_edit', ['id' => self::EVENT_ID]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_event_update', ['id' => self::EVENT_ID])))->form();
        $form['ladb_event_event[url]'] = $url;

        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    private function findEvent(): Event
    {
        static::getEntityManager()->clear();

        return static::getEntityManager()->getRepository(Event::class)->find(self::EVENT_ID);
    }
}
