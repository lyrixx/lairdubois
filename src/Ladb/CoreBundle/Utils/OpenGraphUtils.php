<?php

namespace Ladb\CoreBundle\Utils;

use Psr\Log\LoggerInterface;
class OpenGraphUtils {

	const NAME = 'ladb_core.open_graph_utils';

	private $facebookAccessToken;
	private $facebookAppId;
	private $facebookAppSecret;
	private $logger;
	private $externalUrlUtils;

	public function __construct($facebookAccessToken, $facebookAppId, $facebookAppSecret, LoggerInterface $logger, ExternalUrlUtils $externalUrlUtils) {
		$this->facebookAccessToken = $facebookAccessToken;
		$this->facebookAppId = $facebookAppId;
		$this->facebookAppSecret = $facebookAppSecret;
		$this->logger = $logger;
		$this->externalUrlUtils = $externalUrlUtils;
	}

	/////

	public function scrape($openGraphId) {

		try {

			// Setup Facebook SDK
			$fb = new \Facebook\Facebook([
				'app_id' => $this->facebookAppId,
				'app_secret' => $this->facebookAppSecret,
				'default_graph_version' => 'v5.0',
				'default_access_token' => $this->facebookAccessToken,
			]);

			$request = $fb->request(
				'POST',
				'/',
				array(
					'scrape' => 'true',
					'id' => $openGraphId
				)
			);

			$fb->getClient()->sendRequest($request);

		} catch (\Facebook\Exceptions\FacebookSDKException $e) {
			// When validation fails or other local issues
			$this->logger->error('OpenGraphUtils failed to scrape. Facebook SDK returned an error: '.$e->getMessage());
		}

	}

	public function fetchMetas($uri) {
		$response = $this->externalUrlUtils->fetch($uri, array( 'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ));

		if (!empty($response)) {

			libxml_use_internal_errors(true);
			$doc = new \DOMDocument();
			$doc->loadHTML(mb_convert_encoding($response, 'ISO-8859-1', 'UTF-8'));
			$xpath = new \DOMXPath($doc);
			$query = '//*/meta[starts-with(@property, \'og:\')]';
			$metas = $xpath->query($query);
			$rmetas = array();
			foreach ($metas as $meta) {
				$property = $meta->getAttribute('property');
				$content = $meta->getAttribute('content');
				$rmetas[$property] = $content;
			}
			return $rmetas;

		} else {
			return false;
		}
	}

	public function fetchMetaValue($uri, $property) {
		$metas = $this->fetchMetas($uri);
		if ($metas && isset($metas[$property])) {
			return $metas[$property];
		}
		return false;
	}

}