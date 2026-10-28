<?php

namespace Ladb\CoreBundle\Message;

/**
 * A page shows a viewable (ViewableUtils::processShownView()), counted by ViewConsumer.
 */
final class ShownViewMessage {

	/** @var int */
	private $entityType;
	/** @var int */
	private $entityId;
	/** @var int|null Null for an anonymous visitor */
	private $userId;

	public function __construct(int $entityType, int $entityId, ?int $userId) {
		$this->entityType = $entityType;
		$this->entityId = $entityId;
		$this->userId = $userId;
	}

	public function getEntityType(): int {
		return $this->entityType;
	}

	public function getEntityId(): int {
		return $this->entityId;
	}

	public function getUserId(): ?int {
		return $this->userId;
	}

}
