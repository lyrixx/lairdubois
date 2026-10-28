<?php

namespace Ladb\CoreBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\Resource;

class ResourceToIdTransformer implements DataTransformerInterface {

	private $om;

	public function __construct(ObjectManager $om) {
		$this->om = $om;
	}

	public function transform($resource): mixed {
		if (null === $resource) {
			return '';
		}

		if (!$resource instanceof \Ladb\CoreBundle\Entity\Core\Resource) {
			throw new UnexpectedTypeException($resource, '\Ladb\CoreBundle\Entity\Core\Resource');
		}

		return $resource->getId();
	}

	public function reverseTransform($idString): mixed {
		if (!$idString) {
			return null;
		}

		$id = intval($idString);
		if ($id == 0) {
			throw new TransformationFailedException();
		}
		$resource = $this->om
			->getRepository(Resource::CLASS_NAME)
			->find($id);
		if (is_null($resource)) {
			throw new TransformationFailedException();
		}

		return $resource;
	}

}