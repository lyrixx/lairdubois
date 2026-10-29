<?php

namespace Ladb\CoreBundle\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Without "rtu" (return to URL), the actions that read it redirect to the referer. The site only sends
 * its own URLs: any other one (forged URL) is removed. An empty one would redirect to an empty URL
 * (500), another site's one would be an open redirect.
 */
class ReturnToUrlListener implements EventSubscriberInterface {

	public static function getSubscribedEvents(): array {
		return array(
			KernelEvents::REQUEST => 'onKernelRequest',
		);
	}

	/////

	public function onKernelRequest(RequestEvent $event) {
		$request = $event->getRequest();
		foreach (array( $request->query, $request->request ) as $parameters) {
			// all(): get() refuses a non-scalar value (rtu[]=…, forged), a 400 since Symfony 6.0
			if ($parameters->has('rtu') && !$this->isSiteUrl($parameters->all()['rtu'], $request->getHost())) {
				$parameters->remove('rtu');
			}
		}
	}

	/////

	/**
	 * A path of the site, or an absolute http(s) URL on the host of the request. Browsers read a
	 * backslash as a slash and drop tabs and newlines: "/\example.com" and "/<tab>/example.com" are
	 * "//example.com", relative to the protocol.
	 */
	private function isSiteUrl($url, $host) {
		if (!is_string($url) || $url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
			return false;
		}
		if ($url[0] === '/') {
			return strpos($url, '//') !== 0;
		}
		$scheme = parse_url($url, PHP_URL_SCHEME);
		$urlHost = parse_url($url, PHP_URL_HOST);
		return is_string($scheme) && in_array(strtolower($scheme), array( 'http', 'https' ), true)
			&& is_string($urlHost) && strtolower($urlHost) === $host;
	}

}
