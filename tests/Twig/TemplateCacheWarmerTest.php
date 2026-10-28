<?php

namespace Tests\Twig;

use Tests\WebTestCase;
use Twig\Error\Error;

/**
 * cache:warmup, and the cache:clear that runs it, compiles every template in a single process
 * (TemplateCacheWarmer of TwigBundle), and ignores the ones that do not compile.
 */
final class TemplateCacheWarmerTest extends WebTestCase
{
    // Templates of vendors that need a Twig extension the site does not install: the warmer skips
    // them, no page renders them
    private const UNCOMPILABLE = [
        '@email/zurb_2/notification/body.html.twig', // inky_to_html, twig/inky-extra
        '@email/zurb_2/notification/content_markdown.html.twig', // markdown_to_html, twig/markdown-extra
    ];

    /**
     * A template that throws while TranslationDefaultDomainNodeVisitor visits it leaves its
     * {% trans_default_domain %} to the next ones of the process: after the warmup, the pages
     * would show their translation keys (#138).
     *
     * In a process of its own, as cache:warmup: the classes of the templates loaded by the other
     * tests would be used instead of being compiled.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPagesShowTheirTranslationsAfterTheWarmup(): void
    {
        // PHPUnit reads the errors of this process once it has ended: the warnings of the routes,
        // when it builds their cache (PCRE2, see CLAUDE.md), would fill the pipe and block both
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');

        // The kernel of cache:warmup, whose templates stay in memory: the cache of the other tests
        // stays as it is
        static::bootKernel();
        $twig = static::getContainer()->get('twig');
        $twig->setCache(false);
        // The loop of TemplateCacheWarmer::warmUp(), which does not tell its errors
        $errors = [];
        foreach (static::getContainer()->get('tests.twig_template_iterator') as $template) {
            try {
                $twig->load($template);
            } catch (Error $e) {
                $errors[$template] = $e->getMessage();
            }
        }

        // The kernel of a request: it renders the templates of the warmup, and compiles the others
        $client = static::createClient();
        $client->request('GET', '/creations/1.html');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $follow = $client->getCrawler()->filter('.ladb-follower-widget')->text();
        self::assertStringNotContainsString('follower.follow', $follow);
        self::assertStringContainsString("S'abonner", $follow);
        self::assertSame([], array_diff_key($errors, array_flip(self::UNCOMPILABLE)));
    }
}
