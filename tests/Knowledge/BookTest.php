<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Knowledge\Book;

final class BookTest extends KnowledgeTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_book';
    }

    protected static function getEntityClass(): string
    {
        return Book::class;
    }

    protected function getNewFormValues(): array
    {
        return [
            'ladb_knowledge_newbook[identityValue][work]' => 'Le Guide du tourneur',
            'ladb_knowledge_newbook[coverValue][data]' => static::createPicture('test-book'),
        ];
    }

    protected function getProposal(): array
    {
        return [Book::FIELD_EDITOR, ['ladb_knowledge_value_text[data]' => 'Éditions du Copeau'], 'Éditions du Copeau'];
    }

    /**
     * A language is proposed by its code, and named in the locale of the site.
     */
    public function testProposeLanguage(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);

        static::proposeValue($client, $this->find(static::ID), Book::FIELD_LANGUAGE, ['ladb_knowledge_value_language[rawLanguage]' => 'fr']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('français', static::findLastValue()->getData());
        self::assertSame('français', $this->find(static::ID)->getLanguage());
    }

    /**
     * An unknown code is refused by the choices of the field, an empty one by the validation.
     *
     * @dataProvider provideInvalidLanguages
     */
    public function testProposeInvalidLanguage(string $rawLanguage, string $error): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $lastId = static::findLastValue()->getId();

        static::proposeValue($client, $this->find(static::ID), Book::FIELD_LANGUAGE, ['ladb_knowledge_value_language[rawLanguage]' => $rawLanguage]);

        // The form again, with its error
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($error, $client->getCrawler()->filter('.alert-danger')->text());
        self::assertSame($lastId, static::findLastValue()->getId());
    }

    /**
     * @return iterable<string, array{string, string}> The code and the error of the form
     */
    public static function provideInvalidLanguages(): iterable
    {
        yield 'unknown' => ['xx', 'Cette valeur n\'est pas valide.'];
        yield 'empty' => ['', 'Cette valeur ne doit pas être vide.'];
    }
}
