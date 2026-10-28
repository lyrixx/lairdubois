<?php

namespace Ladb\CoreBundle\Repository\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Ladb\CoreBundle\Entity\Core\Picture;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Repository\AbstractEntityRepository;
use Ladb\CoreBundle\Utils\CanonicalFieldsUpdater;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserRepository extends AbstractEntityRepository implements UserLoaderInterface, PasswordUpgraderInterface {

	/**
	 * The user of the login form and of the remember me cookie, by its username or its email: the
	 * provider fos_user.user_provider.username_email of FOSUserBundle (#24).
	 */
	public function loadUserByUsername($username) {
		if (preg_match('/^.+\@\S+\.\S+$/', $username)) {
			$user = $this->findOneBy(array( 'emailCanonical' => CanonicalFieldsUpdater::canonicalize($username) ));
			if (null !== $user) {
				return $user;
			}
		}

		return $this->findOneBy(array( 'usernameCanonical' => CanonicalFieldsUpdater::canonicalize($username) ));
	}

	/**
	 * At the login, when the hash is not the one of the encoder of security.yml (the sha512 of
	 * FOSUserBundle): the new hash holds its salt. Flushed here, no action handles the login form.
	 */
	public function upgradePassword(UserInterface $user, string $newEncodedPassword): void {
		$user->setPassword($newEncodedPassword);
		$user->setSalt(null);
		$this->getEntityManager()->flush();
	}

	/////

	public function createIsEnabledQueryBuilder() {
		return $this->createQueryBuilder('a')->where('a.enabled = true');	// FOSElasticaBundle bug -> use 'a'
	}

	/////

	/**
	 * Whether rows reference the user by a foreign key (its publications, comments, likes, views, messages, registration...): the database refuses its deletion. Except the given pictures (its avatar and its banner), removed with it.
	 */
	public function existsReferences(User $user, array $removedPictures) {
		$om = $this->getEntityManager();
		$connection = $om->getConnection();
		$removedPictureIds = array_map(function(Picture $picture) { return $picture->getId(); }, $removedPictures);

		foreach ($om->getMetadataFactory()->getAllMetadata() as $metadata) {
			if ($metadata->isMappedSuperclass) {
				continue;
			}
			foreach ($metadata->getAssociationMappings() as $mapping) {

				// The foreign keys to the user are the join columns of the associations to it, in the table of the class that declares them
				if ($mapping['targetEntity'] !== User::class || !isset($mapping['joinColumns']) || isset($mapping['inherited'])) {
					continue;
				}

				$sql = 'SELECT 1 FROM '.$metadata->getTableName().' WHERE '.$mapping['joinColumns'][0]['name'].' = ?';
				$parameters = array( $user->getId() );
				$types = array( \PDO::PARAM_INT );
				if ($metadata->getName() === Picture::class && count($removedPictureIds) > 0) {
					$sql .= ' AND id NOT IN (?)';
					$parameters[] = $removedPictureIds;
					$types[] = Connection::PARAM_INT_ARRAY;
				}

				if ($connection->fetchOne($sql.' LIMIT 1', $parameters, $types) !== false) {
					return true;
				}

			}
		}

		return false;
	}

	/////

	public function countDonors() {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 'COUNT(u.id)' ))
			->from($this->getEntityName(), 'u')
			->leftJoin('u.meta', 'm')
			->where('m.donationCount > 0')
			->andWhere('u.enabled = 1')
		;

		try {
			return $queryBuilder->getQuery()->getSingleScalarResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}
	}

	/////

	public function findByIds(array $ids) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 'u', 'av', 'm' ))
			->from($this->getEntityName(), 'u')
			->innerJoin('u.avatar', 'av')
			->leftJoin('u.meta', 'm')
			->where($queryBuilder->expr()->in('u.id', $ids))
		;

		try {
			return $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return null;
		}
	}

	/////

	public function findDonorsPagined($offset, $limit) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 'u', 'a' ))
			->from($this->getEntityName(), 'u')
			->leftJoin('u.avatar', 'a')
			->leftJoin('u.meta', 'm')
			->where('m.donationCount > 0')
			->andWhere('u.enabled = 1')
			->orderBy('u.displayname', 'ASC')
			->setFirstResult($offset)
			->setMaxResults($limit)
		;

		return new Paginator($queryBuilder->getQuery());
	}

	public function findPagined($offset, $limit, $filter = 'recent', $isAdmin = false) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 'u', 'a' ))
			->from($this->getEntityName(), 'u')
			->leftJoin('u.avatar', 'a')
			->setFirstResult($offset)
			->setMaxResults($limit)
		;

		if ($filter != 'admin-not-enabled') {
			$queryBuilder
				->andWhere('u.enabled = 1')
			;
		}

		$this->_applyCommonFilter($queryBuilder, $filter, $isAdmin);

		return new Paginator($queryBuilder->getQuery());
	}

	private function _applyCommonFilter(&$queryBuilder, $filter, $isAdmin) {
		if ('contributors-all' == $filter) {
			$queryBuilder
				->addOrderBy('u.contributionCount', 'DESC')
				->addOrderBy('u.createdAt', 'DESC')
			;
		} else if ('contributors-creations' == $filter) {
			$queryBuilder
				->addOrderBy('u.publishedCreationCount', 'DESC')
				->addOrderBy('u.createdAt', 'DESC')
			;
		} else if ('contributors-plans' == $filter) {
			$queryBuilder
				->addOrderBy('u.publishedPlanCount', 'DESC')
				->addOrderBy('u.createdAt', 'DESC')
			;
		} else if ('contributors-howtos' == $filter) {
			$queryBuilder
				->addOrderBy('u.publishedHowtoCount', 'DESC')
				->addOrderBy('u.createdAt', 'DESC')
			;
		} else if ('contributors-workshops' == $filter) {
			$queryBuilder
				->addOrderBy('u.publishedWorkshopCount', 'DESC')
				->addOrderBy('u.createdAt', 'DESC')
			;
		} else if ('contributors-comments' == $filter) {
			$queryBuilder
				->addOrderBy('u.commentCount', 'DESC')
				->addOrderBy('u.createdAt', 'DESC')
			;
		} else if ('contributors-finds' == $filter) {
			$queryBuilder
				->addOrderBy('u.publishedFindCount', 'DESC')
				->addOrderBy('u.createdAt', 'DESC')
			;
		} else if ('popular-followers' == $filter) {
			$queryBuilder
				->addOrderBy('u.followerCount', 'DESC')
			;
		} else if ('popular-likes' == $filter) {
			$queryBuilder
				->addOrderBy('u.recievedLikeCount', 'DESC')
			;
		} else if ('type-asso' == $filter) {
			$queryBuilder
				->andWhere('u.accountType = '.User::ACCOUNT_TYPE_ASSO)
			;
		} else if ('type-pro' == $filter) {
			$queryBuilder
				->andWhere('u.accountType = '.User::ACCOUNT_TYPE_PRO)
			;
		} else if ('type-hobbyist' == $filter) {
			$queryBuilder
				->andWhere('u.accountType = '.User::ACCOUNT_TYPE_HOBBYIST)
			;
		} else if ('type-brand' == $filter) {
			$queryBuilder
				->andWhere('u.accountType = '.User::ACCOUNT_TYPE_BRAND)
			;
		} else if ('social-facebook' == $filter) {
			$queryBuilder
				->andWhere('u.facebook IS NOT NULL')
			;
		} else if ('social-twitter' == $filter) {
			$queryBuilder
				->andWhere('u.twitter IS NOT NULL')
			;
		} else if ('social-youtube' == $filter) {
			$queryBuilder
				->andWhere('u.youtube IS NOT NULL')
			;
		} else if ('social-vimeo' == $filter) {
			$queryBuilder
				->andWhere('u.vimeo IS NOT NULL')
			;
		} else if ('social-dailymotion' == $filter) {
			$queryBuilder
				->andWhere('u.dailymotion IS NOT NULL')
			;
		} else if ('social-pinterest' == $filter) {
			$queryBuilder
				->andWhere('u.pinterest IS NOT NULL')
			;
		} else if ('social-instagram' == $filter) {
			$queryBuilder
				->andWhere('u.instagram IS NOT NULL')
			;
		} else if ('admin-not-enabled' == $filter && $isAdmin) {
			$queryBuilder
				->andWhere('u.enabled = 0')
			;
		} else if ('admin-not-email-confirmed' == $filter && $isAdmin) {
			$queryBuilder
				->andWhere('u.emailConfirmed = 0')
			;
		} else {
			$queryBuilder
				->addOrderBy('u.createdAt', 'DESC')
			;
		}
	}

	public  function findGeocoded($filter = 'recent', $isAdmin = false) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 'u', 'a' ))
			->from($this->getEntityName(), 'u')
			->leftJoin('u.avatar', 'a')
			->where('u.latitude IS NOT NULL')
			->andWhere('u.longitude IS NOT NULL')
		;

		if (!$isAdmin) {
			$queryBuilder
				->andwhere('u.enabled = 1')
			;
		}

		$this->_applyCommonFilter($queryBuilder, $filter, $isAdmin);

		try {
			return $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return null;
		}
	}

}