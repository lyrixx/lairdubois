<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Manager\Webpush\UserSubscriptionManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/webpush")
 */
class WebpushController extends AbstractController {

	/**
	 * The subscription action of bentools/webpush-bundle 0.3: the JS of the site (ladb-webpush-client.js)
	 * sends the PushSubscription as is, the one of the bundle 0.7 reads it under a "subscription" key.
	 *
	 * @Route("/", methods={"POST", "DELETE"}, name="bentools_webpush.subscription")
	 */
	public function subscriptionAction(Request $request, UserSubscriptionManager $userSubscriptionManager) {
		$subscription = json_decode($request->getContent(), true);
		if (!isset($subscription['endpoint'])) {
			throw new BadRequestHttpException('Invalid subscription object.');
		}

		$user = $this->getUser();
		$subscriptionHash = $userSubscriptionManager->hash($subscription['endpoint'], $user);
		$userSubscription = $userSubscriptionManager->getUserSubscription($user, $subscriptionHash);

		if ('DELETE' === $request->getMethod()) {
			if (is_null($userSubscription)) {
				throw new BadRequestHttpException('Subscription hash not found.');
			}
			$userSubscriptionManager->delete($userSubscription);
		} else if (is_null($userSubscription)) {
			$userSubscriptionManager->save($userSubscriptionManager->factory($user, $subscriptionHash, $subscription));
		}

		return new Response('', Response::HTTP_NO_CONTENT);
	}

}
