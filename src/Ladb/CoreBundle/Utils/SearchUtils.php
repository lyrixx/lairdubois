<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Persistence\ManagerRegistry;
use Elastica\Document;
use FOS\ElasticaBundle\Index\IndexManager;
use FOS\ElasticaBundle\Persister\PersisterRegistry;
use Ladb\CoreBundle\Entity\Stats\Search;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyPath;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Elastica\Query;
use Ladb\CoreBundle\Model\IndexableInterface;

class SearchUtils {

	const NAME = 'ladb_core.search_utils';

	// The callers give an index by the id of its service: one index per type of content (#31)
	const INDEX_SERVICE_ID_PREFIX = 'fos_elastica.index.';

	private $doctrine;
	private $globalUtils;
	private $indexManager;
	private $logger;
	private $paginatorUtils;
	private $persisterRegistry;

	public function __construct(ManagerRegistry $doctrine, GlobalUtils $globalUtils, IndexManager $indexManager, LoggerInterface $logger, PaginatorUtils $paginatorUtils, PersisterRegistry $persisterRegistry) {
		$this->doctrine = $doctrine;
		$this->globalUtils = $globalUtils;
		$this->indexManager = $indexManager;
		$this->logger = $logger;
		$this->paginatorUtils = $paginatorUtils;
		$this->persisterRegistry = $persisterRegistry;
	}

	/////

	private function _getObjectPersister($entity) {
		$classname = strtolower(get_class($entity));
		if (preg_match('@\\\\([\w]+)\\\\([\w]+)$@', $classname, $matches)) {
			return $this->persisterRegistry->getPersister($matches[1].'_'.$matches[2]);
		}
		return null;
	}

	private function _getIndex($indexServiceId) {
		if (0 !== strpos($indexServiceId, self::INDEX_SERVICE_ID_PREFIX)) {
			throw new \LogicException(sprintf('"%s" is not the id of an index.', $indexServiceId));
		}
		return $this->indexManager->getIndex(substr($indexServiceId, strlen(self::INDEX_SERVICE_ID_PREFIX)));
	}

	private function _logSearch($context, $query, $totalHits) {

		// Exclude empty query
		if (empty($query)) {
			return;
		}

		$om = $this->doctrine->getManager();

		// Retrieve search session identifier
		$session = $this->globalUtils->getSession();
		$key = '_ladb_search_session';
		$sessionIdentifier = $session->get($key);
		if (is_null($sessionIdentifier)) {
			$sessionIdentifier = uniqid();
			$session->set($key, $sessionIdentifier);
		}

		// Create log entry
		$search = new Search();
		$search->setSessionIdentifier($sessionIdentifier);
		$search->setContext($context);
		$search->setQuery($query);
		$search->setTotalHits($totalHits);

		$om->persist($search);
		$om->flush();

	}

	/////

	public function insertEntityToIndex(IndexableInterface $entity) {
		if ($entity->isIndexable()) {
			$objectPersister = $this->_getObjectPersister($entity);
			if (!is_null($objectPersister)) {
				try {
					$objectPersister->insertOne($entity);
				} catch (\Exception $e) {
					$this->logger->error('SearchUtils/insertEntityToIndex', array( 'exception' => $e ));
				}
			}
		}
	}

	public function replaceEntityInIndex(IndexableInterface $entity) {
		if ($entity->isIndexable()) {
			$objectPersister = $this->_getObjectPersister($entity);
			if (!is_null($objectPersister)) {
				try {
					$objectPersister->replaceOne($entity);
				} catch (\Exception $e) {
					$this->logger->error('SearchUtils/replaceEntityInIndex', array( 'exception' => $e ));
				}
			}
		}
	}

	public function deleteEntityFromIndex(IndexableInterface $entity) {
		$objectPersister = $this->_getObjectPersister($entity);
		if (!is_null($objectPersister)) {
			try {
				$objectPersister->deleteOne($entity);
			} catch (\Exception $e) {
				$this->logger->error('SearchUtils/deleteEntityFromIndex', array( 'exception' => $e ));
			}
		}
	}

	/////

