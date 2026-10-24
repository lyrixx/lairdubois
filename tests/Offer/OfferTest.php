<?php

namespace Tests\Offer;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Publish;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Tests\Double\OfflineLocalisableUtils;
use Tests\PublicationTestCase;

/**
 * The offer of the fixtures sells a workbench (kind offer, category tool).
 */
final class OfferTest extends PublicationTestCase
{
    protected const KEEPS_ITS_ACTIVITIES_WHEN_UNPUBLISHED = true;

    protected static function getRoutePrefix(): string
    {
        return 'core_offer';
    }

    protected static function getEntityClass(): string
    {
        return Offer::class;
    }

    protected static function getCounterName(): string
    {
        return 'Offer';
    }

    protected function getNewFormValues(string $owner): array
    {
        return [
            'ladb_offer_offer[title]' => 'rabot n° 5',
            'ladb_offer_offer[pictures]' => static::createPicture($owner, 'test-offer'),
            'ladb_offer_offer[bodyBlocks][0][body]' => 'Rabot métallique, fer affûté.',
            'ladb_offer_offer[kind]' => (string) Offer::KIND_OFFER,
            'ladb_offer_offer[category]' => (string) Offer::CATEGORY_TOOL,
            'ladb_offer_offer[rawPrice]' => '45',
            'ladb_offer_offer[currency]' => 'EUR',
            'ladb_offer_offer[location]' => 'Bordeaux, France',
            'ladb_offer_offer[tags]' => 'rabot',
        ];
    }

    /**
     * An offer can be deleted by its author once published: it is republishable.
     */
    public static function provideDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER, 302];
        yield 'other user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    /**
     * OfferType formats the price of an offer, and geocodes its location.
     */
    public function testCreateFormatsThePrice(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, $this->getNewFormValues(UserFixtures::USER));

        $offer = $this->findLast();
        self::assertSame(45, $offer->getRawPrice());
        // A no-break space before the currency symbol, from \NumberFormatter
        self::assertMatchesRegularExpression('/^45\s€$/u', $offer->getPrice());
        self::assertEqualsWithDelta(OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['latitude'], $offer->getLatitude(), 0.000001);
    }

    public function testCreateFree(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, ['ladb_offer_offer[rawPrice]' => '0'] + $this->getNewFormValues(UserFixtures::USER));

        self::assertSame('Gratuit', $this->findLast()->getPrice());
    }

    /**
     * A request has no price, and does not need a picture.
     */
    public function testCreateRequest(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $this->submitNewForm($client, UserFixtures::USER, [
            'ladb_offer_offer[kind]' => (string) Offer::KIND_REQUEST,
            'ladb_offer_offer[pictures]' => '',
        ] + $this->getNewFormValues(UserFixtures::USER));

        $offer = $this->findLast();
        self::assertSame(Offer::KIND_REQUEST, $offer->getKind());
        self::assertSame('', $offer->getPrice());
        self::assertSame(0, $offer->getRawPrice());
        self::assertCount(0, $offer->getPictures());
    }

    public function testCreateOfferWithoutPicture(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $lastId = $this->findLast()->getId();

        $client->submit($this->getNewForm($client, UserFixtures::USER)->setValues([
            'ladb_offer_offer[pictures]' => '',
        ] + $this->getNewFormValues(UserFixtures::USER)));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $client->getCrawler()->filter('.alert-danger'));
        self::assertSame($lastId, $this->findLast()->getId());
    }

    /**
     * Each publication counts: past Offer::MAX_PUBLISH_COUNT, the offer stays a draft.
     */
    public function testRepublish(): void
    {
        $publishCount = $this->find(static::ID)->getPublishCount();
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame($publishCount + 1, $this->find(static::ID)->getPublishCount());
        self::assertTrue($this->isListed($this->getShowPath()));
        $this->assertShowStatus(200, null);
    }

    /**
     * The first publication notifies the followers, not the next ones.
     */
    public function testPublishCreatesAnActivityOnce(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $this->submitNewForm($client, UserFixtures::USER, $this->getNewFormValues(UserFixtures::USER));
        $id = $this->findLast()->getId();

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => $id]));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->countActivities($id));

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('unpublish', ['id' => $id, 'rtu' => '/']));
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => $id]));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->countActivities($id));
    }

    public function testRepublishPastTheMaximum(): void
    {
        $this->unpublish();
        $this->find(static::ID)->setPublishCount(Offer::MAX_PUBLISH_COUNT);
        static::getEntityManager()->flush();

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('publish', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertTrue($this->find(static::ID)->getIsDraft());
    }

    /**
     * The form of a new message to the author, filled with the offer.
     */
    public function testMessage(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $crawler = $client->request('GET', $this->path('message_new', ['id' => static::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString($this->find(static::ID)->getTitle(), $crawler->filter('input[name="ladb_message_newthread[subject]"]')->attr('value'));
    }

    public function testMessageToOneself(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('message_new', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testMessageAsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->path('message_new', ['id' => static::ID]));

        self::assertRedirectsToLogin($client);
    }

    public function testMessageDraft(): void
    {
        $this->unpublish();

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', $this->path('message_new', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * An admin turns an offer into a question: the page of the offer redirects to it.
     */
    public function testConvertToQuestion(): void
    {
        $title = $this->find(static::ID)->getTitle();
        $publicCount = $this->getCounts(UserFixtures::USER)[1];
        $publicQuestionCount = static::findUser(UserFixtures::USER)->getMeta()->getPublicQuestionCount();
        $showPath = $this->getShowPath();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('offer_admin_converttoquestion', ['id' => static::ID]));

        $question = static::getEntityManager()->getRepository(Question::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame($title, $question->getTitle());
        self::assertSame(UserFixtures::USER, $question->getUser()->getUsername());
        $questionPath = static::$kernel->getContainer()->get('router')->generate('core_qa_question_show', ['id' => $question->getSluggedId()]);
        self::assertTrue($client->getResponse()->isRedirect($questionPath));
        self::assertNull($this->find(static::ID));
        self::assertSame($publicCount - 1, $this->getCounts(UserFixtures::USER)[1]);
        self::assertSame($publicQuestionCount + 1, static::findUser(UserFixtures::USER)->getMeta()->getPublicQuestionCount());

        $client = static::createClient();
        $client->request('GET', $showPath);
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith($questionPath, $client->getResponse()->headers->get('Location'));
    }

    public function testConvertToQuestionAsAuthor(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('offer_admin_converttoquestion', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->find(static::ID));
    }

    private function countActivities(int $id): int
    {
        return \count(static::getEntityManager()->getRepository(Publish::class)->findBy(['entityType' => Offer::TYPE, 'entityId' => $id]));
    }
}
