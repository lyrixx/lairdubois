<?php

namespace Ladb\CoreBundle\Entity\Webpush;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use BenTools\WebPushBundle\Model\Subscription\UserSubscriptionInterface;

#[ORM\Table('tbl_webpush_user_subscription')]
#[ORM\Entity]
class UserSubscription implements UserSubscriptionInterface {

	#[ORM\Column(type: 'integer')]
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'AUTO')]
	private $id;

	#[ORM\JoinColumn(nullable: false)]
	#[ORM\ManyToOne(targetEntity: \Ladb\CoreBundle\Entity\Core\User::class)]
	private $user;

	#[ORM\Column(type: 'string', name: 'subscription_hash')]
	private $subscriptionHash;

	#[ORM\Column(type: 'json')]
	private $subscription;

	/////

	public function __construct(UserInterface $user, string $subscriptionHash, array $subscription) {
		$this->user = $user;
		$this->subscriptionHash = $subscriptionHash;
		$this->subscription = $subscription;
	}

	// Id /////

	public function getId(): ?int {
		return $this->id;
	}

	// User /////

	public function getUser(): UserInterface {
		return $this->user;
	}

	// SubscriptionHash /////

	public function getSubscriptionHash(): string {
		return $this->subscriptionHash;
	}

	// EndPoint /////

	public function getEndpoint(): string {
		return $this->subscription['endpoint'] ?? null;
	}

	// PublicKey /////

	public function getPublicKey(): string {
		return $this->subscription['keys']['p256dh'] ?? null;
	}

	// AuthToken /////

	public function getAuthToken(): string {
		return $this->subscription['keys']['auth'] ?? null;
	}

	// ContentEncoding /////

	public function getContentEncoding(): string {
		// The one of minishlink/web-push 2.0: the JS of the site (ladb-webpush-client.js) does not send
		// the encodings the browser supports (PushManager.supportedContentEncodings)
		return 'aesgcm';
	}

}