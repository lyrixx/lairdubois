<?php

namespace Ladb\CoreBundle\Controller\Message;

use Ladb\CoreBundle\Entity\Message\MessageMeta;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Utils\WebpushNotificationUtils;
use Ladb\CoreBundle\Entity\Message\Thread;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\MessageUtils;
use Ladb\CoreBundle\Form\Type\Message\NewThreadMessageType;
use Ladb\CoreBundle\Form\Model\NewThreadMessage;
use Ladb\CoreBundle\Utils\MailerUtils;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/messagerie')]
class ThreadController extends AbstractThreadController {

	/**
	 * @Template("@LadbCore/Message/newThread.html.twig")
	 */
	#[Route(path: '/thread/new', name: 'core_message_thread_new', defaults: ['recipientUsername' => null, 'announcement' => false])]
	#[Route(path: '/thread/to/{recipientUsername}/new', requirements: ['recipientUsername' => '[a-zA-Z0-9]+'], name: 'core_message_thread_new_recipientusername', defaults: ['announcement' => false])]
	public function newAction($recipientUsername, UserManager $userManager, $subject = null, $message = null, $alertTemplate =  null) {
//		if (!is_null($this->getUser()) && $this->getUser()->getIsTeam()) {
//			throw $this->createNotFoundException('Team not allowed (core_message_thread_new)');
//		}

		$newThreadMessage = new NewThreadMessage();

		if (!is_null($recipientUsername)) {
			$recipient = $userManager->findUserByUsername($recipientUsername);
			if (is_null($recipient)) {
				throw $this->createNotFoundException('User not found (core_message_thread_new_recipientusername)');
			}
			$newThreadMessage->addRecipient($recipient);
		}

		if (!is_null($subject)) {
			$newThreadMessage->setSubject($subject);
		}

		if (!is_null($message)) {
			$newThreadMessage->setBody($message);
		}

		$form = $this->createForm(NewThreadMessageType::class, $newThreadMessage);

		return array(
			'form'          => $form->createView(),
			'alertTemplate' => $alertTemplate,
		);
	}

	/**
	 * @Template("@LadbCore/Message/newThread.html.twig")
	 */
	#[Route(path: '/thread/create', methods: ['POST'], name: 'core_message_thread_create')]
	public function createAction(Request $request, MailerUtils $mailerUtils, MessageUtils $messageUtils, EntityManagerInterface $om, TranslatorInterface $translator) {
//		if (!is_null($this->getUser()) && $this->getUser()->getIsTeam()) {
//			throw $this->createNotFoundException('Team not allowed (core_message_thread_create)');
//		}

		$this->createLock('core_message_thread_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$newThreadMessage = new NewThreadMessage();
		$form = $this->createForm(NewThreadMessageType::class, $newThreadMessage);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$sender = $this->getUser();
			$recipients = $newThreadMessage->getRecipients()->toArray();

			// Exclude "SPAM" threads
			$threadRepository = $om->getRepository(Thread::CLASS_NAME);
			if ($threadRepository->existsBySenderAndSubjectAndBody($sender, $newThreadMessage->getSubject(), $newThreadMessage->getBody())) {

				// Email notification
				$mailerUtils->sendSpamThreadNotificationEmailMessage($sender, $recipients, $newThreadMessage->getSubject(), $newThreadMessage->getBody());

				throw $this->createNotFoundException('SPAM thread detected (sender='.$sender->getUsername().', subject='.$newThreadMessage->getSubject().').');
			}

			$thread = $messageUtils->composeThread($sender, $recipients, $newThreadMessage->getSubject(), $newThreadMessage->getBody(), $newThreadMessage->getPictures());

			// Flashbag
			$recipientNames = '';
			foreach ($recipients as $recipient) {
				if (strlen($recipientNames) != 0) {
					$recipientNames .= ', ';
				}
				$recipientNames .= $recipient->getDisplayname();
			}
			$this->addFlash('success', 'Votre message a été envoyé à <strong>'.$recipientNames.'</strong>.');

			if (!$sender->getEmailConfirmed() && $sender->getMeta()->getIncomingMessageEmailNotificationEnabled()) {
				$this->addFlash('error', $translator->trans('default.alert.email_not_confirmed_error'));
			}

			// Notifications (after flush to have a thread id)
			$this->notifyRecipientsForIncomingMessage($recipients, $sender, $thread, $thread->getMessages()->last());

			return $this->redirect($this->generateUrl('core_message_thread_show', array( 'id' => $thread->getId())));
		}

		return array(
			'form'         => $form->createView(),
			'announcement' => false,
		);
	}

