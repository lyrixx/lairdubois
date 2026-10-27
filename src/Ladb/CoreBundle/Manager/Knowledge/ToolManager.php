<?php

namespace Ladb\CoreBundle\Manager\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\Tool;
use Ladb\CoreBundle\Utils\ReviewableUtils;

class ToolManager extends AbstractKnowledgeManager {

	const NAME = 'ladb_core.knowledge_tool_manager';

	private $reviewableUtils;

	public function __construct(ReviewableUtils $reviewableUtils) {
		$this->reviewableUtils = $reviewableUtils;
	}

	public function delete(Tool $tool, $withWitness = true, $flush = true) {

		// Delete reviews
		$this->reviewableUtils->deleteReviews($tool, false);

		parent::deleteKnowledge($tool, $withWitness, $flush);
	}

}