	public function searchEntitiesCount($filters, $indexServiceId, $excludedIds = null) {

		$sort = null;
		$elasticaQuery = $this->_buildElasticaQuery($filters, $sort, 0, 0, $excludedIds);
		if (is_null($elasticaQuery)) {
			return 0;
		}

		// Count
		$index = $this->_getIndex($indexServiceId);
		try {
			$resultSet = $index->search($elasticaQuery);
			$count = $resultSet->getTotalHits();
		} catch (\Exception $e) {
			return 0;
		}

		return $count;
	}

	private function _buildElasticaQuery(&$filters, &$sort, $offset, $size, $excludedIds = null) {
		if (is_null($filters) && is_null($sort)) {
			return null;
		}

		$query = null;
		if (empty($filters) && !is_null($sort)) {
			$query = new \Elastica\Query\MatchAll();
		} else {
			$query = new \Elastica\Query\BoolQuery();
			foreach ($filters as $filter) {
				$query->addMust($filter);
			}
		}

		// Excluded Ids wrapper query
		if (!is_null($excludedIds) && is_array($excludedIds) && !empty($excludedIds)) {
			$wrapperQuery = new \Elastica\Query\BoolQuery();
			$wrapperQuery->addMustNot(new \Elastica\Query\Ids($excludedIds));
			$wrapperQuery->addMust($query);
			$query = $wrapperQuery;
		}

		// Random sort wrapper query
		if (!is_null($sort) && isset($sort['randomSeed'])) {
			$wrapperQuery = new \Elastica\Query\FunctionScore();
			$wrapperQuery->setQuery($query);
			if (!empty($sort['randomSeed'])) {
				// The seed is a string (smartsearch.js), that Elasticsearch hashes: setRandomScore() only takes an integer.
				// With the field hashed with it, which Elasticsearch 7 asks for, and which is the default of 9
				$wrapperQuery->setParam('random_score', array( 'seed' => $sort['randomSeed'], 'field' => '_seq_no' ));
			}
			$query = $wrapperQuery;
		}

		$elasticaQuery = Query::create($query);
		// Elasticsearch 7 counts the hits exactly up to 10,000 only: the counters and the pagination need them all
		$elasticaQuery->setTrackTotalHits(true);
		if (!is_null($sort) && !isset($sort['randomSeed'])) {
			$elasticaQuery->addSort($sort);
		}
		$elasticaQuery->setFrom($offset);
		if ($size > 0) {
			$elasticaQuery->setSize($size);
		}

		return $elasticaQuery;
	}

	public function searchPaginedEntities(Request $request, $page, $queryCallback, $defaultsCallBack, $globalFiltersCallBack, $indexServiceId, $entityClassName, $route, $parameters = array(), $excludedIds = null) {

		// Parse request
		$queryParameters = $this->_parseQueryRequest($request);

		// Export request parameters
		$excludedIds = is_array($excludedIds) ? array_merge($queryParameters['excludedIds'], $excludedIds) : $queryParameters['excludedIds'];
		$ex = implode(',', $excludedIds);
		$q = $queryParameters['q'];

		// Compute filters and sort
		$filters = array();
		$sort = null;
		$noGlobalFilters = false;
		$couldUseDefaultSort = true;
		$defaults = false;
		foreach ($queryParameters['facets'] as $facet) {
			$queryCallback($facet, $filters, $sort, $noGlobalFilters, $couldUseDefaultSort);
		}
		if (is_null($sort) && (empty($filters) || $couldUseDefaultSort)) {
			$defaultFilters = array();
			$defaultSort = null;
			if (!is_null($defaultsCallBack)) {
				$defaultsCallBack($defaultFilters, $defaultSort);
			}
			if (empty($filters)) {
				$filters = $defaultFilters;
				$defaults = true;
			}
			if (is_null($sort) || $couldUseDefaultSort) {
				$sort = $defaultSort;
			}
		}

		if (!$noGlobalFilters && !is_null($globalFiltersCallBack)) {
			$globalFiltersCallBack($filters);
		}

		// Setup pagination
		$offset = $this->paginatorUtils->computePaginatorOffset($page);
		$limit = $this->paginatorUtils->computePaginatorLimit($page);

		// Search entities
		$searchResult = $this->searchEntities($filters, $sort, $indexServiceId, $entityClassName, $offset, $limit, $excludedIds);

		if (is_null($route) || is_null($searchResult->resultSet)) {
			$pageUrls = new \stdClass();
			$pageUrls->prev = 0;
			$pageUrls->next = 0;
		} else {
			$pageUrls = $this->paginatorUtils->generatePrevAndNextPageUrl($route, array_merge($parameters, array( 'q' => $q, 'ex' => $ex )), $page, $searchResult->resultSet->getTotalHits());
		}

		$totalHits = $defaults ? -1 : (is_null($searchResult->resultSet) ? 0 : $searchResult->resultSet->getTotalHits());

		// Log search
		if ($page == 0) {
			$this->_logSearch($request->get('_route'), $q, $totalHits);
		}

		return array(
			'q'           => $q,
			'ex'          => $ex,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'totalHits'   => $totalHits,
			'resultSet'   => $searchResult->resultSet,
			'entities'    => $searchResult->entities,
		);
	}

