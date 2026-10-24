<?php

namespace Tests\Qa;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Tests\PublicationTestCase;

/**
 * The answers of the question of the fixtures are in AnswerTest.
 */
final class QuestionTest extends PublicationTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_qa_question';
    }

    protected static function getEntityClass(): string
    {
        return Question::class;
    }

    protected static function getCounterName(): string
    {
        return 'Question';
    }

    protected function getNewFormValues(string $owner): array
    {
        return [
            'ladb_qa_question[title]' => 'quelle colle pour du chêne en extérieur ?',
            'ladb_qa_question[bodyBlocks][0][body]' => 'Une colle qui résiste à la pluie, pour un banc de jardin.',
            'ladb_qa_question[tags]' => 'colle,chêne',
        ];
    }

    /**
     * An admin turns a question into an offer: the page of the question redirects to it.
     */
    public function testConvertToOffer(): void
    {
        $title = $this->find(static::ID)->getTitle();
        $publicCount = $this->getCounts(UserFixtures::USER)[1];
        $showPath = $this->getShowPath();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('admin_converttooffer', ['id' => static::ID]));

        $offer = static::getEntityManager()->getRepository(Offer::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame($title, $offer->getTitle());
        self::assertSame(UserFixtures::USER, $offer->getUser()->getUsername());
        $offerPath = static::$kernel->getContainer()->get('router')->generate('core_offer_show', ['id' => $offer->getSluggedId()]);
        self::assertTrue($client->getResponse()->isRedirect($offerPath));
        self::assertNull($this->find(static::ID));
        self::assertSame($publicCount - 1, $this->getCounts(UserFixtures::USER)[1]);

        $client = static::createClient();
        $client->request('GET', $showPath);
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith($offerPath, $client->getResponse()->headers->get('Location'));
    }

    public function testConvertToOfferAsAuthor(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', $this->path('admin_converttooffer', ['id' => static::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->find(static::ID));
    }
}
