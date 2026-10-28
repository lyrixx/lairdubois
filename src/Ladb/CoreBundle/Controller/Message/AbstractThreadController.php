<?php

namespace Ladb\CoreBundle\Controller\Message;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Message\Message;
use Ladb\CoreBundle\Entity\Message\Thread;
use Ladb\CoreBundle\Utils\MailerUtils;
use Ladb\CoreBundle\Utils\WebpushNotificationUtils;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractThreadController extends AbstractController {

	private $mailerUtils;
	// Protected, like the one of UserControllerTrait, that a subclass can use
	protected $om;
	private $webpushNotificationUtils;

	#[Required]
	public function setMailerUtils(MailerUtils $mailerUtils) {
		$this->mailerUtils = $mailerUtils;
	}

	#[Required]
	public function setOm(EntityManagerInterface $om) {
		$this->om = $om;
	}

	#[Required]
	public function setWebpushNotificationUtils(WebpushNotificationUtils $webpushNotificationUtils) {
		$this->webpushNotificationUtils = $webpushNotificationUtils;
	}

	protected function retrieveThread($id) {
		$threadRepository = $this->om->getRepository(Thread::CLASS_NAME);
		$thread = $threadRepository->findOneByIdJoinedOnMetaAndParticipant($id);
		if (is_null($thread)) {
			throw $this->createNotFoundException('Unable to find Thread entity (id='.$id.').');
		}
		return $thread;
	}

	/////

	protected function assertShowable(Thread $thread) {
		if (!$thread->getParticipants()->contains($this->getUser())) {

			$memberRepository = $this->om->getRepository(Member::CLASS_NAME);
			$allowed = false;
			foreach ($thread->getParticipants() as $participant) {
				if ($memberRepository->existsByTeamAndUser($participant, $this->getUser())) {
					$allowed = true;
					break;
				}
			}

			if (!$allowed) {
				throw $this->createNotFoundException('Not allowed');
			}
		}
	}

	protected function assertDeletable(Thread $thread) {
		if (!$thread->getParticipants()->contains($this->getUser())) {
			throw $this->createNotFoundException('Not allowed');
		}
	}

	/////

	protected function notifyRecipientsForIncomingMessage(array $recipients, User $sender, Thread $thread, Message $message) {

		$memberRepository = $this->om->getRepository(Member::CLASS_NAME);
		$notifiedRecipients = array();
		foreach ($recipients as $recipient) {

			if ($recipient->getIsTeam()) {
				$members = $memberRepository->findPaginedByTeam($recipient);
				foreach ($members as $member) {

					if (in_array($member->getUser(), $notifiedRecipients) || $member->getUser() == $sender) {
						continue;
					}

					// Email notification
					$this->mailerUtils->sendIncomingMessageNotificationEmailMessage($recipient, $member->getUser(), $sender, $thread, $message);

					// Webpush notification
					$this->webpushNotificationUtils->enqueueIncomingMessageNotification($member->getUser(), $sender, $thread);

					// Increment unread message count
					$member->getUser()->getMeta()->incrementUnreadMessageCount();

					$notifiedRecipients[] = $member->getUser();
				}
			} else {

				if (in_array($recipient, $notifiedRecipients)) {
					continue;
				}

				// Email notification
				$this->mailerUtils->sendIncomingMessageNotificationEmailMessage($recipient, $recipient, $sender, $thread, $thread->getMessages()->last());

				// Webpush notification
				$this->webpushNotificationUtils->enqueueIncomingMessageNotification($recipient, $sender, $thread);

				// Increment unread message count
				$recipient->getMeta()->incrementUnreadMessageCount();

				$notifiedRecipients[] = $recipient;
			}

		}

	}

}
