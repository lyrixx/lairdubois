<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The lists in which the form of a content picks the contents to link (the iframe of
 * _form-gallery-script.part.html.twig): "layout=choice", and the ids already linked to leave out
 * ("ex"), in the query. On the content of id 1 of each type.
 */
final class ChoiceListTest extends WebTestCase
{
    use XhrTrait;

    /**
     * @dataProvider provideLists
     */
    public function testChoiceListLeavesOutTheLinkedContents(string $list, string $show, string $entityClass): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $showPath = static::generatePath($show, ['id' => static::getEntityManager()->getRepository($entityClass)->find(1)->getSluggedId()]);

        $crawler = $client->request('GET', $list.'?layout=choice');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('body.ladb-embedded'));
        self::assertNotCount(0, $crawler->filter(\sprintf('.ladb-choice-row a[href$="%s"]', $showPath)));

        $crawler = $client->request('GET', $list.'?layout=choice&ex=1');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter(\sprintf('.ladb-choice-row a[href$="%s"]', $showPath)));
    }

    /**
     * @return iterable<string, array{string, string, class-string}>
     */
    public static function provideLists(): iterable
    {
        yield 'creations' => ['/creations/', 'core_creation_show', Creation::class];
        yield 'plans' => ['/plans/', 'core_plan_show', Plan::class];
        yield 'howtos' => ['/pas-a-pas/', 'core_howto_show', Howto::class];
        yield 'questions' => ['/questions/', 'core_qa_question_show', Question::class];
        yield 'workflows' => ['/processus/', 'core_workflow_show', Workflow::class];
        yield 'providers' => ['/fournisseurs/', 'core_provider_show', Provider::class];
        yield 'schools' => ['/ecoles/', 'core_school_show', School::class];
    }
}
