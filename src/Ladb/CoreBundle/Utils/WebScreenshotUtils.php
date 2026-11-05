<?php

namespace Ladb\CoreBundle\Utils;

use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ManagerRegistry;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Exception\CommunicationException;
use HeadlessChromium\Exception\FilesystemException;
use HeadlessChromium\Exception\NavigationExpired;
use HeadlessChromium\Exception\NoResponseAvailable;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Exception\ScreenshotFailed;
use HeadlessChromium\Page;
use Ladb\CoreBundle\Entity\Core\Picture;
use Ladb\CoreBundle\Manager\Core\PictureManager;
use Psr\Log\LoggerInterface;

class WebScreenshotUtils {

	const NAME = 'ladb_core.web_screenshot_utils';

	private $chromium;
	protected $doctrine;
	private $logger;
	protected $pictureManager;
	private $videoHostingUtils;

	public function __construct($chromium, ManagerRegistry $doctrine, LoggerInterface $logger, PictureManager $pictureManager, VideoHostingUtils $videoHostingUtils) {
		$this->chromium = $chromium;
		$this->doctrine = $doctrine;
		$this->logger = $logger;
		$this->pictureManager = $pictureManager;
		$this->videoHostingUtils = $videoHostingUtils;
	}

	/////

	/**
	 * The page of a video, at a URL written by a member: Chromium only opens the pages of the hosts
	 * that the site knows.
	 */
	public function captureVideoToPicture($url, $width, $height) {

		if (!$this->videoHostingUtils->isHostedVideoUrl($url)) {
			$this->logger->warning('Screenshot refused: not the URL of a video on its host.', array( 'url' => $url ));
			return null;
		}

		return $this->captureToPicture($url, $width, $height);
	}

	/**
	 * A page of the site, or the page of a video that captureVideoToPicture() has checked: Chromium
	 * gets $url as it is, never give it a URL written by a member.
	 */
	public function captureToPicture($url, $width, $height, $clipWidth = 0, $clipHeight = 0, $waitBeforeCapture = 0 /* in seconds */) {

		// Create picture
		$picture = $this->pictureManager->createEmpty(false);
		$pictureFile = $picture->getAbsoluteMasterPath();

		// HeadlessChromium Capture /////

		$browserFactory = new \HeadlessChromium\BrowserFactory($this->chromium);

		// starts headless chrome
		$browser = $browserFactory->createBrowser(array(
			'windowSize' => array( $width, $height ),
			'connectionDelay' => 2.0,
			'sendSyncDefaultTimeout' => 5000,
			'customFlags' => array(
				'--no-sandbox',
			),
		));

		try {

			// creates a new page and navigate to an url
			$page = $browser->createPage();
			// The headless mode of recent Chromiums counts the browser UI in the window size
			$page->setViewport($width, $height)->await();

			$pageRefused = false;
			$this->refuseInternalDocuments($page, parse_url($url, PHP_URL_HOST), $pageRefused);

			$page->navigate($url)->waitForNavigation(Page::DOM_CONTENT_LOADED);

			if (!$pageRefused) {

				if ($waitBeforeCapture > 0) {
					sleep($waitBeforeCapture);
				}

				// screenshot - Say "Cheese"! 😄
				$page->screenshot()->saveToFile($pictureFile);

			}

		} catch (CommunicationException $e) {
			$this->logger->error('HeadlessChromium CommunicationException : '.$e->getMessage());
		} catch (NoResponseAvailable $e) {
			$this->logger->error('HeadlessChromium NoResponseAvailable : '.$e->getMessage());
		} catch (OperationTimedOut $e) {
			$this->logger->error('HeadlessChromium OperationTimedOut : '.$e->getMessage());
		} catch (NavigationExpired $e) {
			$this->logger->error('HeadlessChromium NavigationExpired : '.$e->getMessage());
		} catch (FilesystemException $e) {
			$this->logger->error('HeadlessChromium FilesystemException : '.$e->getMessage());
		} catch (ScreenshotFailed $e) {
			$this->logger->error('HeadlessChromium ScreenshotFailed : '.$e->getMessage());
		}

		// bye
		$browser->close();

		/////

		if (is_file($pictureFile)) {

			$this->pictureManager->computeSizes($picture);

			$om = $this->doctrine->getManager();
			$om->persist($picture);
			$om->flush();

			return $picture;
		}

		return null;
	}

	/**
	 * Out of the host of the capture (the site may be on a private address), a redirection or a
	 * frame only loads a document from a public address. $pageRefused tells that it was the page.
	 */
	private function refuseInternalDocuments(Page $page, $host, &$pageRefused) {
		$session = $page->getSession();
		$mainFrameId = $page->getFrameManager()->getMainFrame()->getFrameId();

		$session->on('method:Fetch.requestPaused', function (array $paused) use ($session, $host, $mainFrameId, &$pageRefused) {
			$url = $paused['request']['url'];
			$documentHost = parse_url($url, PHP_URL_HOST);

			if ($documentHost === $host || $this->isPublicHost($documentHost)) {
				$session->sendMessage(new Message('Fetch.continueRequest', array( 'requestId' => $paused['requestId'] )));
				return;
			}

			$this->logger->warning('Screenshot refused: a document on an internal address.', array( 'url' => $url ));
			$session->sendMessage(new Message('Fetch.failRequest', array( 'requestId' => $paused['requestId'], 'errorReason' => 'BlockedByClient' )));
			$pageRefused = $pageRefused || $paused['frameId'] === $mainFrameId;
		});

		$session->sendMessageSync(new Message('Fetch.enable', array(
			'patterns' => array( array( 'urlPattern' => '*', 'resourceType' => 'Document' ) ),
		)));
	}

	private function isPublicHost($host) {
		$host = trim($host, '[]');

		$addresses = false === filter_var($host, FILTER_VALIDATE_IP) ? gethostbynamel($host) : array( $host );
		if (false === $addresses) {
			return false;
		}

		foreach ($addresses as $address) {
			if (false === filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
				return false;
			}
		}

		return true;
	}

}