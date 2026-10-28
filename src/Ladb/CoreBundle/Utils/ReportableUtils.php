<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Entity\Core\Report;
use Ladb\CoreBundle\Model\ReportableInterface;

class ReportableUtils {

	const NAME = 'ladb_core.reportable_utils';

	private $doctrine;

	public function __construct(ManagerRegistry $doctrine) {
		$this->doctrine = $doctrine;
	}

	/////

	public function deleteReports(ReportableInterface $likable, $flush = true) {
		$om = $this->doctrine->getManager();
		$reportRepository = $om->getRepository(Report::CLASS_NAME);
		$reports = $reportRepository->findByEntityTypeAndEntityId($likable->getType(), $likable->getId());
		foreach ($reports as $report) {
			$om->remove($report);
		}
		if ($flush) {
			$om->flush();
		}
	}

	/////

	public function transferReports(ReportableInterface $reportableSrc, ReportableInterface $reportableDest, $flush = true) {
		$om = $this->doctrine->getManager();
		$reportRepository = $om->getRepository(Report::CLASS_NAME);

		// Retrieve reports
		$reports = $reportRepository->findByEntityTypeAndEntityId($reportableSrc->getType(), $reportableSrc->getId());

		// Transfer reports
		foreach ($reports as $report) {
			$report->setEntityType($reportableDest->getType());
			$report->setEntityId($reportableDest->getId());
		}

		if ($flush) {
			$om->flush();
		}
	}

}