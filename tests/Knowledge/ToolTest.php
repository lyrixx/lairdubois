<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\Tool;

final class ToolTest extends KnowledgeTestCase
{
    // Its brand, on top of its mandatory values
    protected const NEW_VALUE_COUNT = 3;

    protected static function getRoutePrefix(): string
    {
        return 'core_tool';
    }

    protected static function getEntityClass(): string
    {
        return Tool::class;
    }

    protected function getNewFormValues(): array
    {
        return [
            'ladb_knowledge_newtool[nameValue][data]' => 'ciseau à bois',
            'ladb_knowledge_newtool[photoValue][data]' => static::createPicture('test-tool'),
            'ladb_knowledge_newtool[brandValue][data]' => 'Pfeil',
        ];
    }

    protected function getProposal(): array
    {
        return [Tool::FIELD_BRAND, ['ladb_knowledge_value_text[data]' => 'Stanley'], 'Stanley'];
    }

    /**
     * Its name starts with a capital letter.
     */
    public function testCreateCapitalizesTheName(): void
    {
        $this->testCreate();

        self::assertSame('Ciseau à bois', $this->findLast()->getTitle());
    }
}
