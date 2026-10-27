<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Entity\Core\Mention;
use Ladb\CoreBundle\Fos\UserManager;
use Ladb\CoreBundle\Model\BodiedInterface;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Model\MentionSourceInterface;

class MentionUtils {

	const NAME = 'ladb_core.mention_utils';

	private $activityUtils;
	private $doctrine;
	private $userManager;

	public function __construct(ActivityUtils $activityUtils, ManagerRegistry $doctrine, UserManager $userManager) {
		$this->activityUtils = $activityUtils;
		$this->doctrine = $doctrine;
		$this->userManager = $userManager;
	}

	/////

	public function deleteMentions(MentionSourceInterface $entity, $flush = true) {
		$om = $this->doctrine->getManager();
		$mentionRepository = $om->getRepository(Mention::CLASS_NAME);

		$mentions = $mentionRepository->findByEntityTypeAndEntityId($entity->getType(), $entity->getId());
		foreach ($mentions as $mention) {
			$this->deleteMention($mention, $om, false);
		}
		if ($flush) {
			$om->flush();
		}
	}

	public function deleteMention(Mention $mention, $om, $flush = false) {

		// Delete relative activities
		$this->activityUtils->deleteActivitiesByMention($mention, false);

		// Remove Mention from DB
		$om->remove($mention);

		if ($flush) {
			$om->flush();
		}

	}

	/////

	public function processMentions(MentionSourceInterface $entity) {
		if ($entity instanceof HiddableInterface && !$entity->getIsPublic()) {
			return;		// Do nothing for non public entities
		}
		if ($entity instanceof BodiedInterface) {

			// Retrieve entity body
			$body = $entity->getBody();

			// Get unique mentions
			preg_match_all('/\B@([A-Za-z0-9]{3,})/', $body, $matches, PREG_SET_ORDER, 0);

			$mentionedUsernames = array();
			foreach ($matches as $match) {
				$username = strtolower($match[1]);
				if ($username != $entity->getUser()->getUsernameCanonical()) {
					$mentionedUsernames[] = $username;
				}
			}
			$mentionedUsernames = array_unique($mentionedUsernames);

			// Retrieve mentioned users
			$mentionedUsers = array();
			foreach ($mentionedUsernames as $username) {
				$user = $this->userManager->findUserByUsername($username);
				if (!is_null($user) && !in_array($user, $mentionedUsers)) {
					$mentionedUsers[] = $user;
				}
			}

			// Retrieve mentions
			$mentionRepository = $this->doctrine->getRepository(Mention::CLASS_NAME);
			$mentions = $mentionRepository->findByEntityTypeAndEntityId($entity->getType(), $entity->getId());

			$mentionsToRemove = array();
			$previouslyMentionedUsers = array();
			foreach ($mentions as $mention) {
				$previouslyMentionedUsers[] = $mention->getMentionedUser();
				if (!in_array($mention->getMentionedUser(), $mentionedUsers)) {
					$mentionsToRemove[] = $mention;
				}
			}

			$om = $this->doctrine->getManager();
			foreach ($mentionedUsers as $user) {
				if (!in_array($user, $previouslyMentionedUsers)) {

					// Create a new Mention
					$mention = new Mention();
					$mention->setEntityType($entity->getType());
					$mention->setEntityId($entity->getId());
					$mention->setUser($entity->getUser());
					$mention->setMentionedUser($user);

					$om->persist($mention);

					// Create activity
					$this->activityUtils->createMentionActivity($mention);

				}

			}
			foreach ($mentionsToRemove as $mention) {

				// Delete mention
				$this->deleteMention($mention, $om, false);

			}

			$om->flush();

		}
	}

}