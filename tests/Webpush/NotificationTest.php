<?php

namespace Tests\Webpush;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Webpush\UserSubscription;
use Ladb\CoreBundle\Message\WebpushNotificationMessage;
use Psr\Http\Message\RequestInterface;
use Tests\WebTestCase;

/**
 * The handler of the webpush_notification messages sends the notification to each subscription of the recipient,
 * as a push service expects it (RFC 8030): signed with the VAPID key of the site (RFC 8292), the
 * payload encrypted for the subscription in "aesgcm" (draft of RFC 8291), as minishlink/web-push 2.0
 * did. The push service is Tests\Double\InMemoryPushHandler.
 */
final class NotificationTest extends WebTestCase
{
    private const ENDPOINT = 'https://push.example.test/send/';

    // Prefix of the DER SubjectPublicKeyInfo of a P-256 public key, before the point
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function testSendsTheNotificationToEachSubscription(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $handler = $container->get('tests.webpush_push_handler');
        $user = static::findUser(UserFixtures::USER);

        // The keys of the browser
        $subscriptionKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        $authSecret = random_bytes(16);
        $activeId = self::subscribe($user, self::ENDPOINT.'active', $subscriptionKey, $authSecret);
        $expiredId = self::subscribe($user, self::ENDPOINT.'expired', $subscriptionKey, $authSecret);
        $unreachableId = self::subscribe($user, self::ENDPOINT.'unreachable', $subscriptionKey, $authSecret);
        $handler->expire(self::ENDPOINT.'expired');
        $handler->makeUnreachable(self::ENDPOINT.'unreachable');

        static::consumeMessage(new WebpushNotificationMessage(
            $user->getId(),
            'Nouveau coup de cœur de Personne',
            'https://lairdubois.test/media/cache/128x128o/avatar.png',
            'https://lairdubois.test/creations/1.html',
        ), 'webpush_notification');

        $requests = [];
        foreach ($handler->getRequests() as $request) {
            $requests[(string) $request->getUri()] = $request;
        }
        self::assertEqualsCanonicalizing([self::ENDPOINT.'active', self::ENDPOINT.'expired', self::ENDPOINT.'unreachable'], array_keys($requests));

        $request = $requests[self::ENDPOINT.'active'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('2419200', $request->getHeaderLine('TTL'));
        self::assertSame('aesgcm', $request->getHeaderLine('Content-Encoding'));
        self::assertSame('application/octet-stream', $request->getHeaderLine('Content-Type'));

        // VAPID: the public key of the site, and a JWT signed with its private key
        $vapidPublicKey = self::base64UrlDecode($container->getParameter('bentools_webpush.public_key'));
        $cryptoKey = self::parseParameters($request->getHeaderLine('Crypto-Key'));
        self::assertSame(self::base64UrlEncode($vapidPublicKey), $cryptoKey['p256ecdsa']);
        self::assertStringStartsWith('WebPush ', $request->getHeaderLine('Authorization'));
        [$jwtHeader, $jwtClaims, $jwtSignature] = explode('.', substr($request->getHeaderLine('Authorization'), \strlen('WebPush ')));
        self::assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(self::base64UrlDecode($jwtHeader), true));
        self::assertSame(1, openssl_verify($jwtHeader.'.'.$jwtClaims, self::toDerSignature(self::base64UrlDecode($jwtSignature)), self::toPem($vapidPublicKey), \OPENSSL_ALGO_SHA256));
        $claims = json_decode(self::base64UrlDecode($jwtClaims), true);
        self::assertSame('https://push.example.test', $claims['aud']);
        self::assertSame($container->getParameter('router.request_context.host'), $claims['sub']);
        self::assertGreaterThan(time(), $claims['exp']);
        self::assertLessThanOrEqual(time() + 86400, $claims['exp']);

        // The payload that ladb-webpush-sw.js reads, padded to 3052 bytes
        $body = (string) $request->getBody();
        self::assertSame(2 + 3052 + 16, \strlen($body));
        $payload = self::decrypt(
            $body,
            self::base64UrlDecode(self::parseParameters($request->getHeaderLine('Encryption'))['salt']),
            self::base64UrlDecode($cryptoKey['dh']),
            $subscriptionKey,
            $authSecret
        );
        self::assertSame('{"notification":{"title":"L\'Air du Bois","body":"Nouveau coup de c\u0153ur de Personne","icon":"https:\/\/lairdubois.test\/media\/cache\/128x128o\/avatar.png","data":{"link":"https:\/\/lairdubois.test\/creations\/1.html"}}}', $payload);

        // Only the expired subscription is deleted
        $entityManager = static::getEntityManager();
        $entityManager->clear();
        $repository = $entityManager->getRepository(UserSubscription::class);
        self::assertNotNull($repository->find($activeId));
        self::assertNull($repository->find($expiredId));
        self::assertNotNull($repository->find($unreachableId));
    }

