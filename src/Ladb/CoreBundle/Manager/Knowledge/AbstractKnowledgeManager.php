<?php

namespace Ladb\CoreBundle\Manager\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\AbstractKnowledge;
use Ladb\CoreBundle\Manager\AbstractPublicationManager;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\PropertyUtils;
use Ladb\CoreBundle\Utils\VotableUtils;

abstract class AbstractKnowledgeManager extends AbstractPublicationManager {

	protected $activityUtils;
	protected $propertyUtils;
	protected $votableUtils;

	/**
	 * @required
	 */
	public function setActivityUtils(ActivityUtils $activityUtils) {
		$this->activityUtils = $activityUtils;
	}

	/**
	 * @required
	 */
	public function setPropertyUtils(PropertyUtils $propertyUtils) {
		$this->propertyUtils = $propertyUtils;
	}

	/**
	 * @required
	 */
	public function setVotableUtils(VotableUtils $votableUtils) {
		$this->votableUtils = $votableUtils;
	}

	protected function deleteKnowledge(AbstractKnowledge $knowledge, $withWitness = true, $flush = true) {

		// Delete values (votes and proposals count)
		$fieldDefs = $knowledge->getFieldDefs();
		foreach ($fieldDefs as $field => $fieldDef) {
			$values = $this->propertyUtils->getValue($knowledge, $field.'_values');
			foreach ($values as $value) {

				// Decrement user proposal count
				$value->getUser()->getMeta()->incrementProposalCount(-1);

				// Delete comments
				$this->commentableUtils->deleteComments($value, false);

				// Delete votes
				$this->votableUtils->deleteVotes($value, $knowledge, false);

				// Delete activities
				$this->activityUtils->deleteActivitiesByValue($value, false);

			}
		}

		parent::deletePublication($knowledge, $withWitness, $flush);
	}

}