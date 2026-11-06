<?php

namespace Tests\Command;

use Ladb\CoreBundle\Entity\Blog\Post;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Entity\Core\Comment;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Entity\Core\Notification;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Entity\Faq\Question as FaqQuestion;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Howto\Article;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Knowledge\Software;
use Ladb\CoreBundle\Entity\Knowledge\Tool;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Ladb\CoreBundle\Entity\Message\Thread;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Promotion\Graphic;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Tests\WebTestCase;

/**
 * The pages of the contents of ladb:fixtures:volume, on two items per entity. It indexes nothing:
 * castor app:db:fixtures-volume fills the index, the lists are not tested here.
 */
final class VolumeFixturesTest extends WebTestCase
{
    private const COUNT = 2;

    /**
     * @var array<class-string, string>
     */
    private const SHOW_PAGES = [
        Answer::class => '/questions/reponses/%d',
        Article::class => '/pas-a-pas/articles/%d.html',
        Book::class => '/livres/%d.html',
        Collection::class => '/collections/%d.html',
        Creation::class => '/creations/%d.html',
        Event::class => '/evenements/%d.html',
        FaqQuestion::class => '/faq/%d.html',
        Find::class => '/trouvailles/%d.html',
        Graphic::class => '/promouvoir/%d.html',
        Howto::class => '/pas-a-pas/%d.html',
        Offer::class => '/annonces/%d.html',
        Plan::class => '/plans/%d.html',
        Post::class => '/blog/%d.html',
        Provider::class => '/fournisseurs/%d.html',
        Question::class => '/questions/%d.html',
        School::class => '/ecoles/%d.html',
        Software::class => '/logiciels/%d.html',
        Thread::class => '/messagerie/thread/%d',
        Tool::class => '/outils/%d.html',
        Wood::class => '/xylotheque/%d.html',
        Workflow::class => '/processus/%d.html',
        Workshop::class => '/ateliers/%d.html',
    ];

    public function testThePagesOfTheContentsAnswer(): void
    {
        [$exitCode] = static::runCommandLine('ladb:fixtures:volume --count='.self::COUNT);
        self::assertSame(0, $exitCode);

        $paths = [];
        foreach (self::SHOW_PAGES as $class => $path) {
            $paths[] = sprintf($path, $this->lastId($class));
        }

        // The sender of the last thread: an account has to be in the thread to read it
        $username = static::getEntityManager()->find(Thread::class, $this->lastId(Thread::class))->getCreatedBy()->getUsername();
        $client = static::createClientAs($username);
        $client->followRedirects();

        foreach ([...$paths, '/@'.$username, '/notifications/', '/messagerie/'] as $path) {
            $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
        }
    }

    public function testTheCountersMatchTheInteractions(): void
    {
        static::runCommandLine('ladb:fixtures:volume --count='.self::COUNT);

        $om = static::getEntityManager();
        $creation = $om->find(Creation::class, $this->lastId(Creation::class));
        $criteria = ['entityType' => Creation::TYPE, 'entityId' => $creation->getId()];

        self::assertGreaterThan(0, $creation->getCommentCount());
        self::assertCount($creation->getCommentCount(), $om->getRepository(Comment::class)->findBy($criteria));
        self::assertCount($creation->getLikeCount(), $om->getRepository(Like::class)->findBy($criteria));

        $user = $creation->getUser();
        self::assertSame(
            $om->getRepository(Creation::class)->count(['user' => $user]),
            $user->getMeta()->getPublicCreationCount(),
        );
        self::assertNotSame([], $om->getRepository(Notification::class)->findBy(['user' => $user]));
    }

    /**
     * Published once and recently: ladb:cron:offers has nothing to do after a load.
     */
    public function testTheOffersAreLeftAloneByTheirCron(): void
    {
        static::runCommandLine('ladb:fixtures:volume --count='.self::COUNT);

        [$exitCode, $output] = static::runCommandLine('ladb:cron:offers --force');

        self::assertSame(0, $exitCode, $output);
        self::assertSame([], static::getSentEmailsByCommand());
        $offers = static::getEntityManager()->getRepository(Offer::class)->findAll();
        self::assertGreaterThan(self::COUNT, \count($offers));
        foreach ($offers as $offer) {
            self::assertFalse($offer->getIsDraft());
            self::assertSame(1, $offer->getPublishCount());
        }
    }

    /**
     * @param class-string $class
     */
    private function lastId(string $class): int
    {
        return (int) static::getEntityManager()->createQuery("SELECT MAX(e.id) FROM {$class} e")->getSingleScalarResult();
    }
}
