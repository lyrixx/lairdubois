<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Entity\Core\Activity\AbstractActivity;
use Ladb\CoreBundle\Entity\Core\Comment;
use Ladb\CoreBundle\Entity\Core\Feedback;
use Ladb\CoreBundle\Entity\Core\Follower;
use Ladb\CoreBundle\Entity\Core\Join;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Entity\Core\Review;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Core\Vote;
use Ladb\CoreBundle\Entity\Core\Watch;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Knowledge\School\Testimonial;
use Ladb\CoreBundle\Entity\Knowledge\Software;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Promotion\Graphic;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Model\CommentableInterface;
use Ladb\CoreBundle\Model\LikableInterface;
use Ladb\CoreBundle\Model\WatchableInterface;

/**
 * The interactions of the volume fixtures, on the contents of VolumeContentFactory: a few
 * statements for each type instead of hundreds of thousands of entities.
 *
 * Their activities are not pending, and their notifications written here: ladb:cron:notification:populate
 * reads all the pending activities in memory. They are already shown, so nothing is sent by
 * ladb:cron:notification:email and the fresh counters stay at 0.
 */
final readonly class VolumeInteractions {

	public const MAX_PER_CONTENT = 3;

	// Keep in sync with the discriminator map of AbstractActivity
	private const ACTIVITY_COMMENT = 1;
	private const ACTIVITY_LIKE = 3;
	private const ACTIVITY_PUBLISH = 4;

	private const COMMENTS = array(
		'Superbe travail, bravo !',
		'Quelle finition as-tu utilisée ?',
		'Merci pour le partage, très inspirant.',
		'Combien de temps cela t\'a-t-il pris ?',
		'Le veinage est magnifique.',
		'Très propre, les assemblages sont nets.',
	);

	// From the rows of the contents, 1 to 3 rows for each of them. "e" is the content.
	private const COPIES = 'JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3) AS copies ON copies.n <= 1 + e.id % 3';

	// The public counters of the accounts: table of what they count, and its column for the account
	private const USER_COUNTERS = array(
		'answer_count' => array( Answer::class, 'user_id' ),
		'comment_count' => array( Comment::class, 'user_id' ),
		'feedback_count' => array( Feedback::class, 'user_id' ),
		'follower_count' => array( Follower::class, 'following_user_id' ),
		'following_count' => array( Follower::class, 'user_id' ),
		'public_collection_count' => array( Collection::class, 'user_id' ),
		'public_creation_count' => array( Creation::class, 'user_id' ),
		'public_event_count' => array( Event::class, 'user_id' ),
		'public_find_count' => array( Find::class, 'user_id' ),
		'public_graphic_count' => array( Graphic::class, 'user_id' ),
		'public_howto_count' => array( Howto::class, 'user_id' ),
		'public_offer_count' => array( Offer::class, 'user_id' ),
		'public_plan_count' => array( Plan::class, 'user_id' ),
		'public_question_count' => array( Question::class, 'user_id' ),
		'public_workflow_count' => array( Workflow::class, 'user_id' ),
		'public_workshop_count' => array( Workshop::class, 'user_id' ),
		'recieved_like_count' => array( Like::class, 'entity_user_id' ),
		'review_count' => array( Review::class, 'user_id' ),
		'sent_like_count' => array( Like::class, 'user_id' ),
		'testimonial_count' => array( Testimonial::class, 'user_id' ),
	);

	public function __construct(
		private Connection $connection,
		private EntityManagerInterface $om,
	) {
	}

	/**
	 * @param array<class-string, int> $firstIds the id of the first content of each class
	 */
	public function create(array $firstIds, int $firstUserId, int $userCount): void {
		// Another account for each copy of a content: the like of an account is unique
		$actor = sprintf('%d + (e.id * 31 + copies.n) %% %d', $firstUserId, $userCount);

		$firstCommentId = $this->nextId(Comment::class);
		$firstLikeId = $this->nextId(Like::class);

		foreach ($firstIds as $class => $firstId) {
			$table = $this->table($class);
			$type = $class::TYPE;
			$isAuthored = is_a($class, AuthoredInterface::class, true);

			if (is_a($class, CommentableInterface::class, true)) {
				$this->connection->executeStatement(
					'INSERT INTO '.$this->table(Comment::class).' (entity_type, entity_id, created_at, updated_at, body, htmlBody, childCount, user_id) '.
					"SELECT {$type}, e.id, e.changed_at, e.changed_at, {$this->commentBody()}, CONCAT('<p>', {$this->commentBody()}, '</p>'), 0, {$actor} ".
					"FROM {$table} e ".self::COPIES." WHERE e.id >= {$firstId}"
				);
				$this->connection->executeStatement("UPDATE {$table} SET comment_count = 1 + id % 3 WHERE id >= {$firstId}");
			}

			if (is_a($class, LikableInterface::class, true)) {
				$author = $isAuthored ? 'e.user_id' : 'NULL';
				$this->connection->executeStatement(
					'INSERT INTO '.$this->table(Like::class).' (entity_type, entity_id, entity_user_id, user_id) '.
					"SELECT {$type}, e.id, {$author}, {$actor} FROM {$table} e ".self::COPIES." WHERE e.id >= {$firstId}"
				);
				$this->connection->executeStatement("UPDATE {$table} SET like_count = 1 + id % 3 WHERE id >= {$firstId}");
			}

			if ($isAuthored && is_a($class, WatchableInterface::class, true)) {
				$this->connection->executeStatement(
					'INSERT INTO '.$this->table(Watch::class).' (entity_type, entity_id, user_id) '.
					"SELECT {$type}, e.id, e.user_id FROM {$table} e WHERE e.id >= {$firstId}"
				);
				$this->connection->executeStatement("UPDATE {$table} SET watch_count = 1 WHERE id >= {$firstId}");
			}

			if ($isAuthored) {
				$this->publishActivities($table, $type, $firstId);
			}
		}

		$firstActivityId = $this->nextId(AbstractActivity::class);
		$this->activities(self::ACTIVITY_COMMENT, $this->table(Comment::class), 'tbl_core_activity_comment', 'comment_id', $firstCommentId);
		$this->activities(self::ACTIVITY_LIKE, $this->table(Like::class), 'tbl_core_activity_like', 'like_id', $firstLikeId);
		$this->notifications($firstActivityId);

		$this->others($firstIds, $firstUserId, $userCount);
		$this->userCounters($firstUserId);
	}

	private function publishActivities(string $table, int $type, int $firstId): void {
		$offset = $this->nextId(AbstractActivity::class) - $firstId;
		$this->connection->executeStatement(
			'INSERT INTO '.$this->table(AbstractActivity::class).' (id, created_at, is_pending_notifications, user_id, discr) '.
			"SELECT {$offset} + e.id, e.created_at, 0, e.user_id, ".self::ACTIVITY_PUBLISH." FROM {$table} e WHERE e.id >= {$firstId}"
		);
		$this->connection->executeStatement(
			'INSERT INTO tbl_core_activity_publish (id, entity_type, entity_id, publisher_user) '.
			"SELECT {$offset} + e.id, {$type}, e.id, e.user_id FROM {$table} e WHERE e.id >= {$firstId}"
		);
	}

	/**
	 * An activity for each row of the table, from $firstId.
	 */
	private function activities(int $discriminator, string $table, string $activityTable, string $activityColumn, int $firstId): void {
		$offset = $this->nextId(AbstractActivity::class) - $firstId;
		$this->connection->executeStatement(
			'INSERT INTO '.$this->table(AbstractActivity::class).' (id, created_at, is_pending_notifications, user_id, discr) '.
			"SELECT {$offset} + s.id, NOW(), 0, s.user_id, {$discriminator} FROM {$table} s WHERE s.id >= {$firstId}"
		);
		$this->connection->executeStatement(
			"INSERT INTO {$activityTable} (id, {$activityColumn}) SELECT {$offset} + s.id, s.id FROM {$table} s WHERE s.id >= {$firstId}"
		);
	}

	/**
	 * What ladb:cron:notification:populate does for a like and a comment: the author of the
	 * content and the watchers of the content.
	 */
	private function notifications(int $firstActivityId): void {
		$columns = '(created_at, is_pending_email, is_listed, is_shown, is_children_shown, is_folder, user_id, activity_id)';

		$this->connection->executeStatement(
			"INSERT INTO tbl_core_notification {$columns} ".
			'SELECT NOW(), 0, 1, 1, 0, 0, l.entity_user_id, a.id FROM tbl_core_activity_like a '.
			'JOIN '.$this->table(Like::class).' l ON l.id = a.like_id '.
			"WHERE a.id >= {$firstActivityId} AND l.entity_user_id IS NOT NULL AND l.entity_user_id <> l.user_id"
		);
		$this->connection->executeStatement(
			"INSERT INTO tbl_core_notification {$columns} ".
			'SELECT NOW(), 0, 1, 1, 0, 0, w.user_id, a.id FROM tbl_core_activity_comment a '.
			'JOIN '.$this->table(Comment::class).' c ON c.id = a.comment_id '.
			'JOIN '.$this->table(Watch::class).' w ON w.entity_type = c.entity_type AND w.entity_id = c.entity_id AND w.user_id <> c.user_id '.
			"WHERE a.id >= {$firstActivityId}"
		);
	}

	/**
	 * Follows, joins, reviews, testimonials and votes, one for each account or content. No
	 * activity for those.
	 *
	 * @param array<class-string, int> $firstIds
	 */
	private function others(array $firstIds, int $firstUserId, int $userCount): void {
		$actor = sprintf('%d + (e.id * 31) %% %d', $firstUserId, $userCount);

		$this->connection->executeStatement(
			'INSERT INTO '.$this->table(Follower::class).' (user_id, following_user_id) '.
			"SELECT e.id, {$firstUserId} + (e.id - {$firstUserId} + 1) % {$userCount} FROM ".$this->table(User::class)." e WHERE e.id >= {$firstUserId}"
		);

		$events = $this->table(Event::class);
		$firstEventId = $firstIds[Event::class];
		$this->connection->executeStatement(
			'INSERT INTO '.$this->table(Join::class).' (entity_type, entity_id, user_id) '.
			'SELECT '.Event::TYPE.", e.id, {$actor} FROM {$events} e WHERE e.id >= {$firstEventId}"
		);
		$this->connection->executeStatement("UPDATE {$events} SET join_count = 1 WHERE id >= {$firstEventId}");

		foreach (array( Book::class, Software::class ) as $class) {
			$table = $this->table($class);
			$firstId = $firstIds[$class];
			$this->connection->executeStatement(
				'INSERT INTO '.$this->table(Review::class).' (entity_type, entity_id, created_at, updated_at, title, body, html_body, rating, user_id) '.
				'SELECT '.$class::TYPE.", e.id, e.created_at, e.created_at, 'Une référence', 'À avoir dans son atelier.', '<p>À avoir dans son atelier.</p>', 1 + e.id % 5, {$actor} ".
				"FROM {$table} e WHERE e.id >= {$firstId}"
			);
			$this->connection->executeStatement("UPDATE {$table} SET review_count = 1, average_rating = 1 + id % 5 WHERE id >= {$firstId}");
		}

		$schools = $this->table(School::class);
		$firstSchoolId = $firstIds[School::class];
		$this->connection->executeStatement(
			'INSERT INTO '.$this->table(Testimonial::class).' (created_at, updated_at, diploma, from_year, to_year, body, html_body, school_id, user_id) '.
			"SELECT e.created_at, e.created_at, 'CAP Menuisier', 2018, 2020, 'Une formation exigeante.', '<p>Une formation exigeante.</p>', e.id, {$actor} ".
			"FROM {$schools} e WHERE e.id >= {$firstSchoolId}"
		);
		$this->connection->executeStatement("UPDATE {$schools} SET testimonial_count = 1 WHERE id >= {$firstSchoolId}");

		$answers = $this->table(Answer::class);
		$questions = $this->table(Question::class);
		$firstQuestionId = $firstIds[Question::class];
		$this->connection->executeStatement(
			'INSERT INTO '.$this->table(Vote::class).' (entity_type, entity_id, parent_entity_type, parent_entity_id, parent_entity_field, created_at, updated_at, score, user_id) '.
			'SELECT '.Answer::TYPE.', e.id, '.Question::TYPE.", e.question_id, 'bestAnswer', e.created_at, e.created_at, 1, {$actor} ".
			"FROM {$answers} e WHERE e.question_id >= {$firstQuestionId}"
		);
		$this->connection->executeStatement("UPDATE {$answers} SET positive_vote_score = 1, vote_score = 1, vote_count = 1 WHERE question_id >= {$firstQuestionId}");
		$this->connection->executeStatement("UPDATE {$questions} SET positive_vote_count = 1, vote_count = 1 WHERE id >= {$firstQuestionId}");

		$creations = $this->table(Creation::class);
		$this->connection->executeStatement(
			"UPDATE {$creations} SET feedback_count = 1 WHERE id IN (SELECT entity_id FROM ".$this->table(Feedback::class).' WHERE entity_type = '.Creation::TYPE.") AND id >= {$firstIds[Creation::class]}"
		);
	}

	private function userCounters(int $firstUserId): void {
		$users = $this->table(User::class);
		foreach (self::USER_COUNTERS as $counter => [$class, $column]) {
			$table = $this->table($class);
			$this->connection->executeStatement(
				"UPDATE tbl_core_user_meta m JOIN {$users} u ON u.meta_id = m.id ".
				"JOIN (SELECT {$column} AS user_id, COUNT(*) AS total FROM {$table} GROUP BY {$column}) t ON t.user_id = u.id ".
				"SET m.{$counter} = t.total WHERE u.id >= {$firstUserId}"
			);
		}
	}

	private function commentBody(): string {
		$comments = array_map($this->connection->quote(...), self::COMMENTS);
		return sprintf('ELT(1 + (e.id + copies.n) %% %d, %s)', count($comments), implode(', ', $comments));
	}

	/**
	 * @param class-string $class
	 */
	private function nextId(string $class): int {
		return 1 + (int) $this->connection->fetchOne('SELECT MAX(id) FROM '.$this->table($class));
	}

	/**
	 * @param class-string $class
	 */
	private function table(string $class): string {
		return $this->om->getClassMetadata($class)->getTableName();
	}

}
