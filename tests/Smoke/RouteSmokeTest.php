<?php

namespace Tests\Smoke;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Utils\CryptoUtils;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;
use Tests\WebTestCase;

/**
 * Calls every GET route, anonymous, as "user" and as "admin": none may answer 5xx, nor throw a
 * PHP \Error (which escapes the HttpKernel of Symfony 3.4 instead of giving a 500).
 *
 * The parameters of the routes come from the fixtures. A request that changes the database (like,
 * delete, mark as read...) is rolled back with its test.
 */
final class RouteSmokeTest extends WebTestCase
{
    private const ROLES = [
        'anonymous' => null,
        'user' => UserFixtures::USER,
        'admin' => UserFixtures::ADMIN,
    ];

    /**
     * Routes known to answer 5xx, by role, with their issue. A known failure that passes fails
     * the test: remove it from here once its issue is fixed.
     *
     * @var array<string, array<string, int>>
     */
    private const KNOWN_FAILURES = [
        'core_blog_post_unpublish' => ['admin' => 42],
        'core_collection_add' => ['anonymous' => 49],
        'core_collection_list_available' => ['anonymous' => 49],
        'core_collection_list_available_page' => ['anonymous' => 49],
        'core_collection_unpublish' => ['admin' => 42],
        'core_creation_unpublish' => ['admin' => 42],
        'core_event_unpublish' => ['admin' => 42],
        'core_faq_question_unpublish' => ['admin' => 42],
        'core_find_unpublish' => ['admin' => 42],
        'core_follower_create' => ['user' => 42, 'admin' => 42],
        'core_funding_donation_webhook' => ['anonymous' => 34, 'user' => 34, 'admin' => 34],
        'core_howto_article_unpublish' => ['admin' => 42],
        'core_howto_unpublish' => ['admin' => 42],
        'core_join_create' => ['user' => 42, 'admin' => 42],
        'core_knowledge_value_admin_move' => ['admin' => 51],
        'core_knowledge_value_delete' => ['admin' => 51],
        'core_like_create' => ['user' => 42, 'admin' => 42],
        'core_member_list_session_teams' => ['anonymous' => 33, 'user' => 43, 'admin' => 43],
        'core_offer_unpublish' => ['admin' => 42],
        'core_plan_unpublish' => ['admin' => 42],
        'core_promotion_graphic_unpublish' => ['admin' => 42],
        'core_qa_question_unpublish' => ['admin' => 42],
        'core_review_edit' => ['admin' => 50],
        'core_school_list_geojson' => ['anonymous' => 47, 'user' => 47],
        'core_tag_usage_highlight_create' => ['user' => 44, 'admin' => 44],
        'core_tag_usage_highlight_delete' => ['user' => 44, 'admin' => 44],
        'core_user_admin_delete' => ['admin' => 48],
        'core_user_list_geojson' => ['anonymous' => 47, 'user' => 47, 'admin' => 47],
        'core_user_team_list_geojson' => ['anonymous' => 47, 'user' => 47, 'admin' => 47],
        'core_watch_create' => ['user' => 42, 'admin' => 42],
        'core_watch_delete' => ['user' => 42],
        'core_workflow_chown' => ['user' => 45, 'admin' => 45],
        'core_workflow_restart' => ['anonymous' => 46],
        'core_workflow_unpublish' => ['admin' => 42],
        'core_workshop_unpublish' => ['admin' => 42],
        'fos_user_profile_show' => ['anonymous' => 32],
    ];

    /**
     * Routes not called, with the reason.
     *
     * @var array<string, string>
     */
    private const UNTESTED = [
        '_profiler' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_exception' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_exception_css' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_home' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_open_file' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_phpinfo' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_router' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_search' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_search_bar' => 'Web profiler (dev tool), not a page of the site.',
        '_profiler_search_results' => 'Web profiler (dev tool), not a page of the site.',
        '_twig_error_test' => 'Preview of the error pages (dev tool), not a page of the site.',
        '_wdt' => 'Web debug toolbar (dev tool), not a page of the site.',
        'core_picture_upload' => 'The action ends with exit(0), which would stop PHPUnit.',
        'core_resource_upload' => 'The action ends with exit(0), which would stop PHPUnit.',
    ];

