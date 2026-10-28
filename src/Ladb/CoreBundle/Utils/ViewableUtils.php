<?php

namespace Ladb\CoreBundle\Utils;

use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Ladb\CoreBundle\Entity\Core\View;
use Ladb\CoreBundle\Message\ListedViewMessage;
use Ladb\CoreBundle\Message\ShownViewMessage;
use Ladb\CoreBundle\Model\HiddableInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Model\ViewableInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

class ViewableUtils {

	const NAME = 'ladb_core.viewable_utils';

	private $globalUtils;
	private $logger;
	private $messageBus;
	private $om;

	public function __construct(GlobalUtils $globalUtils, LoggerInterface $logger, MessageBusInterface $messageBus, EntityManagerInterface $om) {
		$this->globalUtils = $globalUtils;
		$this->logger = $logger;
		$this->messageBus = $messageBus;
		$this->om = $om;
	}

	/////

	public function deleteViews(ViewableInterface $viewable, $kind = View::KIND_NONE, $flush = true) {
		$viewRepository = $this->om->getRepository(View::CLASS_NAME);
		if ($kind == View::KIND_NONE) {
			$viewRepository->deleteByEntityTypeAndEntityId($viewable->getType(), $viewable->getId());
		} else {
			$viewRepository->deleteByEntityTypeAndEntityIdAndKind($viewable->getType(), $viewable->getId(), $kind);
		}
		if ($flush) {
			$this->om->flush();
		}
	}

	public function processShownView(ViewableInterface $viewable) {

		$CrawlerDetect = new CrawlerDetect();
		if ($CrawlerDetect->isCrawler()) {
			$this->logger->info('Crawler detected and excluded from processShownView : '.$_SERVER['HTTP_USER_AGENT']);
			return;	// Exclude bots
		}

		$user = $this->globalUtils->getUser();
		if (is_null($user)) {

			// No user -> use sessions

			$session = $this->globalUtils->getSession();
			$key = '_ladb_viewable_'.$viewable->getType();
			$shownIds = $session->get($key);
			if (is_null($shownIds)) {
				$shownIds = array();
			}
			if (!in_array($viewable->getId(), $shownIds)) {
				$shownIds[] = $viewable->getId();
				$session->set($key, $shownIds);
			} else {
				return;
			}

		}

		try {

			// Publish a view in queue
			$this->messageBus->dispatch(new ShownViewMessage(
				$viewable->getType(),
				$viewable->getId(),
				!is_null($user) ? $user->getId() : null
			));

		} catch (TransportException $e) {
			$this->logger->error('Failed to publish shown view process in queue', array ( 'exception' => $e));
		}

	}

	public function processListedView($viewables) {

		$user = $this->globalUtils->getUser();
		if (is_null($user)) {
			return;
		}

		$entityType = null;
		$entityIds = array();
		foreach($viewables as $viewable) {
			if ($viewable instanceof ViewableInterface) {
				$entityType = $viewable->getType();
				$entityIds[] = $viewable->getId();
			}
		}

		if (is_null($entityType)) {
			return;
		}

		try {

			// Publish a view in queue
			$this->messageBus->dispatch(new ListedViewMessage(
				$entityType,
				$entityIds,
				$user->getId()
			));

		} catch (TransportException $e) {
			$this->logger->error('Failed to publish shown view process in queue.');
		}

	}

	// Transfer /////

	public function transferViews(ViewableInterface $viewableSrc, ViewableInterface $viewableDest, $flush = true) {
		$viewRepository = $this->om->getRepository(View::CLASS_NAME);

		// Retrieve views
		$views = $viewRepository->findByEntityTypeAndEntityId($viewableSrc->getType(), $viewableSrc->getId());

		// Transfer views
		foreach ($views as $view) {
			$view->setEntityType($viewableDest->getType());
			$view->setEntityId($viewableDest->getId());
		}

		// Update counters
		$viewableDest->incrementViewCount($viewableSrc->getViewCount());
		$viewableSrc->setViewCount(0);

		if ($flush) {
			$this->om->flush();
		}
	}

}