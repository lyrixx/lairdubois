<?php

namespace Ladb\CoreBundle\Utils;

use Ladb\CoreBundle\Entity\Core\MemberInvitation;
use Ladb\CoreBundle\Entity\Core\MemberRequest;
use Ladb\CoreBundle\Manager\Webpush\UserSubscriptionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use BenTools\WebPushBundle\Model\Message\PushMessage;
use BenTools\WebPushBundle\Sender\PushMessageSender;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Entity\Message\Thread;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Model\LikableInterface;

class WebpushNotificationUtils extends AbstractContainerAwareUtils {

	private $pushMessageSender;
	private $userSubscriptionManager;
	private $logger;

	public function __construct(ContainerInterface $container, PushMessageSender $pushMessageSender, UserSubscriptionManager $userSubscriptionManager, LoggerInterface $logger) {
		parent::__construct($container);
		$this->pushMessageSender = $pushMessageSender;
		$this->userSubscriptionManager = $userSubscriptionManager;
		$this->logger = $logger;
	}

	/////

	public function enqueueNewAnswerNotification(Answer $answer, Question $question) {
		$this->enqueueNotification(
			$question->getUser()->getId(),
			'Nouvelle réponse de '.$answer->getUser()->getDisplayname(),
			$this->get('liip_imagine.cache.manager')->getBrowserPath($answer->getUser()->getAvatar()->getWebPath(), '128x128o'),
			$this->get(TypableUtils::NAME)->getUrlAction($answer)
		);
	}

	public function enqueueNewLikeNotification(Like $like, LikableInterface $likable) {
		$this->enqueueNotification(
			$like->getEntityUser()->getId(),
			'Nouveau coup de coeur de '.$like->getUser()->getDisplayname(),
			$this->get('liip_imagine.cache.manager')->getBrowserPath($like->getUser()->getAvatar()->getWebPath(), '128x128o'),
			$this->get(TypableUtils::NAME)->getUrlAction($likable)
		);
	}

	public function enqueueNewMemberInvitationNotification(MemberInvitation $memberInvitation) {
		$this->enqueueNotification(
			$memberInvitation->getRecipient(),
			$memberInvitation->getSender()->getDisplayname().' vous invite à rejoindre le collectif '.$memberInvitation->getTeam()->getDisplayname(),
			$this->get('liip_imagine.cache.manager')->getBrowserPath($memberInvitation->getTeam()->getAvatar()->getWebPath(), '128x128o'),
			$this->get('router')->generate('core_user_show', array( 'username' => $memberInvitation->getTeam()->getUsernameCanonical()), UrlGeneratorInterface::ABSOLUTE_URL)
		);
	}

	public function enqueueIncomingMessageNotification(User $recipient, User $sender, Thread $thread) {
		$this->enqueueNotification(
			$recipient,
			'Nouveau message de '.$sender->getDisplayname(),
			$this->get('liip_imagine.cache.manager')->getBrowserPath($sender->getAvatar()->getWebPath(), '128x128o'),
			$this->get('router')->generate('core_message_thread_show', array( 'id' => $thread->getId()), UrlGeneratorInterface::ABSOLUTE_URL)
		);
	}

	public function enqueueNotification($userId, $body, $icon, $link) {
		try {
			$producer = $this->container->get('old_sound_rabbit_mq.webpush_notification_producer');
			$producer->publish(serialize(array(
				'userId' => $userId,
				'body'   => $body,
				'icon'   => $icon,
				'link'   => $link,
			)));
		} catch (\Exception $e) {

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