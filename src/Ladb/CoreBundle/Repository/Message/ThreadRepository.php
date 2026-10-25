<?php

namespace Ladb\CoreBundle\Repository\Message;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Repository\AbstractEntityRepository;

class ThreadRepository extends AbstractEntityRepository {

	/////

	public function existsBySenderAndSubjectAndBody($sender, $subject, $body, $backwardDaysInterval = 1) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 'count(t.id)' ))
			->from($this->getEntityName(), 't')
			->innerJoin('t.messages', 'm')
			->where('m.sender = :sender')
			->andWhere('LOWER(t.subject) = LOWER(:subject)')
			->andWhere('LOWER(m.body) = LOWER(:body)')
			->andWhere('t.createdAt >= :minDate')
			->setParameter('sender', $sender)
			->setParameter('subject', strtolower(trim($subject)))
			->setParameter('body', strtolower(trim($body)))
			->setParameter('minDate', (new \DateTime())->sub(new \DateInterval('P'.$backwardDaysInterval.'D')))
		;

		try {
			return $queryBuilder->getQuery()->getSingleScalarResult() > 0;
		} catch (NonUniqueResultException $e) {
			return false;
		}
	}

	/////

	public function findOneByIdJoinedOnOptimized($id) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 't', 'tm', 'm', 's', 'mm', 'p' ))
			->from($this->getEntityName(), 't')
			->innerJoin('t.metas', 'tm')
			->innerJoin('t.messages', 'm')
			->innerJoin('m.sender', 's')
			->innerJoin('m.metas', 'mm')
			->innerJoin('mm.participant', 'p')
			->where('t.id = :id')
			->setParameter('id', $id);

		try {
			return $queryBuilder->getQuery()->getSingleResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return null;
		}
	}

	public function findOneByIdJoinedOnMetaAndParticipant($id) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 't', 'tm', 'p' ))
			->from($this->getEntityName(), 't')
			->innerJoin('t.metas', 'tm')
			->innerJoin('tm.participant', 'p')
			->where('t.id = :id')
			->setParameter('id', $id);

		try {
			return $queryBuilder->getQuery()->getSingleResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return null;
		}
	}

	/////

	public function countUnreadMessageByThreadAndUser($thread, User $participant) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select('COUNT(m)')
			->from($this->getEntityName(), 't')
			->innerJoin('t.messages', 'm')
			->innerJoin('m.metas', 'mm')
			->where('t = :thread')
			->andWhere('mm.participant = :participant')
			->andWhere('mm.isRead = true')
			->setParameter('thread', $thread)
			->setParameter('participant', $participant);

		try {
			return $thread->getMessageCount() - $queryBuilder->getQuery()->getSingleScalarResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}
	}

	/**
	 * The unread messages of each user, as reading the threads of the mailbox counts them
	 * (ThreadController::showAction()): the messages without a read meta of the user, in the
	 * threads they or one of their teams did not delete. Replies and threads to a team only have
	 * a meta for their sender.
	 *
	 * @param User[] $users
	 * @return array<int, int> The count by user id, without the users who have read everything
	 */
	public function countUnreadMessageByUsers(array $users) {
		$userIds = array_map(function (User $user) { return $user->getId(); }, $users);

		// UNION: a thread to a user and to one of their teams is counted once
		$sql = 'SELECT v.user_id, COUNT(m.id) ';
		$sql .= 'FROM (';
		$sql .= 	'SELECT tm.participant_user_id AS user_id, tm.thread_id FROM tbl_message_thread_meta tm ';
		$sql .= 	'WHERE tm.participant_user_id IN (:userIds) AND tm.is_deleted = 0 ';
		$sql .= 	'UNION ';
		$sql .= 	'SELECT mb.user_id, tm.thread_id FROM tbl_core_member mb ';
		$sql .= 	'INNER JOIN tbl_message_thread_meta tm ON tm.participant_user_id = mb.team_id ';
		$sql .= 	'WHERE mb.user_id IN (:userIds) AND tm.is_deleted = 0';
		$sql .= ') v ';
		$sql .= 'INNER JOIN tbl_message m ON m.thread_id = v.thread_id ';
		$sql .= 'LEFT JOIN tbl_message_meta mm ON mm.message_id = m.id AND mm.participant_user_id = v.user_id AND mm.is_read = 1 ';
		$sql .= 'WHERE mm.id IS NULL ';
		$sql .= 'GROUP BY v.user_id';

		$counts = $this->getEntityManager()->getConnection()->fetchAllKeyValue($sql, array( 'userIds' => $userIds ), array( 'userIds' => Connection::PARAM_INT_ARRAY ));

		return array_map('intval', $counts);
	}

	/////

	public function findPaginedByUsers(array $users, $offset, $limit, $filter = 'all') {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 't' ))
			->from($this->getEntityName(), 't')
			->innerJoin('t.metas', 'tm')
			->innerJoin('tm.participant', 'p')
			->where('p IN (:users)')
			->andWhere('tm.isDeleted = false')
			->setParameter('users', $users)
			->setFirstResult($offset)
			->setMaxResults($limit)
		;

		if ($filter == 'sent' && count($users) > 0) {
			$queryBuilder
				->andWhere('t.createdBy = :createdBy')
				->setParameter('createdBy', $users[0]);
		}

		$queryBuilder
			->addOrderBy('t.lastMessageDate', 'DESC');

		return new Paginator($queryBuilder->getQuery());
	}

}