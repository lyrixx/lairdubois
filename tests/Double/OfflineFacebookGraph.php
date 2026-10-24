<?php

namespace Tests\Double {
    /**
     * The Graph API of Facebook in the tests. The SDK (facebook/graph-sdk) is created inside the
     * code of the site (ApiController, OpenGraphUtils), with no service to replace: its cURL
     * client calls the functions curl_*() from its namespace, which the ones at the end of this
     * file define first, as ClockMock and DnsMock of symfony/phpunit-bridge do.
     * tests/bootstrap.php loads this file before any test.
     *
     * The API knows the share count of one page of the site, URL; it answers an error for
     * ERROR_URL (an expired token...) and for any other request, and the other pages have no
     * Open Graph object.
     */
    final class OfflineFacebookGraph
    {
        public const URL = 'https://www.lairdubois.fr/creations/1.html';
        public const SHARE_COUNT = 42;
        public const ERROR_URL = 'https://www.lairdubois.fr/erreur.html';

        /** @var list<string> */
        private static array $requests = [];

        private static ?string $url = null;

        /**
         * The URLs of the requests since the last call, query string included.
         *
         * @return list<string>
         */
        public static function popRequests(): array
        {
            $requests = self::$requests;
            self::$requests = [];

            return $requests;
        }

        /**
         * @internal For the functions curl_*() below
         */
        public static function open(string $url): void
        {
            self::$url = $url;
            self::$requests[] = $url;
        }

        /**
         * The raw response, headers and body, as cURL gives it with CURLOPT_HEADER.
         *
         * @internal For the functions curl_*() below
         */
        public static function respond(): string
        {
            parse_str((string) parse_url(self::$url, \PHP_URL_QUERY), $query);

            if ('og_object{engagement}' === ($query['fields'] ?? null) && isset($query['id']) && self::ERROR_URL !== $query['id']) {
                $body = self::URL === $query['id']
                    ? ['og_object' => ['engagement' => ['count' => self::SHARE_COUNT, 'social_sentence' => self::SHARE_COUNT.' people like this.'], 'id' => '1000000000000001'], 'id' => $query['id']]
                    : ['id' => $query['id']];

                return "HTTP/1.1 200 OK\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($body);
            }

            return "HTTP/1.1 400 Bad Request\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode(['error' => [
                'message' => 'Unsupported request (Tests\Double\OfflineFacebookGraph).',
                'type' => 'GraphMethodException',
                'code' => 100,
            ]]);
        }
    }
}

// The functions Facebook\HttpClients\FacebookCurl calls: PHP looks for a function of the
// namespace before the global one
namespace Facebook\HttpClients {
    use Tests\Double\OfflineFacebookGraph;

    function curl_init()
    {
        return 'offline';
    }

    function curl_setopt($ch, $option, $value)
    {
        if (\CURLOPT_URL === $option) {
            OfflineFacebookGraph::open($value);
        }

        return true;
    }

    function curl_setopt_array($ch, array $options)
    {
        foreach ($options as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        return true;
    }

    function curl_exec($ch)
    {
        return OfflineFacebookGraph::respond();
    }

    function curl_errno($ch)
    {
        return 0;
    }

    function curl_error($ch)
    {
        return '';
    }

    function curl_close($ch)
    {
    }
}
