<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Persistence\ManagerRegistry;
use Imagine\Gd\Font;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Point;
use Imagine\Image\Palette\RGB;
use Ladb\CoreBundle\Entity\AbstractPublication;
use Ladb\CoreBundle\Entity\Core\Picture;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Core\View;
use Ladb\CoreBundle\Fos\UserManager;
use Ladb\CoreBundle\Manager\Core\PictureManager;
use Ladb\CoreBundle\Model\HiddableInterface;

class UserUtils {

	const NAME = 'ladb_core.user_utils';

	const COUNTABLE_TYPES = array(
		\Ladb\CoreBundle\Entity\Wonder\Creation::TYPE,
		\Ladb\CoreBundle\Entity\Qa\Question::TYPE,
		\Ladb\CoreBundle\Entity\Wonder\Plan::TYPE,
		\Ladb\CoreBundle\Entity\Wonder\Workshop::TYPE,
		\Ladb\CoreBundle\Entity\Howto\Howto::TYPE,
		\Ladb\CoreBundle\Entity\Knowledge\Wood::TYPE,
		\Ladb\CoreBundle\Entity\Knowledge\Tool::TYPE,
		\Ladb\CoreBundle\Entity\Knowledge\Book::TYPE,
		\Ladb\CoreBundle\Entity\Knowledge\Software::TYPE,
		\Ladb\CoreBundle\Entity\Collection\Collection::TYPE,
		\Ladb\CoreBundle\Entity\Knowledge\Provider::TYPE,
		\Ladb\CoreBundle\Entity\Knowledge\School::TYPE,
		\Ladb\CoreBundle\Entity\Find\Find::TYPE,
		\Ladb\CoreBundle\Entity\Event\Event::TYPE,
		\Ladb\CoreBundle\Entity\Offer\Offer::TYPE,
		\Ladb\CoreBundle\Entity\Workflow\Workflow::TYPE,
		\Ladb\CoreBundle\Entity\Promotion\Graphic::TYPE,
		\Ladb\CoreBundle\Entity\Blog\Post::TYPE,
		\Ladb\CoreBundle\Entity\Faq\Question::TYPE,
	);

	private $memcached;

	private $doctrine;
	private $memcachedHost;
	private $memcachedPort;
	private $pictureManager;
	private $propertyUtils;
	private $typableUtils;
	private $userManager;

	public function __construct(ManagerRegistry $doctrine, $memcachedHost, $memcachedPort, PictureManager $pictureManager, PropertyUtils $propertyUtils, TypableUtils $typableUtils, UserManager $userManager) {
		$this->doctrine = $doctrine;
		$this->memcachedHost = $memcachedHost;
		$this->memcachedPort = $memcachedPort;
		$this->pictureManager = $pictureManager;
		$this->propertyUtils = $propertyUtils;
		$this->typableUtils = $typableUtils;
		$this->userManager = $userManager;
	}

	/////

	// The refresh dates are in Memcached, per user, not in the session: /@me/counters.json would write the session back, and erase what a concurrent request has just put in it (the CSRF token of a form loaded in XHR)
	private function getMemcached() {
		if (is_null($this->memcached)) {
			$this->memcached = new \Memcached();
			$this->memcached->addServer($this->memcachedHost, $this->memcachedPort);
		}
		return $this->memcached;
	}

	public function _getUnlistedCounterRefreshDateKeyByEntityType(User $user, $entityType) {
		return 'ladb_unlisted_counter_refresh_date_'.$user->getId().'_'.$entityType;
	}

	public function _setUnlistedCounterRefreshDateByEntityType(User $user, $entityType, $refreshDate) {
		$key = $this->_getUnlistedCounterRefreshDateKeyByEntityType($user, $entityType);
		if (is_null($refreshDate)) {
			$this->getMemcached()->delete($key);
		} else {
			// Kept until the refresh date, after which the counter is computed again
			$this->getMemcached()->set($key, $refreshDate, $refreshDate->getTimestamp() - time());
		}
	}

	public function _getUnlistedCounterRefreshDateByEntityType(User $user, $entityType, $now) {
		$key = $this->_getUnlistedCounterRefreshDateKeyByEntityType($user, $entityType);
		$refreshDate = $this->getMemcached()->get($key);
		if ($refreshDate === false) {
			return $now;
		}
		return $refreshDate;
	}

	/////

	public function computeUnlistedCounters(User $user, $ignoredEntityType = null) {

		$updated = false;
		foreach (self::COUNTABLE_TYPES as $entityType) {
			if ($entityType == $ignoredEntityType) {
				continue;
			}
			$updated |= $this->computeUnlistedCounterByEntityType($user, $entityType, false);
		}

		if ($updated) {
			$this->userManager->updateUser($user);
		}

	}

