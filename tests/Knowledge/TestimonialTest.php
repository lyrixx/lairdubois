<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Knowledge\School\Testimonial;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The testimonials of the former students of a school, one per user: the school of the fixtures
 * has the one of "user2". Its actions are XHR, except the deletion.
 */
final class TestimonialTest extends WebTestCase
{
    use XhrTrait;

    private const ID = 1;

    public function testShow(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_knowledge_school_testimonial_show', ['id' => $this->findTestimonialOfUser2()->getId()]));

        self::assertTrue($client->getResponse()->isRedirect());
    }

    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $testimonialCount = $this->findSchool()->getTestimonialCount();

        $crawler = static::xhr($client, 'GET', static::generatePath('core_knowledge_school_testimonial_new', ['id' => self::ID]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_knowledge_school_testimonial_create', ['id' => self::ID])))->form([
            'ladb_knowledge_school_testimonial[fromYear]' => '2015',
            'ladb_knowledge_school_testimonial[toYear]' => '2017',
            'ladb_knowledge_school_testimonial[diploma]' => 'BP Menuisier',
            'ladb_knowledge_school_testimonial[body]' => 'Deux années à l\'atelier, beaucoup de pratique.',
        ]);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $testimonial = $this->findTestimonial(UserFixtures::USER);
        self::assertNotNull($testimonial);
        self::assertSame('BP Menuisier', $testimonial->getDiploma());
        self::assertSame($testimonialCount + 1, $this->findSchool()->getTestimonialCount());
        self::assertSame(1, static::findUser(UserFixtures::USER)->getMeta()->getTestimonialCount());
    }

    public function testCreateTwice(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'POST', static::generatePath('core_knowledge_school_testimonial_create', ['id' => self::ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideEditors
     */
    public function testEdit(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath('core_knowledge_school_testimonial_edit', ['id' => $this->findTestimonialOfUser2()->getId()]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 200];
        yield 'other user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    public function testUpdate(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $testimonial = $this->findTestimonialOfUser2();

        $crawler = static::xhr($client, 'GET', static::generatePath('core_knowledge_school_testimonial_edit', ['id' => $testimonial->getId()]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_knowledge_school_testimonial_update', ['id' => $testimonial->getId()])))->form([
            'ladb_knowledge_school_testimonial[diploma]' => 'BP Menuisier',
        ]);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('BP Menuisier', $this->findTestimonialOfUser2()->getDiploma());
    }

    /**
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, int $status): void
    {
        $testimonialId = $this->findTestimonialOfUser2()->getId();
        $testimonialCount = $this->findSchool()->getTestimonialCount();

        $client = static::createClientAs($username);
        $client->request('GET', static::generatePath('core_knowledge_school_testimonial_delete', ['id' => $testimonialId]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_school_show', ['id' => $this->findSchool()->getSluggedId()])));
            self::assertNull(static::getEntityManager()->getRepository(Testimonial::class)->find($testimonialId));
            self::assertSame($testimonialCount - 1, $this->findSchool()->getTestimonialCount());
        } else {
            self::assertNotNull(static::getEntityManager()->getRepository(Testimonial::class)->find($testimonialId));
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

    private function findSchool(): School
    {
        return static::getEntityManager()->getRepository(School::class)->find(self::ID);
    }

    private function findTestimonialOfUser2(): Testimonial
    {
        return $this->findTestimonial(UserFixtures::USER2);
    }

    private function findTestimonial(string $username): ?Testimonial
    {
        return static::getEntityManager()->getRepository(Testimonial::class)->findOneBy([
            'school' => $this->findSchool(),
            'user' => static::findUser($username),
        ]);
    }
}