    /**
     * Values of the required parameters, by name. ids are those of the contents of the
     * fixtures: each type has a published content of id 1.
     *
     * @var array<string, string|int>
     */
    private const PARAMETERS = [
        'encryptedEmail' => 'invalid',
        'entityId' => 1,
        'entityType' => Creation::TYPE,
        'field' => Wood::FIELD_NAME,
        'filter' => 'recent',
        'followingUserId' => 3, // user2
        'hash' => 'invalid',
        'id' => 1,
        'list' => 'notifications',
        'network' => 'facebook', // Without the "url" query parameter: 404 before any call to Facebook
        'postProcessor' => 'none',
        'quality' => 'sd',
        'questionId' => 1,
        'recipientUsername' => UserFixtures::USER2,
        'teamId' => 4, // team
        'threadId' => 1,
        'token' => 'invalid',
        'username' => UserFixtures::USER,
        'way' => 'up',
    ];

    // The exception of the last request, which the kernel turned into a response
    private ?\Throwable $exception = null;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRoutes(): iterable
    {
        foreach (self::getRoutes() as $name => $route) {
            if (isset(self::UNTESTED[$name])) {
                continue;
            }
            // The CSS and JS of the dev controller of Assetic are the same for everyone, and slow
            // to build (LESS): anonymous only
            if (str_starts_with($name, '_assetic_')) {
                yield "{$name} (anonymous)" => [$name, 'anonymous'];

                continue;
            }
            foreach (array_keys(self::ROLES) as $role) {
                yield "{$name} ({$role})" => [$name, $role];
            }
        }
    }

