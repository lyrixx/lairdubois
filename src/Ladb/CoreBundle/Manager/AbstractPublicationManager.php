<?php

namespace Ladb\CoreBundle\Manager;

use Ladb\CoreBundle\Entity\AbstractDraftableAuthoredPublication;
use Ladb\CoreBundle\Entity\AbstractPublication;
use Ladb\CoreBundle\Entity\Core\Block\Gallery;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Model\BlockBodiedInterface;
use Ladb\CoreBundle\Model\CollectionnableInterface;
use Ladb\CoreBundle\Model\CommentableInterface;
use Ladb\CoreBundle\Model\DraftableInterface;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Model\LikableInterface;
use Ladb\CoreBundle\Model\MentionSourceInterface;
use Ladb\CoreBundle\Model\MultiPicturedInterface;
use Ladb\CoreBundle\Model\ReportableInterface;
use Ladb\CoreBundle\Model\RepublishableInterface;
use Ladb\CoreBundle\Model\WatchableInterface;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\MentionUtils;
use Ladb\CoreBundle\Utils\ReportableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

abstract class AbstractPublicationManager extends AbstractManager {

	protected $collectionnableUtils;
	protected $commentableUtils;
	protected $dispatcher;
	protected $likableUtils;
	protected $mentionUtils;
	protected $reportableUtils;
	protected $watchableUtils;
	protected $witnessManager;

	/**
	 * @required
	 */
	public function setCollectionnableUtils(CollectionnableUtils $collectionnableUtils) {
		$this->collectionnableUtils = $collectionnableUtils;
	}

	/**
	 * @required
	 */
	public function setCommentableUtils(CommentableUtils $commentableUtils) {
		$this->commentableUtils = $commentableUtils;
	}

	/**
	 * @required
	 */
	public function setDispatcher(EventDispatcherInterface $dispatcher) {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * @required
	 */
	public function setLikableUtils(LikableUtils $likableUtils) {
		$this->likableUtils = $likableUtils;
	}

	/**
	 * @required
	 */
	public function setMentionUtils(MentionUtils $mentionUtils) {
		$this->mentionUtils = $mentionUtils;
	}

	/**
	 * @required
	 */
	public function setReportableUtils(ReportableUtils $reportableUtils) {
		$this->reportableUtils = $reportableUtils;
	}

	/**
	 * @required
	 */
	public function setWatchableUtils(WatchableUtils $watchableUtils) {
		$this->watchableUtils = $watchableUtils;
	}

	/**
	 * @required
	 */
	public function setWitnessManager(WitnessManager $witnessManager) {
		$this->witnessManager = $witnessManager;
	}

	/////

	public function lock(AbstractPublication $publication, $flush = true) {
		$om = $this->doctrine->getManager();

		$publication->setIsLocked(true);

		if ($flush) {
			$om->flush();
		}

		// Dispatch publication event
		$this->dispatcher->dispatch(new PublicationEvent($publication), PublicationListener::PUBLICATION_LOCKED);

	}

	public function unlock(AbstractPublication $publication, $flush = true) {
		$om = $this->doctrine->getManager();

		$publication->setIsLocked(false);

		if ($flush) {
			$om->flush();
		}

		// Dispatch publication event
		$this->dispatcher->dispatch(new PublicationEvent($publication), PublicationListener::PUBLICATION_UNLOCKED);

	}
	
	/////

	protected function publishPublication(AbstractPublication $publication, $flush = true) {
		$om = $this->doctrine->getManager();

		if ($publication instanceof RepublishableInterface) {
			if ($publication->getPublishCount() == 0) {
				$publication->setCreatedAt(new \DateTime());
			}
			$publication->incrementPublishCount();
		} else {
			$publication->setCreatedAt(new \DateTime());
		}
		$publication->setChangedAt(new \DateTime());

		if ($publication instanceof HiddableInterface) {
			$publication->setVisibility(HiddableInterface::VISIBILITY_PUBLIC);
		}

		if ($publication instanceof DraftableInterface) {
			$publication->setIsDraft(false);
		}

		// Process mentions
		$this->mentionUtils->processMentions($publication);

		// Delete the witness (if it exists)
		$this->witnessManager->deleteByPublication($publication);

		if ($flush) {
			$om->flush();
		}

		// Dispatch publication event
		$this->dispatcher->dispatch(new PublicationEvent($publication), PublicationListener::PUBLICATION_PUBLISHED);

	}

	protected function unpublishPublication(AbstractPublication $publication, $flush = true) {
		$om = $this->doctrine->getManager();

		if ($publication instanceof HiddableInterface) {
			$publication->setVisibility(HiddableInterface::VISIBILITY_PRIVATE);
		}

		if ($publication instanceof DraftableInterface) {
			$publication->setIsDraft(true);
		}

		if ($publication instanceof CollectionnableInterface) {
			// Delete collection's entries
			$this->collectionnableUtils->deleteEntries($publication, false);
		}

		// Delete mentions
		$this->mentionUtils->deleteMentions($publication);

		// Create the witness
		$this->witnessManager->createUnpublishedByPublication($publication, false);

		if ($flush) {
			$om->flush();
		}

		// Dispatch publication event
		$this->dispatcher->dispatch(new PublicationEvent($publication), PublicationListener::PUBLICATION_UNPUBLISHED);

	}

	protected function deletePublication(AbstractPublication $publication, $withWitness = true, $flush = true) {

		if ($publication instanceof MentionSourceInterface) {
			// Delete mentions
			$this->mentionUtils->deleteMentions($publication, false);
		}

		if ($publication instanceof WatchableInterface) {
			// Delete watches
			$this->watchableUtils->deleteWatches($publication, false);
		}

		if ($publication instanceof LikableInterface) {
			// Delete likes
			$this->likableUtils->deleteLikes($publication, false);
		}

		if ($publication instanceof CommentableInterface) {
			// Delete comments
			$this->commentableUtils->deleteComments($publication, false);
		}

		if ($publication instanceof CollectionnableInterface) {
			// Delete collection's entries
			$this->collectionnableUtils->deleteEntries($publication, false);
		}

		if ($publication instanceof ReportableInterface) {
			// Delete reports
			$this->reportableUtils->deleteReports($publication, false);
		}

		// Dispatch publication event
		$this->dispatcher->dispatch(new PublicationEvent($publication), PublicationListener::PUBLICATION_DELETED);

		if ($withWitness) {

			// Create the witness
			$this->witnessManager->createDeletedByPublication($publication, false);

		}

		parent::deleteEntity($publication, $flush);
	}

}