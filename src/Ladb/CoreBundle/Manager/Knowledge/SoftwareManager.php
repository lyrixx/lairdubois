<?php

namespace Ladb\CoreBundle\Manager\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\Software;
use Ladb\CoreBundle\Utils\ReviewableUtils;

class SoftwareManager extends AbstractKnowledgeManager {

	const NAME = 'ladb_core.knowledge_software_manager';

	private $reviewableUtils;

	public function __construct(ReviewableUtils $reviewableUtils) {
		$this->reviewableUtils = $reviewableUtils;
	}

	public function delete(Software $software, $withWitness = true, $flush = true) {

		// Delete reviews
		$this->reviewableUtils->deleteReviews($software, false);

		parent::deleteKnowledge($software, $withWitness, $flush);
	}

}