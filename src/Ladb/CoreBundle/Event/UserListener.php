<?php

namespace Ladb\CoreBundle\Event;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Fos\UserManager;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\FOSUserEvents;
use Ladb\CoreBundle\Entity\Core\Registration;
use Ladb\CoreBundle\Utils\MessageUtils;
use Ladb\CoreBundle\Utils\MailerUtils;
use Ladb\CoreBundle\Utils\UserUtils;

class UserListener implements EventSubscriberInterface {

	private $adminUsername;
	private $mailerUtils;
	private $messageUtils;
	private $om;
	private $templating;
	private $userManager;
	private $userUtils;

	public function __construct($adminUsername, MailerUtils $mailerUtils, MessageUtils $messageUtils, EntityManagerInterface $om, EngineInterface $templating, UserManager $userManager, UserUtils $userUtils) {
		$this->adminUsername = $adminUsername;
		$this->mailerUtils = $mailerUtils;
		$this->messageUtils = $messageUtils;
		$this->om = $om;
		$this->templating = $templating;
		$this->userManager = $userManager;
		$this->userUtils = $userUtils;
	}

	/////

	public static function getSubscribedEvents() {
		return array(
			FOSUserEvents::REGISTRATION_COMPLETED => 'onRegistrationCompleted',
		);
	}

	/////

	public function onRegistrationCompleted(FilterUserResponseEvent $event) {
		$user = $event->getUser();

		/////

		// Create default avatar
		$this->userUtils->createDefaultAvatar($user, false);

		/////

		$registration = new Registration();
		$registration->setUser($user);
		$registration->setClientIp4($event->getRequest()->getClientIp());
		$registration->setClientUserAgent($event->getRequest()->server->get('HTTP_USER_AGENT'));

		$this->om->persist($registration);
		$this->om->flush();

		/////

		// Send welcome Message
		$sender = $this->userManager->findUserByUsername($this->adminUsername);
		if (!is_null($sender)) {
			// Unread message count: composeThread() leaves it to the caller
			$user->getMeta()->incrementUnreadMessageCount();
			$this->messageUtils->composeThread($sender, array( $user ), 'Bienvenue !', $this->templating->render('@LadbCore/Core/User/message-welcome.md.twig', array( 'recipientUser' => $user )), null, true);
		}

		// Send admin email notification
		$this->mailerUtils->sendNewUserNotificationEmailMessage($user);

	}

}