<?php

namespace Ladb\CoreBundle\Utils;

use Ladb\CoreBundle\Entity\Core\MemberInvitation;
use Ladb\CoreBundle\Entity\Core\MemberRequest;
use Ladb\CoreBundle\Manager\Webpush\UserSubscriptionManager;
use Ladb\CoreBundle\Message\WebpushNotificationMessage;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use BenTools\WebPushBundle\Model\Message\PushMessage;
use BenTools\WebPushBundle\Sender\PushMessageSender;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Entity\Message\Thread;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Model\LikableInterface;
use Symfony\Component\Routing\RouterInterface;

class WebpushNotificationUtils {

	private $cacheManager;
	private $logger;
	private $messageBus;
	private $pushMessageSender;
	private $router;
	private $typableUtils;
	private $userSubscriptionManager;

	public function __construct(CacheManager $cacheManager, LoggerInterface $logger, MessageBusInterface $messageBus, PushMessageSender $pushMessageSender, RouterInterface $router, TypableUtils $typableUtils, UserSubscriptionManager $userSubscriptionManager) {
		$this->cacheManager = $cacheManager;
		$this->logger = $logger;
		$this->messageBus = $messageBus;
		$this->pushMessageSender = $pushMessageSender;
		$this->router = $router;
		$this->typableUtils = $typableUtils;
		$this->userSubscriptionManager = $userSubscriptionManager;
	}

	/////

	public function enqueueNewAnswerNotification(Answer $answer, Question $question) {
		$this->enqueueNotification(
			$question->getUser()->getId(),
			'Nouvelle réponse de '.$answer->getUser()->getDisplayname(),
			$this->cacheManager->getBrowserPath($answer->getUser()->getAvatar()->getWebPath(), '128x128o'),
			$this->typableUtils->getUrlAction($answer)
		);
	}

	public function enqueueNewLikeNotification(Like $like, LikableInterface $likable) {
		$this->enqueueNotification(
			$like->getEntityUser()->getId(),
			'Nouveau coup de coeur de '.$like->getUser()->getDisplayname(),
			$this->cacheManager->getBrowserPath($like->getUser()->getAvatar()->getWebPath(), '128x128o'),
			$this->typableUtils->getUrlAction($likable)
		);
	}

	public function enqueueNewMemberInvitationNotification(MemberInvitation $memberInvitation) {
		$this->enqueueNotification(
			$memberInvitation->getRecipient()->getId(),
			$memberInvitation->getSender()->getDisplayname().' vous invite à rejoindre le collectif '.$memberInvitation->getTeam()->getDisplayname(),
			$this->cacheManager->getBrowserPath($memberInvitation->getTeam()->getAvatar()->getWebPath(), '128x128o'),
			$this->router->generate('core_user_show', array( 'username' => $memberInvitation->getTeam()->getUsernameCanonical()), UrlGeneratorInterface::ABSOLUTE_URL)
		);
	}

	public function enqueueIncomingMessageNotification(User $recipient, User $sender, Thread $thread) {
		$this->enqueueNotification(
			$recipient->getId(),
			'Nouveau message de '.$sender->getDisplayname(),
			$this->cacheManager->getBrowserPath($sender->getAvatar()->getWebPath(), '128x128o'),
			$this->router->generate('core_message_thread_show', array( 'id' => $thread->getId()), UrlGeneratorInterface::ABSOLUTE_URL)
		);
	}

	public function enqueueNotification($userId, $body, $icon, $link) {
		try {
			$this->messageBus->dispatch(new WebpushNotificationMessage($userId, $body, $icon, $link));
		} catch (TransportException $e) {
			$this->logger->error('Failed to publish the webpush notification in queue.', array( 'exception' => $e ));
		}
	}

	/////

	public function sendNotification(User $user, string $body, string $icon = 'https://www.lairdubois.fr/favicon-144x144.png', string $link = 'https://www.lairdubois.fr') {

		// The payload of the Notification of bentools/webpush-bundle 0.3, that ladb-webpush-sw.js reads:
		// the PushNotification of the bundle 0.7 has "title" and "options" keys instead
		$message = new PushMessage(json_encode(array(
			'notification' => array(
				'title' => 'L\'Air du Bois',
				'body'  => $body,
				'icon'  => $icon,
				'data'  => array(
					'link' => $link,
				),
			),
		)));

		$subscriptions = $this->userSubscriptionManager->findByUser($user);
		foreach ($this->pushMessageSender->push($message, $subscriptions) as $response) {

			// Not sent (no answer from the push service...)
			if ($response instanceof \Throwable) {
				$this->logger->warning('WebpushNotificationUtils/sendNotification', array( 'exception' => $response ));
				continue;
			}

			// Delete expired subscriptions
			if ($response->isExpired()) {
				foreach ($this->userSubscriptionManager->findByHash($response->getSubscription()->getSubscriptionHash()) as $subscription) {
					$this->userSubscriptionManager->delete($subscription);
				}
			} else if (!$response->isSuccessFul()) {
				$this->logger->warning('WebpushNotificationUtils/sendNotification: refused by the push service.', array( 'statusCode' => $response->getStatusCode() ));
			}

		}

	}

}