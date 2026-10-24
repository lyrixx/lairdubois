<?php

namespace Tests\Double;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * The HTTP client of Stripe in the test environment (config_test.yml): FundingController calls
 * the static API of Stripe, this listener gives it this client at each request. It knows one
 * payment intent, the one it creates; any other request answers as for an unknown resource.
 */
final class OfflineStripeClient implements ClientInterface
{
    public const PAYMENT_INTENT_ID = 'pi_offline';
    // Cents, as the API
    public const PAYMENT_INTENT_AMOUNT = 1500;

    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    private array $requests = [];

    public function onKernelRequest(): void
    {
        ApiRequestor::setHttpClient($this);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        $path = parse_url($absUrl, \PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

        if ('post' === $method && '/v1/payment_intents' === $path) {
            return $this->respond(200, $this->getPaymentIntent((int) $params['amount']));
        }
        if ('get' === $method && '/v1/payment_intents/'.self::PAYMENT_INTENT_ID === $path) {
            return $this->respond(200, $this->getPaymentIntent(self::PAYMENT_INTENT_AMOUNT));
        }

        return $this->respond(404, ['error' => [
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
            'message' => \sprintf('No such resource: %s.', $path),
        ]]);
    }

    /**
     * The requests to the API, until the next reboot of the kernel.
     *
     * @return list<array{method: string, path: string, params: array<string, mixed>}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    private function getPaymentIntent(int $amount): array
    {
        return [
            'id' => self::PAYMENT_INTENT_ID,
            'object' => 'payment_intent',
            'amount' => $amount,
            'application_fee_amount' => null,
            'client_secret' => self::PAYMENT_INTENT_ID.'_secret_offline',
            'currency' => 'eur',
            'status' => 'requires_payment_method',
        ];
    }

    private function respond(int $status, array $body): array
    {
        return [json_encode($body), $status, new CaseInsensitiveArray(['request-id' => 'req_offline'])];
    }
}
