<?php

namespace Tests;

use Symfony\Bundle\FrameworkBundle\Client;

/**
 * Client of the test environment (config_test.yml), whose crawler reads UTF-8.
 *
 * The DomCrawler of Symfony 3.4 converts a page to HTML entities with mb_convert_encoding() and
 * "HTML-ENTITIES", deprecated since PHP 8.2: it skips the conversion, and DOMDocument reads the
 * page as ISO-8859-1 ("chÃªne"). The texts and the values of the forms would be garbled, and
 * submitted as such.
 */
final class Utf8Client extends Client
{
    protected function createCrawlerFromContent($uri, $content, $type)
    {
        return parent::createCrawlerFromContent($uri, mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'), $type);
    }
}
