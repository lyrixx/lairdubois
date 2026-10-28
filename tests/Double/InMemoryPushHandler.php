<?php

namespace Tests\Double;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle handler of the web push notifications in the test environment (config_test.yml): keeps the
 * requests instead of sending them. The push service answers 201 Created, 410 Gone for the endpoints
 * given to expire(), and does not answer for the ones given to makeUnreachable().
 */
final class InMemoryPushHandler
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<array<string, mixed>> The options of each request, in the order of getRequests() */
    private array $requestOptions = [];

    /** @var list<string> */
    private array $expiredEndpoints = [];

    /** @var list<string> */
    private array $unreachableEndpoints = [];

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->requests[] = $request;
        $this->requestOptions[] = $options;
        $endpoint = (string) $request->getUri();

        if (\in_array($endpoint, $this->unreachableEndpoints, true)) {
            return new RejectedPromise(new ConnectException('Could not resolve host.', $request));
        }

        return new FulfilledPromise(new Response(\in_array($endpoint, $this->expiredEndpoints, true) ? 410 : 201));
    }

    public function expire(string $endpoint): void
    {
        $this->expiredEndpoints[] = $endpoint;
    }

    public function makeUnreachable(string $endpoint): void
    {
        $this->unreachableEndpoints[] = $endpoint;
    }

    /**
     * @return list<RequestInterface>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRequestOptions(): array
    {
        return $this->requestOptions;
    }
}
