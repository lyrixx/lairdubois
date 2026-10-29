<?php

namespace Ladb\CoreBundle\Form\DataTransformer\Workflow;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Workflow\Label;

class LabelsToIdsTransformer implements DataTransformerInterface {

	private $om;

	public function __construct(ObjectManager $om) {
		$this->om = $om;
	}

	public function transform($labels): mixed {
		if (null === $labels) {
			return '';
		}

		if (!$labels instanceof \Doctrine\Common\Collections\Collection) {
			throw new UnexpectedTypeException($labels, '\Doctrine\Common\Collections\Collection');
		}

		$idsArray = array();
		foreach ($labels as $label) {
			$idsArray[] = $label->getId();
		}
		return implode(',', $idsArray);
	}

	public function reverseTransform($idsString): mixed {
		if (!$idsString) {
			return array();
		}

		$labels = array();
		$idsStrings = preg_split("/[,]+/", $idsString);
		$repository = $this->om->getRepository(Label::class);
		foreach ($idsStrings as $idString) {
			$id = intval($idString);
			if ($id == 0) {
				continue;
			}
			$label = $repository->find($id);
			if (is_null($label)) {
				throw new TransformationFailedException();
			}
			$labels[] = $label;
		}

		return $labels;
	}

}