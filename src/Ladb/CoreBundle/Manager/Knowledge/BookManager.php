<?php

namespace Ladb\CoreBundle\Manager\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Utils\ReviewableUtils;

class BookManager extends AbstractKnowledgeManager {

	const NAME = 'ladb_core.knowledge_book_manager';

	private $reviewableUtils;

	public function __construct(ReviewableUtils $reviewableUtils) {
		$this->reviewableUtils = $reviewableUtils;
	}

	public function delete(Book $book, $withWitness = true, $flush = true) {

		// Delete reviews
		$this->reviewableUtils->deleteReviews($book, false);

		parent::deleteKnowledge($book, $withWitness, $flush);
	}

}