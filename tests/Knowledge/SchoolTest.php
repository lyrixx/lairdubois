<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\School;

/**
 * The testimonials of its former students: TestimonialTest.
 */
final class SchoolTest extends KnowledgeTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_school';
    }

    protected static function getEntityClass(): string
    {
        return School::class;
    }

    protected function getNewFormValues(): array
    {
        return [
            'ladb_knowledge_newschool[nameValue][data]' => 'Lycée du Bois',
            'ladb_knowledge_newschool[logoValue][data]' => static::createPicture('test-school'),
        ];
    }

    protected function getProposal(): array
    {
        return [School::FIELD_DIPLOMAS, ['ladb_knowledge_value_text[data]' => 'CAP Ébéniste'], 'CAP Ébéniste'];
    }
}
