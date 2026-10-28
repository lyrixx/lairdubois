<?php

namespace Ladb\CoreBundle\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ladb\CoreBundle\Utils\TextureUtils;
use Ladb\CoreBundle\Utils\KnowledgeUtils;

class KnowledgeListener implements EventSubscriberInterface {

	const FIELD_VALUE_ADDED = 'ladb.knowledge.field_value_added';
	const FIELD_VALUE_UPDATED = 'ladb.knowledge.field_value_updated';
	const FIELD_VALUE_REMOVED = 'ladb.knowledge.field_value_removed';

	private $knowledgeUtils;
	private $textureUtils;

	public function __construct(KnowledgeUtils $knowledgeUtils, TextureUtils $textureUtils) {
		$this->knowledgeUtils = $knowledgeUtils;
		$this->textureUtils = $textureUtils;
	}

	public static function getSubscribedEvents(): array {
		return array(
			KnowledgeListener::FIELD_VALUE_ADDED   => 'onFieldValueAdded',
			KnowledgeListener::FIELD_VALUE_UPDATED => 'onFieldValueUpdated',
			KnowledgeListener::FIELD_VALUE_REMOVED => 'onFieldValueRemoved',
		);
	}

	/////

	public function onFieldValueAdded(KnowledgeEvent $event) {
		$knowledge = $event->getKnowledge();
		$field = $event->getData()['field'];
		$value = $event->getData()['value'];

		// Update field
		$this->knowledgeUtils->updateKnowledgeField($knowledge, $field);

		// Source history
		$this->knowledgeUtils->pushToSourcesHistory($value);

		$knowledge->setUpdatedAt(new \DateTime());
		$knowledge->setChangedAt(new \DateTime());

		if ($knowledge instanceof \Ladb\CoreBundle\Entity\Knowledge\Wood
			&& ($field == \Ladb\CoreBundle\Entity\Knowledge\Wood::FIELD_GRAIN || $field == \Ladb\CoreBundle\Entity\Knowledge\Wood::FIELD_ENDGRAIN)) {

			$this->textureUtils->createTexture($knowledge, $value, false);

		}

	}

	public function onFieldValueUpdated(KnowledgeEvent $event) {
		$knowledge = $event->getKnowledge();
		$field = $event->getData()['field'];
		$value = $event->getData()['value'];

		// Update field
		$this->knowledgeUtils->updateKnowledgeField($knowledge, $field);

		// Source history
		$this->knowledgeUtils->pushToSourcesHistory($value);

		$knowledge->setUpdatedAt(new \DateTime());

		if ($knowledge instanceof \Ladb\CoreBundle\Entity\Knowledge\Wood
			&& ($field == \Ladb\CoreBundle\Entity\Knowledge\Wood::FIELD_GRAIN || $field == \Ladb\CoreBundle\Entity\Knowledge\Wood::FIELD_ENDGRAIN)) {

			$this->textureUtils->updateTexture($knowledge, $value, false);

		}

	}

	public function onFieldValueRemoved(KnowledgeEvent $event) {
		$knowledge = $event->getKnowledge();
		$field = $event->getData()['field'];
		$value = $event->getData()['value'];

		// Update field
		$this->knowledgeUtils->updateKnowledgeField($knowledge, $field);

		$knowledge->setUpdatedAt(new \DateTime());
		$knowledge->setChangedAt(new \DateTime());

		if ($knowledge instanceof \Ladb\CoreBundle\Entity\Knowledge\Wood
			&& ($field == \Ladb\CoreBundle\Entity\Knowledge\Wood::FIELD_GRAIN || $field == \Ladb\CoreBundle\Entity\Knowledge\Wood::FIELD_ENDGRAIN)) {

			$this->textureUtils->deleteTexture($knowledge, $value, false);

		}

	}

}