<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Entity\Core\Review;
use Ladb\CoreBundle\Model\DraftableInterface;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Model\ReviewableInterface;

class ReviewableUtils {

	const NAME = 'ladb_core.reviewable_utils';

	private $activityUtils;
	private $doctrine;
	private $globalUtils;

	public function __construct(ActivityUtils $activityUtils, ManagerRegistry $doctrine, GlobalUtils $globalUtils) {
		$this->activityUtils = $activityUtils;
		$this->doctrine = $doctrine;
		$this->globalUtils = $globalUtils;
	}
	
	/////

	public function deleteReviews(ReviewableInterface $reviewable, $flush = true) {
		$om = $this->doctrine->getManager();
		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		$reviews = $reviewRepository->findByEntityTypeAndEntityId($reviewable->getType(), $reviewable->getId());
		foreach ($reviews as $review) {
			$this->deleteReview($review, $reviewable, $this->activityUtils, $om, false);
		}
		if ($flush) {
			$om->flush();
		}
	}

	public function deleteReview(Review $review, ReviewableInterface $reviewable, ActivityUtils $activityUtils, $om, $flush = false) {

		// Update user review count
		if (!($reviewable instanceof DraftableInterface) || ($reviewable instanceof DraftableInterface && !$reviewable->getIsDraft())) {
			$review->getUser()->getMeta()->incrementReviewCount(-1);
		}

		// Update reviewable review count
		$reviewable->incrementReviewCount(-1);

		// Delete relative activities
		$activityUtils->deleteActivitiesByReview($review);

		// Remove Review from DB
		$om->remove($review);

		if ($flush) {
			$om->flush();
		}

	}

	/////

	public function computeAverageRating(ReviewableInterface $reviewable) {
		$om = $this->doctrine->getManager();
		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		$ratingSum = 0;
		$reviewCount = 0;

		// Retrieve reviews
		$reviews = $reviewRepository->findByEntityTypeAndEntityId($reviewable->getType(), $reviewable->getId());

		foreach ($reviews as $review) {
			if ($review->getRating() > 0) {
				$ratingSum += $review->getRating();
				$reviewCount++;
			}
		}
		$reviewable->setAverageRating($reviewCount > 0 ? $ratingSum / $reviewCount : 0);
	}

	/////

	public function getReviewContext(ReviewableInterface $reviewable) {
		$om = $this->doctrine->getManager();
		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		// Retrieve reviews
		$reviews = $reviewRepository->findByEntityTypeAndEntityId($reviewable->getType(), $reviewable->getId());

		// Retrieve current user review
		$userReview = null;
		$user = $this->globalUtils->getUser();
		if (!is_null($user)) {

			foreach ($reviews as $review) {
				if ($review->getUser() == $user) {
					$userReview = $review;
					break;
				}
			}

		}

		return array(
			'entityType'   => $reviewable->getType(),
			'entityId'     => $reviewable->getId(),
			'reviewCount'  => $reviewable->getReviewCount(),
			'reviews'      => $reviews,
			'userReview'   => $userReview,
			'isReviewable' => $reviewable instanceof HiddableInterface ? $reviewable->getIsPublic() : true,
		);
	}

}