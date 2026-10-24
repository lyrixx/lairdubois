<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Review as ReviewActivity;
use Ladb\CoreBundle\Entity\Core\Review;
use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Entity\Knowledge\Software;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The reviews of the books and software, one per user: the book of the fixtures has the one of
 * "user2" (5 stars). Its actions are XHR, except the deletion.
 */
final class ReviewTest extends WebTestCase
{
    use XhrTrait;

    private const ID = 1;

    public function testNewForNewAccount(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        static::xhr($client, 'GET', $this->getNewPath(Book::TYPE));

        // The accounts of the fixtures are just created
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideReviewables
     */
    public function testCreate(int $entityType, string $entityClass): void
    {
        $client = $this->createClientOfOldAccount(UserFixtures::ADMIN);
        $reviewCount = $this->findReviewable($entityClass)->getReviewCount();

        $crawler = static::xhr($client, 'GET', $this->getNewPath($entityType));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_review_create', ['entityType' => $entityType, 'entityId' => self::ID])))->form([
            'ladb_core_review[title]' => 'Pas mal',
            'ladb_core_review[rating]' => '2',
            'ladb_core_review[body]' => 'Utile, mais incomplet sur les finitions.',
        ]);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $review = $this->findLastReview();
        self::assertSame(UserFixtures::ADMIN, $review->getUser()->getUsername());
        self::assertEquals($entityType, $review->getEntityType());
        $reviewable = $this->findReviewable($entityClass);
        self::assertSame($reviewCount + 1, $reviewable->getReviewCount());
        self::assertSame(1, static::findUser(UserFixtures::ADMIN)->getMeta()->getReviewCount());
        self::assertNotNull(static::getEntityManager()->getRepository(ReviewActivity::class)->findOneBy(['review' => $review]));
    }

    /**
     * @return iterable<string, array{int, class-string}>
     */
    public static function provideReviewables(): iterable
    {
        yield 'book' => [Book::TYPE, Book::class];
        yield 'software' => [Software::TYPE, Software::class];
    }

    /**
     * The average of the ratings: 5 stars from "user2", 2 from the admin.
     */
    public function testCreateComputesTheAverageRating(): void
    {
        $this->testCreate(Book::TYPE, Book::class);

        self::assertEquals(3.5, $this->findReviewable(Book::class)->getAverageRating());
    }

    public function testCreateTwice(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'POST', static::generatePath('core_review_create', ['entityType' => Book::TYPE, 'entityId' => self::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testEditAsOtherUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'GET', static::generatePath('core_review_edit', ['id' => $this->findReviewOfUser2()->getId()]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * The template of the form misses a variable, for its author too: #50.
     *
     * @dataProvider provideEditors
     */
    public function testEdit(string $username): void
    {
        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath('core_review_edit', ['id' => $this->findReviewOfUser2()->getId()]));

        self::assertSame(500, $client->getResponse()->getStatusCode(), 'The form passes now: remove the known failure (#50).');
        self::markTestIncomplete('Known failure, see #50.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER2];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    /**
     * Without its edit form (#50), the update takes the token of the form of a new review, of the
     * same name.
     */
    public function testUpdate(): void
    {
        $client = $this->createClientOfOldAccount(UserFixtures::USER2);
        $review = $this->findReviewOfUser2();
        $crawler = static::xhr($client, 'GET', $this->getNewPath(Book::TYPE));
        $values = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_review_create', ['entityType' => Book::TYPE, 'entityId' => self::ID])))->form([
            'ladb_core_review[title]' => $review->getTitle(),
            'ladb_core_review[rating]' => '3',
            'ladb_core_review[body]' => $review->getBody(),
        ])->getPhpValues();

        static::xhr($client, 'POST', static::generatePath('core_review_update', ['id' => $review->getId()]), $values);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(3, $this->findReviewOfUser2()->getRating());
        self::assertEquals(3, $this->findReviewable(Book::class)->getAverageRating());

        // Not for the others
        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', static::generatePath('core_review_update', ['id' => $review->getId()]), $values);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, int $status): void
    {
        $reviewId = $this->findReviewOfUser2()->getId();
        $reviewCount = $this->findReviewable(Book::class)->getReviewCount();

        $client = static::createClientAs($username);
        $client->request('GET', static::generatePath('core_review_delete', ['id' => $reviewId]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        if (302 === $status) {
            self::assertStringEndsWith(static::generatePath('core_book_show', ['id' => $this->findReviewable(Book::class)->getSluggedId()]), $client->getResponse()->headers->get('Location'));
            self::assertNull(static::getEntityManager()->getRepository(Review::class)->find($reviewId));
            self::assertSame($reviewCount - 1, $this->findReviewable(Book::class)->getReviewCount());
        } else {
            self::assertNotNull(static::getEntityManager()->getRepository(Review::class)->find($reviewId));
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 302];
        yield 'other user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    /**
     * Only the accounts of more than 7 days review: the ones of the fixtures are just created.
     */
    private function createClientOfOldAccount(string $username): Client
    {
        $client = static::createClientAs($username);
        static::findUser($username)->setCreatedAt(new \DateTime('-8 days'));
        static::getEntityManager()->flush();

        return $client;
    }

    private function getNewPath(int $entityType): string
    {
        return static::generatePath('core_review_new', ['entityType' => $entityType, 'entityId' => self::ID]);
    }

    /**
     * @param class-string<Book|Software> $entityClass
     */
    private function findReviewable(string $entityClass): Book|Software
    {
        return static::getEntityManager()->getRepository($entityClass)->find(self::ID);
    }

    private function findReviewOfUser2(): Review
    {
        return static::getEntityManager()->getRepository(Review::class)->findOneBy([
            'entityType' => Book::TYPE,
            'entityId' => self::ID,
            'user' => static::findUser(UserFixtures::USER2),
        ]);
    }

    private function findLastReview(): Review
    {
        return static::getEntityManager()->getRepository(Review::class)->findOneBy([], ['id' => 'DESC']);
    }
}
