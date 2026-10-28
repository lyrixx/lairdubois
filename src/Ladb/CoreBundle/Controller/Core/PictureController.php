<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Entity\Core\Member;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Imagine\Gd\Imagine;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Entity\Core\Picture;
use Ladb\CoreBundle\Form\Model\EditPicture;
use Ladb\CoreBundle\Handler\PictureUploadHandler;
use Ladb\CoreBundle\Form\Type\Core\EditPictureType;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/pictures')]
class PictureController extends AbstractController {

	use PublicationControllerTrait;

	private function assertEditableGranted(Picture $picture, EntityManagerInterface $om, $context = '') {

		if ($this->isGranted('ROLE_ADMIN')) {
			$allowed = true;
		} else if ($picture->getUser()->getIsTeam() && !is_null($this->getUser())) {

			// Only members allowed
			$memberRepository = $om->getRepository(Member::class);
			$allowed = $memberRepository->existsByTeamAndUser($picture->getUser(), $this->getUser());

		} else {
			$allowed = $picture->getUser() == $this->getUser();
		}

		if (!$allowed) {
			throw $this->createNotFoundException('Not allowed ('.$context.')');
		}

		return true;
	}

	/////
	#[Route(path: '/{quality}/{postProcessor}/upload', requirements: ['quality' => 'ld|sd|hd', 'postProcessor' => 'none|square'], name: 'core_picture_upload')]
	public function uploadAction(Request $request, $quality, $postProcessor, PictureUploadHandler $uploadHandler) {

		$owner = $this->retrieveOwner($request);

		$uploadHandler->handle($quality, $postProcessor, $owner);
		exit(0);
	}

	/**
	 * @Template("@LadbCore/Core/Picture/edit-xhr.html.twig")
	 */
	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'], name: 'core_picture_edit')]
	public function editAction(Request $request, $id, EntityManagerInterface $om) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_picture_edit)');
		}

		$pictureRepository = $om->getRepository(Picture::CLASS_NAME);

		$picture = $pictureRepository->findOneById($id);
		if (is_null($picture)) {
			throw $this->createNotFoundException('Unable to find Picture entity (id='.$id.').');
		}
		$this->assertEditableGranted($picture, $om, 'core_picture_edit');

		$editPicture = new EditPicture();
		$editPicture->setLegend($picture->getLegend());
		$editPicture->setSourceUrl($picture->getSourceUrl());
		$editPicture->setRotation($picture->getRotation());
		$editPicture->setCenterX100($picture->getCenterX100());
		$editPicture->setCenterY100($picture->getCenterY100());

		$form = $this->createForm(EditPictureType::class, $editPicture);

		return array(
			'picture'     => $picture,
			'form'        => $form->createView(),
			'formSection' => $request->get('formSection', 'pictures'),
			'sortable'    => $request->get('sortable', false),
		);
	}

	/**
	 * @Template("@LadbCore/Core/Picture/update-error-xhr.html.twig")
	 */
	#[Route(path: '/{id}/update', requirements: ['id' => '\d+'], methods: ['POST'], name: 'core_picture_update')]
	public function updateAction(Request $request, $id, EntityManagerInterface $om) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_picture_update)');
		}

		$pictureRepository = $om->getRepository(Picture::CLASS_NAME);

		$picture = $pictureRepository->findOneById($id);
		if (is_null($picture)) {
			throw $this->createNotFoundException('Unable to find Picture entity (id='.$id.').');
		}
		$this->assertEditableGranted($picture, $om, 'core_picture_edit');

		$editPicture = new EditPicture();

		$form = $this->createForm(EditPictureType::class, $editPicture);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			// Legend & SourceUrl
			$picture->setLegend($editPicture->getLegend());
			$picture->setSourceUrl($editPicture->getSourceUrl());

			// Rotation
			$rotation = $editPicture->getRotation();
			if ($rotation != $picture->getRotation()) {

				if ($rotation == 0) {

					// Remove previously transformed image
					if (!$picture->isMaster()) {
						if ($filename = $picture->getAbsoluteTransformedPath()) {
							unlink($filename);
						}
					}

					list($width, $height) = getimagesize($picture->getAbsoluteMasterPath());

					$picture->setTransformedPath(null);
					$picture->setRotation($rotation);

				} else if ($rotation == 90 || $rotation == 180 || $rotation == 270) {

					// Instantiate Imagine
					$imagine = new Imagine();

					// Convert picture to image
					$image = $imagine->open($picture->getAbsoluteMasterPath());

					// Transform
					$image = $image->rotate($rotation);

					// Remove previously transformed image
					if (!$picture->isMaster()) {
						if ($filename = $picture->getAbsoluteTransformedPath()) {
							unlink($filename);
						}
					}

					$picture->setTransformedPath(sha1(uniqid(mt_rand(), true)).'.jpg');
					$picture->setRotation($rotation);

					// Save rotated image
					$image->save($picture->getAbsoluteTransformedPath(), array( 'format' => 'jpg' ));

				}

				list($width, $height) = getimagesize($picture->getAbsolutePath());

				$picture->setWidth($width);
				$picture->setHeight($height);
				$picture->setHeightRatio100($width > 0 ? $height / $width * 100 : 100);

			}

			// Center
			$picture->setCenterX100($editPicture->getCenterX100());
			$picture->setCenterY100($editPicture->getCenterY100());

			$om->flush();

			return $this->render('@LadbCore/Core/Picture/update-success-xhr.html.twig', array(
				'picture'     => $picture,
				'formSection' => $request->get('formSection', 'pictures'),
				'sortable'    => $request->get('sortable', false),
			));
		}

		return array(
			'picture'     => $picture,
			'form'        => $form->createView(),
			'formSection' => $request->get('formSection', 'pictures'),
			'sortable'    => $request->get('sortable', false),
		);
	}

}
