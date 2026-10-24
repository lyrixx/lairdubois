<?php

namespace Tests\Knowledge;

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
}
