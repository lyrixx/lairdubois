<?php

namespace Ladb\CoreBundle\Utils;

use Ladb\CoreBundle\Entity\Core\Picture;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a URL which comes from outside the site: the link of a find, the picture of its Open Graph metas, the
 * HTTP referer of a content. By ladb_core.external_url_http_client (services.yml): http and https only, and no
 * private, loopback or link-local address, after a redirection too.
 */
final readonly class ExternalUrlUtils {

	// The largest picture that a member can upload
	private const MAX_RESPONSE_SIZE_IN_BYTES = Picture::MAX_FILE_SIZE;
	private const MAX_DURATION_IN_SECONDS = 15;
	private const MAX_REDIRECTS = 5;
	private const TIMEOUT_IN_SECONDS = 5;

	public function __construct(
		#[Autowire(service: 'ladb_core.external_url_http_client')]
		private HttpClientInterface $httpClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The body of the response, or null when the request fails: refused URL, network error, error status, timeout,
	 * response too large.
	 *
	 * @param array<string, string> $headers
	 */
	public function fetch(string $url, array $headers = array()): ?string {
		try {
			return $this->httpClient->request('GET', $url, array(
				'headers'       => $headers,
				'max_duration'  => self::MAX_DURATION_IN_SECONDS,
				'max_redirects' => self::MAX_REDIRECTS,
				'on_progress'   => static function (int $dlNow, int $dlSize): void {
					if (max($dlNow, $dlSize) > self::MAX_RESPONSE_SIZE_IN_BYTES) {
						throw new TransportException(sprintf('The response is larger than %d bytes.', self::MAX_RESPONSE_SIZE_IN_BYTES));
					}
				},
				'timeout'       => self::TIMEOUT_IN_SECONDS,
			))->getContent();
		} catch (ExceptionInterface $e) {
			$this->logger->warning('The request to an external URL failed.', array( 'url' => $url, 'exception' => $e ));
			return null;
		}
	}

}