	/**
	 * @Template("@LadbCore/Message/deleteThread.html.twig")
	 */
	#[Route(path: '/thread/{id}/delete', requirements: ['id' => '\d+'], name: 'core_message_thread_delete')]
	public function deleteAction($id, EntityManagerInterface $om) {
		$threadRepository = $om->getRepository(Thread::CLASS_NAME);

		$thread = $this->retrieveThread($id);
		$this->assertDeletable($thread);

		$unreadMessageCount = $threadRepository->countUnreadMessageByThreadAndUser($thread, $this->getUser());
		if ($unreadMessageCount > 0) {
			$this->getUser()->getMeta()->incrementUnreadMessageCount(-$unreadMessageCount);
		}

		$remainingCount = 0;
		foreach ($thread->getMetas() as $threadMeta) {
			if ($threadMeta->getParticipant() === $this->getUser()) {
				$threadMeta->setIsDeleted(true);
			} else if (!$threadMeta->getIsDeleted()) {
				$remainingCount++;
			}
		}

		if ($remainingCount == 0) {
			$om->remove($thread);
		}
		$om->flush();

		return $this->redirect($this->generateUrl('core_message_mailbox'));
	}

	/**
	 * @Template("@LadbCore/Message/showThread.html.twig")
	 */
	#[Route(path: '/thread/{id}', requirements: ['id' => '\d+'], name: 'core_message_thread_show')]
	public function showAction($id, EntityManagerInterface $om) {

		$this->createLock('core_message_thread_show', true, 3, false);

		$messageMetaRepository = $om->getRepository(MessageMeta::CLASS_NAME);

		$thread = $this->retrieveThread($id);
		$this->assertShowable($thread);

		// Flag messages as read

		$participant = $this->getUser();

		$unreadMessageCount = 0;
		foreach ($thread->getMessages() as $message) {

			$messageMeta = $messageMetaRepository->findOneByMessageAndParticipant($message, $participant);
			if (is_null($messageMeta)) {

				$message->setIsRead(false);	// used to view only

				$messageMeta = new MessageMeta();
				$messageMeta->setParticipant($participant);
				$messageMeta->setIsRead(true);
				$message->addMeta($messageMeta);

				$om->persist($messageMeta);

				$unreadMessageCount++;
			} else {
				if (!$messageMeta->getIsRead()) {
					$messageMeta->setIsRead(true);
					$unreadMessageCount++;
				}
			}

		}

		if ($unreadMessageCount > 0) {

			// Decrement unread message count
			$participant->getMeta()->incrementUnreadMessageCount(-$unreadMessageCount);

			$om->flush();

		}

		return array(
			'thread' => $thread,
		);
	}

	/**
	 * @Template("@LadbCore/Message/mailbox.html.twig")
	 */
	#[Route(path: '/', name: 'core_message_mailbox')]
	#[Route(path: '/{filter}', requirements: ['filter' => '\w+'], name: 'core_message_mailbox_filter')]
	#[Route(path: '/{filter}/{page}', requirements: ['filter' => '\w+', 'page' => '\d+'], name: 'core_message_mailbox_filter_page')]
	public function mailboxAction(Request $request, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = 'all', $page = 0) {
		$threadRepository = $om->getRepository(Thread::CLASS_NAME);

		// Compute allowed user list by adding user's teams
		$users = array( $this->getUser() );
		if ($this->getUser()->getMeta()->getTeamCount() > 0) {
			$memberRepository = $om->getRepository(Member::CLASS_NAME);
			$members = $memberRepository->findPaginedByUser($this->getUser());
			foreach ($members as $member) {
				$users[] = $member->getTeam();
			}
		}

		$offset = $paginatorUtils->computePaginatorOffset($page, 20, 20);
		$limit = $paginatorUtils->computePaginatorLimit($page, 20, 20);
		$paginator = $threadRepository->findPaginedByUsers($users, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_message_mailbox_filter_page', array( 'filter' => $filter ), $page, $paginator->count(), 20, 20);

		// Compute unreadMessageCount

		$threads = $paginator;

		$participant = $this->getUser();
		foreach ($threads as $thread) {
			$thread->setUnreadMessageCount($threadRepository->countUnreadMessageByThreadAndUser($thread, $participant));
		}

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'threads'     => $threads,
			'threadCount' => $paginator->count(),
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Message/mailbox-xhr.html.twig', $parameters);
		}
		return $parameters;
	}

}
