<?php

namespace Tests\Double;

use Ladb\CoreBundle\Utils\SearchUtils;

/**
 * Search utils of the test environment (config_test.yml): keeps what each search of a request
 * found in the index, in its order (Tests\Search\FacetTest).
 */
final class RecordingSearchUtils extends SearchUtils
{
    /** @var list<array{string, string, list<int>, int}> */
    private array $searches = [];

    public function searchEntitiesCount($filters, $indexServiceId, $excludedIds = null)
    {
        $count = parent::searchEntitiesCount($filters, $indexServiceId, $excludedIds);
        $this->searches[] = [$this->getIndexName($indexServiceId), 'count', [], $count];

        return $count;
    }

    public function searchEntities(&$filters, &$sort, $indexServiceId, $entityClassName, $offset, $limit, $excludedIds = null)
    {
        $result = parent::searchEntities($filters, $sort, $indexServiceId, $entityClassName, $offset, $limit, $excludedIds);
        if (null !== $result->resultSet) {
            $ids = [];
            foreach ($result->resultSet->getResults() as $hit) {
                $ids[] = (int) $hit->getId();
            }
            $this->searches[] = [$this->getIndexName($indexServiceId), self::describeSort($sort), $ids, $result->resultSet->getTotalHits()];
        }

        return $result;
    }

    /**
     * The searches of the requests since the last reboot of the kernel: type, sort ("score" for the
     * relevance), ids of the page in their order, total.
     *
     * @return list<array{string, string, list<int>, int}>
     */
    public function getSearches(): array
    {
        return $this->searches;
    }

    private function getIndexName(string $indexServiceId): string
    {
        return substr($indexServiceId, \strlen(self::INDEX_SERVICE_ID_PREFIX));
    }

    private static function describeSort(?array $sort): string
    {
        if (null === $sort) {
            return 'score';
        }
        if (isset($sort['randomSeed'])) {
            return 'random';
        }
        $fields = [];
        foreach ($sort as $field => $options) {
            $fields[] = $field.' '.$options['order'];
        }

        return implode(', ', $fields);
    }
}
