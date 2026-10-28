<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\Report;
use Ladb\CoreBundle\Model\ReportableInterface;

class MaybeUtils {

	const NAME = 'ladb_core.maybe_utils';

	private $globalUtils;

	public function __construct(GlobalUtils $globalUtils) {
		$this->globalUtils = $globalUtils;
	}

	/////

	public function canDoIt($start = 0, $mod = 10, $key = 'global') {

		// Retrieve index from session
		$session = $this->globalUtils->getSession();
		$key = '_ladb_maybe_'.$key.'_index';
		$index = $session->get($key, -1);

		// Increment
		$index = $index + 1;

		// Save new index in session
		$session->set($key, $index);

		return (($index - $start) % $mod) == 0;
	}

}