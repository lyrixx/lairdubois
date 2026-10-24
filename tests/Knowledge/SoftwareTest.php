<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\Software;

final class SoftwareTest extends KnowledgeTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_software';
    }

    protected static function getEntityClass(): string
    {
        return Software::class;
    }

    protected function getNewFormValues(): array
    {
        return [
            'ladb_knowledge_newsoftware[identityValue][name]' => 'SketchUp',
            'ladb_knowledge_newsoftware[iconValue][data]' => static::createPicture('test-software'),
        ];
    }

    protected function getProposal(): array
    {
        return [Software::FIELD_PUBLISHER, ['ladb_knowledge_value_text[data]' => 'Trimble'], 'Trimble'];
    }
}