	public function computeUnlistedCounterByEntityType(User $user, $entityType, $flush = true, $incrementUnlistedCounterRefreshTime = true) {
		if (is_null($user)) {
			return false;
		}

		$meta = $user->getMeta();
		$entityStrippedName = $this->typableUtils->getStrippedNameByType($entityType);

		$badgeEnabledPropertyPath = $entityStrippedName.'s_badge_enabled';
		$unlistedCountPropertyPath = 'unlisted_'.$entityStrippedName.'_count';

		// Check if badge is enabled for this entity type
		if (!$this->propertyUtils->getValue($meta, $badgeEnabledPropertyPath)) {

			// Reset (if necessary) count to 0 and returns
			if ($this->propertyUtils->getValue($meta, $unlistedCountPropertyPath) > 0) {
				$this->propertyUtils->setValue($meta, $unlistedCountPropertyPath, 0);
				return true;
			}

			return false;
		}

		// Check refresh date
		$now = new \DateTime();
		$refreshDate = $this->_getUnlistedCounterRefreshDateByEntityType($user, $entityType, $now);
		if ($now < $refreshDate) {
			return false;
		}

		if ($incrementUnlistedCounterRefreshTime) {
			$this->incrementUnlistedCounterRefreshTimeByEntityType($user, $entityType, 'PT'.mt_rand(180, 300).'S' /* = between 3 and 5 min */);
		}

		$om = $this->doctrine->getManager();
		$viewRepository = $om->getRepository(View::CLASS_NAME);
		$lastView = $viewRepository->findLastCreatedByEntityTypeAndUserAndKindGreaterOrEquals($entityType, $user, View::KIND_LISTED);
		if (is_null($lastView)) {
			$lastViewDate = max(new \DateTime('2014-11-22'), $user->getCreatedAt());
		} else {
			$lastViewDate = $lastView->getCreatedAt();
		}
		if (!is_null($lastViewDate)) {
			$entityRepository = $this->typableUtils->getRepositoryByType($entityType);
			if (!is_null($entityRepository)) {

				$entityClass = $this->typableUtils->getClassByType($entityType);
				$andWheres = array();
				$parameters = array();
				if (is_subclass_of($entityClass, '\Ladb\CoreBundle\Model\HiddableInterface')) {
					$andWheres[] = 'e.visibility = '.HiddableInterface::VISIBILITY_PUBLIC;
				}
				if (is_subclass_of($entityClass, '\Ladb\CoreBundle\Model\AuthoredInterface')) {
					$andWheres[] = 'e.user != :user';
					$parameters = array_merge($parameters, array( 'user' => $user ));
				}
				// TODO
//				if (is_subclass_of($entityClass, '\Ladb\CoreBundle\Entity\Knowledge\AbstractKnowledge')) {
//					$andWheres[] = 'e.isRejected = false';
//				}
				$count = $entityRepository->countNewerByDate($lastViewDate, $andWheres, $parameters);

				// Update count value on user entity
				if ($count != $this->propertyUtils->getValue($meta, $unlistedCountPropertyPath)) {

					$this->propertyUtils->setValue($meta, $unlistedCountPropertyPath, $count);

					if ($flush) {
						$this->userManager->updateUser($user);
					}

					return true;
				}

			}
		}

		return false;	// Returns updated
	}

	public function incrementUnlistedCounterRefreshTimeByEntityType(User $user, $entityType, $inc) {
		$this->_setUnlistedCounterRefreshDateByEntityType($user, $entityType, (new \DateTime())->add(new \DateInterval($inc)));
	}

	public function resetUnlistedCounterRefreshTimeByEntityType(User $user, $entityType) {
		$this->_setUnlistedCounterRefreshDateByEntityType($user, $entityType, null);
	}

	/////

	public function createDefaultAvatar(User $user, $randomColor = true) {

		$colors = array( '006ba6', '0496ff', 'ffbc42', 'd81159', '8f2d56' );

		// Instantiate Imagine
		$imagine = new Imagine();

		// Create avatar picture
		$avatar = $this->pictureManager->createEmpty();
		$avatar->setUser($user);

		$width = 512;
		$height = 512;
		$fontSize = 400;
		$backgroundColor = $colors[$randomColor ? mt_rand(0, count($colors) - 1) : $user->getId() % count($colors)];
		$foregroundColor = 'fff';
		$letter = substr(empty($user->getDisplayname()) ? $user->getUsername() : $user->getDisplayname(), 0, 1);

		$palette = new RGB();

		// Output
		$avatarSize = new Box(
			$width,
			$height
		);
		$avatarImage = $imagine->create($avatarSize, $palette->color($backgroundColor));

		$font = new Font(__DIR__.'/../Resources/private/fonts/ClassicRaw.ttf', $fontSize, $palette->color($foregroundColor));
		$avatarImage->draw()->text(
			$letter,
			$font,
			new Point(
				($width - $font->box($letter)->getWidth()) / 2,
				($height - $fontSize) / 2 - 40	// 40 = workaround to center uppercase letter
			)
		);

		// Save avater image
		$avatarImage->save($avatar->getAbsoluteMasterPath(), array( 'format' => 'png', 'png_compression_level' => 9 ));

		// Add avatar to user
		$user->setAvatar($avatar);

		return $avatar;
	}

}