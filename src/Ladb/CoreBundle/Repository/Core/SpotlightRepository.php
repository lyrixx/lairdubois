<?php

namespace Ladb\CoreBundle\Repository\Core;

use Doctrine\ORM\Tools\Pagination\Paginator;
use Ladb\CoreBundle\Repository\AbstractEntityRepository;

class SpotlightRepository extends AbstractEntityRepository {

	/////

	public function findOneLast() {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 's' ))
			->from($this->getEntityName(), 's')
			->where('s.enabled = 1')
			->andWhere('s.finishedAt IS NULL')
			->orderBy('s.createdAt', \SortDirection::Ascending)
			->setMaxResults(1);

		try {
			return $queryBuilder->getQuery()->getSingleResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return null;
		}
	}

	/////

	public function findPagined($offset, $limit) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 's' ))
			->from($this->getEntityName(), 's')
			->where('s.enabled = true')
			->orderBy('s.createdAt', \SortDirection::Descending)
			->setFirstResult($offset)
			->setMaxResults($limit)
		;

		return new Paginator($queryBuilder->getQuery());
	}

}