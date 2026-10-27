<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\Wonder\CreationController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class DefaultController extends AbstractController {

	/**
	 * @Route("/", name="core_welcome")
	 */
	public function welcomeAction() {
		$response = $this->forward(CreationController::class.'::listAction', array(
			'homepage' => true,
		));
		return $response;
	}

	/**
	 * @Route("/connexion", name="core_smartlogin")
	 */
	public function smartLoginAction(Request $request) {
		$request->getSession()->set('_security.main.target_path', $request->headers->get('referer'));
		return $this->redirect($this->generateUrl('fos_user_security_login'));
	}

}
