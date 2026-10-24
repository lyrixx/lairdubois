<?php

namespace Tests\Double;

/**
 * Stream wrapper of the http and https protocols, for the code which reads a URL with
 * file_get_contents() instead of a service (ladb:cron:opencutlist:access:analyze and ip-api.com):
 * between register() and unregister(), it answers the URLs it is given, and fails to open any
 * other (a warning, which fails the test).
 */
final class OfflineHttpStream
{
    private const PROTOCOLS = ['http', 'https'];

    /** @var array<string, string> */
    private static array $bodies = [];

    /** @var list<string> */
    private static array $urls = [];

    /** @var resource|null Set by PHP */
    public $context;

    private string $body = '';

    private int $position = 0;

    /**
     * @param array<string, string> $bodies The body of each URL
     */
    public static function register(array $bodies): void
    {
        self::$bodies = $bodies;
        self::$urls = [];
        foreach (self::PROTOCOLS as $protocol) {
            stream_wrapper_unregister($protocol);
            stream_wrapper_register($protocol, self::class);
        }
    }

    public static function unregister(): void
    {
        foreach (self::PROTOCOLS as $protocol) {
            stream_wrapper_restore($protocol);
        }
    }

    /**
     * The URLs opened since register().
     *
     * @return list<string>
     */
    public static function getUrls(): array
    {
        return self::$urls;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$urls[] = $path;
        if (!isset(self::$bodies[$path])) {
            return false;
        }
        $this->body = self::$bodies[$path];

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->body, $this->position, $count);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= \strlen($this->body);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => \strlen($this->body)];
    }
}
