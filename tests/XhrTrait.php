<?php

namespace Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The requests the JS of the site sends (votes, values, reviews...), to routes by name. Most of
 * their actions answer 404 to a request that is not XHR.
 */
trait XhrTrait
{
    /**
     * A request with the header of XHR: the client of Symfony 3.4 has no xmlHttpRequest().
     */
    protected static function xhr(KernelBrowser $client, string $method, string $uri, array $parameters = []): Crawler
    {
        return $client->request($method, $uri, $parameters, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /**
     * The path of a route, from the router of the kernel of the last client.
     */
    protected static function generatePath(string $route, array $parameters = []): string
    {
        return static::$kernel->getContainer()->get('router')->generate($route, $parameters);
    }
}