    /**
     * Saves the subscription as the browser sends it (SubscriptionTest).
     *
     * @param \OpenSSLAsymmetricKey $subscriptionKey
     */
    private static function subscribe(User $user, string $endpoint, $subscriptionKey, string $authSecret): int
    {
        $subscription = new UserSubscription($user, md5($endpoint), [
            'endpoint' => $endpoint,
            'expirationTime' => null,
            'keys' => [
                'p256dh' => self::base64UrlEncode(self::getPoint($subscriptionKey)),
                'auth' => self::base64UrlEncode($authSecret),
            ],
        ]);
        $entityManager = static::getEntityManager();
        $entityManager->persist($subscription);
        $entityManager->flush();

        return $subscription->getId();
    }

    /**
     * The decryption of the browser: "aesgcm" content encoding (draft-ietf-webpush-encryption-04).
     *
     * @param \OpenSSLAsymmetricKey $subscriptionKey
     */
    private static function decrypt(string $body, string $salt, string $serverPublicKey, $subscriptionKey, string $authSecret): string
    {
        $subscriptionPublicKey = self::getPoint($subscriptionKey);
        $sharedSecret = openssl_pkey_derive(self::toPem($serverPublicKey), $subscriptionKey, 32);
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, "Content-Encoding: auth\0", $authSecret);
        $context = "P-256\0".pack('n', \strlen($subscriptionPublicKey)).$subscriptionPublicKey.pack('n', \strlen($serverPublicKey)).$serverPublicKey;
        $contentEncryptionKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aesgcm\0".$context, $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0".$context, $salt);

        $padded = openssl_decrypt(substr($body, 0, -16), 'aes-128-gcm', $contentEncryptionKey, \OPENSSL_RAW_DATA, $nonce, substr($body, -16));
        self::assertIsString($padded, 'The payload cannot be decrypted with the keys of the subscription.');

        return substr($padded, 2 + unpack('n', $padded)[1]);
    }

    /**
     * The uncompressed point of a P-256 public key, as the browser gives it (p256dh) and as VAPID sends it.
     *
     * @param \OpenSSLAsymmetricKey $key
     */
    private static function getPoint($key): string
    {
        $details = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($details['x'], 32, "\0", \STR_PAD_LEFT).str_pad($details['y'], 32, "\0", \STR_PAD_LEFT);
    }

    private static function toPem(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * The JWS signature (r and s, 32 bytes each) as OpenSSL verifies it (DER).
     */
    private static function toDerSignature(string $signature): string
    {
        $integer = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            if ('' === $bytes || \ord($bytes[0]) > 0x7F) {
                $bytes = "\0".$bytes;
            }

            return "\x02".\chr(\strlen($bytes)).$bytes;
        };
        $sequence = $integer(substr($signature, 0, 32)).$integer(substr($signature, 32));

        return "\x30".\chr(\strlen($sequence)).$sequence;
    }

    /**
     * @return array<string, string> "dh=…;p256ecdsa=…" by name
     */
    private static function parseParameters(string $header): array
    {
        $parameters = [];
        foreach (preg_split('/[;,]/', $header) as $parameter) {
            [$name, $value] = explode('=', trim($parameter), 2);
            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /**
     * Also decodes the standard base64 (the VAPID keys of parameters.yml can be either).
     */
    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
