<?php

namespace Tests\Howto;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\AbstractDraftableAuthoredPublication;
use Ladb\CoreBundle\Entity\Howto\Article;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Tests\PublicationTestCase;

final class HowtoTest extends PublicationTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_howto';
    }

    protected static function getEntityClass(): string
    {
        return Howto::class;
    }

    protected static function getCounterName(): string
    {
        return 'Howto';
    }

    protected function getNewFormValues(string $owner): array
    {
        return [
            'howto[title]' => 'fabriquer un banc de jardin',
            'howto[mainPicture]' => static::createPicture($owner, 'test-howto'),
            'howto[kind]' => (string) Howto::KIND_TUTORIAL,
            'howto[body]' => 'Les étapes de la fabrication d\'un banc en robinier.',
            'howto[tags]' => 'banc,jardin',
        ];
    }

    /**
     * A new howto has no article yet: its author writes the first one.
     */
    protected function getCreatedPath(AbstractDraftableAuthoredPublication $publication): string
    {
        return static::$kernel->getContainer()->get('router')->generate('core_howto_article_new', [
            'id' => $publication->getId(),
            'owner' => $publication->getUser()->getUsernameCanonical(),
        ]);
    }

    public function testDeleteDeletesTheArticles(): void
    {
        $articleIds = array_map(fn (Article $article) => $article->getId(), $this->find(static::ID)->getArticles()->toArray());
        self::assertNotCount(0, $articleIds);

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', $this->path('delete', ['id' => static::ID]));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame([], static::getEntityManager()->getRepository(Article::class)->findBy(['id' => $articleIds]));
    }
}