    /**
     * @dataProvider provideRoutes
     */
    public function testRouteDoesNotFail(string $name, string $role): void
    {
        $client = $this->createClientFor($role);
        $url = $client->getContainer()->get('router')->generate($name, $this->getParameters($client, $name));

        $failure = $this->request($client, $url);
        // The actions which only answer XHR (cards, counters...) are requested again as such
        if (null !== $this->exception && str_starts_with($this->exception->getMessage(), 'Only XML request allowed')) {
            $failure = $this->request($this->createClientFor($role), $url, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        }

        $issue = self::KNOWN_FAILURES[$name][$role] ?? null;
        if (null !== $issue) {
            if (null === $failure) {
                self::fail("GET {$url} ({$role}) passes now: remove it from the known failures (#{$issue}).");
            }
            self::markTestIncomplete("Known failure, see #{$issue}. {$failure}");
        }
        if (null !== $failure) {
            self::fail($failure);
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUntestedRoutes(): iterable
    {
        foreach (self::UNTESTED as $name => $reason) {
            yield $name => [$name, $reason];
        }
    }

    /**
     * Lists the untested routes in the results.
     *
     * @dataProvider provideUntestedRoutes
     */
    public function testUntestedRoute(string $name, string $reason): void
    {
        self::markTestSkipped($reason);
    }

    public function testListedRoutesExist(): void
    {
        foreach ([...array_keys(self::KNOWN_FAILURES), ...array_keys(self::UNTESTED)] as $name) {
            self::assertArrayHasKey($name, self::getRoutes(), "The route {$name} does not exist anymore: remove it from the lists of the smoke test.");
        }
    }

    private function createClientFor(string $role): Client
    {
        return null === self::ROLES[$role] ? static::createClient() : static::createClientAs(self::ROLES[$role]);
    }

    /**
     * Requests the URL, and describes the failure: a 5xx response or an uncaught \Throwable. The
     * exception the kernel turned into a response, if any, is kept in $this->exception.
     *
     * @param array<string, string> $server
     */
    private function request(Client $client, string $url, array $server = []): ?string
    {
        $this->exception = null;
        $client->getContainer()->get('event_dispatcher')->addListener(KernelEvents::EXCEPTION, function (GetResponseForExceptionEvent $event): void {
            $this->exception ??= $event->getException();
        }, 2048);

        // A \Throwable thrown while a response streams its content leaves the output buffer of
        // the client open
        $obLevel = ob_get_level();
        try {
            $client->request('GET', $url, [], [], $server);
        } catch (\Throwable $e) {
            return "GET {$url} threw an uncaught ".self::describe($e);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }

        $status = $client->getResponse()->getStatusCode();
        if ($status < 500) {
            return null;
        }

        return "GET {$url} answered {$status}".(null === $this->exception ? '.' : ': '.self::describe($this->exception));
    }

    /**
     * @return array<string, string|int>
     */
    private function getParameters(Client $client, string $name): array
    {
        // The collection of the router of a new kernel would load the annotations of all the controllers again
        $route = self::getRoutes()[$name];
        $variables = $route->compile()->getVariables();
        // Any variable, optional ones included: the others would end up in the query string
        $parameters = array_intersect_key($this->getRouteParameters($client, $name), array_flip($variables));

        foreach (array_diff($variables, array_keys($route->getDefaults()), array_keys($parameters)) as $variable) {
            if (!isset(self::PARAMETERS[$variable])) {
                self::fail("No value for the parameter {$variable} of the route {$name}: add one, or list the route as untested.");
            }
            $parameters[$variable] = self::PARAMETERS[$variable];
        }

        return $parameters;
    }

    /**
     * Values for a given route, when the default ones do not fit.
     *
     * @return array<string, string|int>
     */
    private function getRouteParameters(Client $client, string $name): array
    {
        $em = static::getEntityManager();

        switch ($name) {
            // Current month: year, month and panel default to null, which does not match their requirements
            case 'core_funding_admin_charge_new':
            case 'core_funding_dashboard_year_month':
                return ['year' => (int) date('Y'), 'month' => (int) date('n')];
            case 'core_funding_infos':
                return ['year' => (int) date('Y'), 'month' => (int) date('n'), 'panel' => 'charge-balance'];

            // Interactions, on the entity types which have them
            case 'core_join_create':
            case 'core_join_list_byentity':
            case 'core_join_list_byentity_page':
                return ['entityType' => Event::TYPE];
            case 'core_review_new':
                return ['entityType' => Book::TYPE];
            case 'core_vote_new':
            case 'core_vote_create':
                return ['entityType' => Answer::TYPE];
            case 'core_vote_list_parent_entity':
            case 'core_vote_list_parent_entity_filter':
            case 'core_vote_list_parent_entity_filter_page':
                return ['entityType' => Question::TYPE];

            // Values of the knowledge
            case 'core_knowledge_contributors':
            case 'core_knowledge_contributors_page':
            case 'core_knowledge_field_show':
                return ['entityType' => Wood::TYPE];
            case 'core_knowledge_value_edit':
            case 'core_knowledge_value_update':
            case 'core_knowledge_value_delete':
            case 'core_knowledge_value_admin_move':
                return ['entityType' => Wood::TYPE, 'fieldSrc' => Wood::FIELD_NAME, 'fieldDest' => Wood::FIELD_SCIENTIFICNAME, 'id' => $em->find(Wood::class, 1)->getNameValues()->first()->getId()];
            case 'core_knowledge_value_download':
            case 'core_knowledge_value_view':
                return ['entityType' => Wood::TYPE, 'field' => Wood::FIELD_GRAIN, 'id' => $em->find(Wood::class, 1)->getGrainValues()->first()->getId()];

            // Liip Imagine, on the main picture of the creation
            case 'liip_imagine_filter':
            case 'liip_imagine_filter_runtime':
                return ['filter' => '128x128o', 'path' => $em->find(Creation::class, 1)->getMainPicture()->getMasterPath()];

            case 'core_user_email_unsubscribe':
                $user = $em->getRepository(User::class)->findOneBy(['usernameCanonical' => UserFixtures::USER]);

                return ['encryptedEmail' => $client->getContainer()->get(CryptoUtils::NAME)->encryptString($user->getEmailCanonical())];
        }

        return [];
    }

    /**
     * @return array<string, Route>
     */
    private static function getRoutes(): array
    {
        static $routes = null;

        if (null === $routes) {
            $kernel = static::createKernel();
            $kernel->boot();
            $routes = [];
            foreach ($kernel->getContainer()->get('router')->getRouteCollection() as $name => $route) {
                if (!$route->getMethods() || \in_array('GET', $route->getMethods(), true)) {
                    $routes[$name] = $route;
                }
            }
            $kernel->shutdown();
        }

        return $routes;
    }

    /**
     * The exception, where it is thrown and the last frame of the application before it.
     */
    private static function describe(\Throwable $e): string
    {
        $description = \sprintf('%s: %s (%s:%d', \get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && str_starts_with($frame['file'], \dirname(__DIR__, 2).'/src/') && $frame['file'] !== $e->getFile()) {
                $description .= \sprintf(', from %s:%d', $frame['file'], $frame['line']);

                break;
            }
        }

        return $description.')';
    }
}
