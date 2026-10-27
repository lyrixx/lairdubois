<?php

namespace Ladb\CoreBundle\Controller;

use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Entity\Core\UserWitness;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as BaseAbstractController;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractController extends BaseAbstractController {

	const LOCK_TTL_CREATE_ACTION = 3;		// 3 seconds

	/**
	 * The page to return to after an action: the referer, or the home page if the browser did not send it (like after the logout).
	 */
	protected function getRefererUrl(Request $request) {
		$referer = $request->headers->get('referer');
		if (is_null($referer) || $referer === '') {
			return $this->generateUrl('core_welcome');
		}
		return $referer;
	}

	protected function createLock($name, $blocking = false, $ttl = 300.0, $autoRelease = true) {

		// Lock / Check resource
		if ($blocking) {
			$store = new \Symfony\Component\Lock\Store\SemaphoreStore();
		} else {
			$memcached = new \Memcached();
			$memcached->addServer($this->getParameter('memcached_host'), $this->getParameter('memcached_port'));
			$store = new \Symfony\Component\Lock\Store\MemcachedStore($memcached);
		}
		$factory = new \Symfony\Component\Lock\LockFactory($store);
		$resource = $name.'_'.($this->getUser() ? $this->getUser()->getId() : '');
		$lock = $factory->createLock($resource, $ttl, $autoRelease);
		if (!$lock->acquire($blocking)) {
			throw $this->createNotFoundException('Resource locked ('.$resource.').');
		}

		return $lock;
	}

}