	/////

	private function _parseQueryRequest(Request $request, $parseFacets = true) {
		$q = $request->get('q', '');

		// Parse facets
		$facets = null;
		if ($parseFacets) {

			$facets = array();
			preg_match_all('/(?:@([^\s]+):(?:\"([^\"]+)\"|([^\s]+))|@([^\s]+)|\"([^\"]+)\"|([^\s]+))/', $q, $matches);
			for ($i = 0; $i < count($matches[0]); $i++) {

				$facet = new \stdClass();
				$facet->name = null;

				if (!empty($matches[1][$i])) {
					$facet->name = $matches[1][$i];
					if (!empty($matches[2][$i])) {
						$facet->value = $matches[2][$i];
					} elseif (!empty($matches[3][$i])) {
						$facet->value = $matches[3][$i];
					} else {
						continue;
					}
				} else if (!empty($matches[4][$i])) {
					$facet->name = $matches[4][$i];
				} else {
					if (!empty($matches[5][$i])) {
						$facet->value = $matches[5][$i];
					} elseif (!empty($matches[6][$i])) {
						$facet->value = $matches[6][$i];
					} else {
						continue;
					}
				}

				$facets[] = $facet;
			}

		}

		// Compute excluded IDs
		$ex = $request->get('ex');
		$excludedIds = array();
		$strIds = explode(',', $ex);
		foreach ($strIds as $strId) {
			$intId = intval(trim($strId));
			if ($intId > 0) {
				$excludedIds[] = $intId;
			}
		}

		return array(
			'q'           => $q,
			'facets'      => $facets,
			'ex'          => $ex,
			'excludedIds' => $excludedIds,
		);
	}

	public function searchEntities(&$filters, &$sort, $indexServiceId, $entityClassName, $offset, $limit, $excludedIds = null) {

		$result = new \stdClass();
		$result->resultSet = null;
		$result->entities = array();

		$elasticaQuery = $this->_buildElasticaQuery($filters, $sort, $offset, $limit, $excludedIds);
		if (is_null($elasticaQuery)) {
			return $result;
		}

		// Search
		$index = $this->_getIndex($indexServiceId);
		$resultSet = $index->search($elasticaQuery);

		// Extract Ids
		$ids = array();
		foreach ($resultSet->getResults() as $result) {
			$ids[] = $result->getId();
		}

		// Retrieve entities
		$entities = count($ids) == 0 ? array() : $this->doctrine->getManager()->getRepository($entityClassName)->findByIds($ids);

		// Reorder entities
		$identifierPropertyPath = new PropertyPath('id');
		$propertyAccessor = PropertyAccess::createPropertyAccessor();
		$idPos = array_flip($ids);
		usort($entities, function($a, $b) use ($idPos, $identifierPropertyPath, $propertyAccessor) {
			return $idPos[$propertyAccessor->getValue($a, $identifierPropertyPath)] > $idPos[$propertyAccessor->getValue($b, $identifierPropertyPath)];
		});

		$result->resultSet = $resultSet;
		$result->entities = $entities;

		return $result;
	}

	/////

	public function getSorterOrder($facet, $defaultOrder = 'desc') {
		if ($defaultOrder == 'desc') {
			return isset($facet->value) && $facet->value == 'asc' ? 'asc' : $defaultOrder;
		}
		return isset($facet->value) && $facet->value == 'desc' ? 'desc' : $defaultOrder;
	}
}