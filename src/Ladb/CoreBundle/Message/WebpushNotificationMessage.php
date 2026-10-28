<?php

namespace Ladb\CoreBundle\Message;

/**
 * A web push notification to the subscriptions of a user (WebpushNotificationUtils::enqueueNotification()),
 * sent by WebpushNotificationConsumer.
 */
final class WebpushNotificationMessage {

	/** @var int */
	private $userId;
	/** @var string */
	private $body;
	/** @var string */
	private $icon;
	/** @var string */
	private $link;

	public function __construct(int $userId, string $body, string $icon, string $link) {
		$this->userId = $userId;
		$this->body = $body;
		$this->icon = $icon;
		$this->link = $link;
	}

	public function getUserId(): int {
		return $this->userId;
	}

	public function getBody(): string {
		return $this->body;
	}

	public function getIcon(): string {
		return $this->icon;
	}

	public function getLink(): string {
		return $this->link;
	}

}
