<?php

namespace Ladb\CoreBundle\Controller\Core;

use Symfony\Component\Routing\Annotation\Route;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Handler\ResourceUploadHandler;

/**
 * @Route("/resources")
 */
class ResourceController extends AbstractController {

	/**
	 * @Route("/upload", name="core_resource_upload")
	 */
	public function uploadAction(ResourceUploadHandler $uploadHandler) {
		$uploadHandler->handle();
		exit(0);
	}

}
