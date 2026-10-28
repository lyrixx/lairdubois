<?php

namespace Ladb\CoreBundle\Message;

/**
 * A list shows viewables of a type to a user (ViewableUtils::processListedView()), recorded by
 * ViewConsumer.
 */
final class ListedViewMessage {

	/** @var int */
	private $entityType;
	/** @var int[] */
	private $entityIds;
	/** @var int */
	private $userId;

	/**
	 * @param int[] $entityIds
	 */
	public function __construct(int $entityType, array $entityIds, int $userId) {
		$this->entityType = $entityType;
		$this->entityIds = $entityIds;
		$this->userId = $userId;
	}

	public function getEntityType(): int {
		return $this->entityType;
	}

	/**
	 * @return int[]
	 */
	public function getEntityIds(): array {
		return $this->entityIds;
	}

	public function getUserId(): int {
		return $this->userId;
	}

}
