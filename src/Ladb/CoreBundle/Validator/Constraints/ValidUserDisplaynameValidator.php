<?php

namespace Ladb\CoreBundle\Validator\Constraints;

use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Utils\DisplaynameCanonicalizer;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Ladb\CoreBundle\Entity\Core\User;

class ValidUserDisplaynameValidator extends ConstraintValidator {

	private $displaynameCanonicalizer;
	private $doctrine;

	public function __construct(DisplaynameCanonicalizer $displaynameCanonicalizer, ManagerRegistry $doctrine) {
		$this->displaynameCanonicalizer = $displaynameCanonicalizer;
		$this->doctrine = $doctrine;
	}

	/**
	 * Checks if the passed value is valid.
	 *
	 * @param mixed $value      The value that should be validated
	 * @param Constraint $constraint The constraint for the validation
	 *
	 * @api
	 */
	public function validate($value, Constraint $constraint): void {
		if ($value instanceof User) {

			try {
				$displaynameCanonical = $this->displaynameCanonicalizer->canonicalize($value->getDisplayname());
			} catch(\Exception $e) {
				$this->context->buildViolation('Ce nom est invalide.')
					->atPath('displayname')
					->addViolation();
				return;
			}

			if (in_array($displaynameCanonical, ValidUserUsernameValidator::UNAUTHORIZED_USERNAMES)) {
				$this->context->buildViolation('Ce nom n\'est pas autorisé.')
					->atPath('displayname')
					->addViolation();
			}

			$userRepository = $this->doctrine->getRepository(User::class);
			$currentUser = $value->getId() ? $userRepository->findOneById($value->getId()) : null;
			$user = $userRepository->findOneByDisplaynameCanonical($displaynameCanonical);

			if (!is_null($user) && $user !== $currentUser) {
				$this->context->buildViolation('Ce nom est déjà utilisé.')
					->atPath('displayname')
					->addViolation();
			}

		}
	}

}