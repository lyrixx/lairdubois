<?php
namespace Ladb\CoreBundle\Consumer;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Message\WebpushNotificationMessage;
use Psr\Log\LoggerInterface;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Utils\WebpushNotificationUtils;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class WebpushNotificationConsumer {

	private $logger;
	private $om;
	private $userRepository;
	private $webpushNotificationUtils;

	public function __construct(LoggerInterface $logger, EntityManagerInterface $om, WebpushNotificationUtils $webpushNotificationUtils) {

		$this->logger = $logger;

		$this->om = $om;
		$this->userRepository = $this->om->getRepository(User::CLASS_NAME);
		$this->webpushNotificationUtils = $webpushNotificationUtils;

	}

	/////

	public function __invoke(WebpushNotificationMessage $message) {

		$user = $this->userRepository->findOneById($message->getUserId());
		if (!is_null($user)) {

			// Send notification
			$this->webpushNotificationUtils->sendNotification($user, $message->getBody(), $message->getIcon(), $message->getLink());

		}

	}

}