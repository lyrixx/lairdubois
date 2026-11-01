<?php

namespace Tests\Block;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Block\Gallery;
use Ladb\CoreBundle\Entity\Core\Block\Text;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Model\BlockBodiedInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The body blocks of a content (PolyCollectionType and its PolyCollectionResizeFormListener, the
 * same for every type). A block of the form has the type of its class, a submitted one the type
 * that its "_type" field names. The first block is a text block, and a text block separates two
 * others (BodyBlocks). What the editor of the site submits: the rows in the order of the page,
 * under their keys, their sortIndex renumbered, a new row under the next key.
 *
 * At the update, a creation recreates its blocks from the form (resetBodyBlocks(), as the
 * workshop, the questions, the answers, the articles, the blog and the FAQ), an event keeps them
 * and updates them (as the find and the offer).
 */
final class BodyBlocksTest extends WebTestCase
{
    use XhrTrait;

    private const ID = 1;
    private const LAST_BODY = 'Un dernier bloc, **sous** la galerie.';
    private const NEW_BODY = 'Un bloc **ajouté**.';

    /**
     * Creation 1 of the fixtures: a text block, then a gallery block.
     */
    public function testEditShowsEachBlockWithItsType(): void
    {
        [$text, $gallery] = $this->findBlocks(Creation::class);

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', static::generatePath('core_creation_edit', ['id' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([
            0 => self::text($text->getBody(), 0),
            1 => self::gallery($gallery, 1),
        ], self::getFormBlocks($crawler, 'ladb_wonder_creation'));
    }

    /**
     * The first block deleted, the last one moved to the top, a block added at the end.
     */
    public function testUpdateOfACreationAddsRemovesAndReordersBlocks(): void
    {
        [$first, $gallery] = $this->addLastBlocks(Creation::class);
        $firstId = $first->getId();

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $this->submitBlocks($client, 'core_creation', 'ladb_wonder_creation', [
            2 => self::text(self::LAST_BODY, 0),
            1 => self::gallery($gallery, 1),
            3 => self::text(self::NEW_BODY, 2),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('.alert-success'));
        // The form of the updated creation
        self::assertSame([
            0 => self::text(self::LAST_BODY, 0),
            1 => self::gallery($gallery, 1),
            2 => self::text(self::NEW_BODY, 2),
        ], self::getFormBlocks($crawler, 'ladb_wonder_creation'));

        static::getEntityManager()->clear();
        $blocks = $this->findBlocks(Creation::class);
        self::assertSame([Text::class, Gallery::class, Text::class], array_map('get_class', $blocks));
        self::assertSame(self::LAST_BODY, $blocks[0]->getBody());
        self::assertSame(self::getPictureIds($gallery), self::getPictureIds($blocks[1]));
        self::assertSame(self::NEW_BODY, $blocks[2]->getBody());
        self::assertNull(static::getEntityManager()->find(Text::class, $firstId));
        self::assertSame(self::LAST_BODY."\n".self::NEW_BODY, $this->find(Creation::class)->getBody());
    }

    /**
     * An invalid form shows the submitted blocks, under their keys, with the type of their "_type".
     */
    public function testInvalidUpdateShowsTheSubmittedBlocks(): void
    {
        [$first, $gallery] = $this->addLastBlocks(Creation::class);
        $submitted = [
            2 => self::text(self::LAST_BODY, 0),
            1 => self::gallery($gallery, 1),
            3 => self::text(self::NEW_BODY, 2),
        ];

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $this->submitBlocks($client, 'core_creation', 'ladb_wonder_creation', $submitted, ['title' => '']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('.alert-success'));
        self::assertSame($submitted, self::getFormBlocks($crawler, 'ladb_wonder_creation'));

        static::getEntityManager()->clear();
        self::assertSame($first->getId(), $this->findBlocks(Creation::class)[0]->getId());
    }

    /**
     * The last block deleted, a block added between the first one and the gallery. The event keeps
     * its blocks, the new one goes at the end of its collection: the page orders them by sortIndex.
     */
    public function testUpdateOfAnEventAddsRemovesAndReordersBlocks(): void
    {
        [$first, $gallery, $last] = $this->addLastBlocks(Event::class);
        $firstId = $first->getId();
        $galleryId = $gallery->getId();
        $lastId = $last->getId();

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $this->submitBlocks($client, 'core_event', 'ladb_event_event', [
            0 => self::text($first->getBody(), 0),
            3 => self::text(self::NEW_BODY, 1),
            1 => self::gallery($gallery, 2),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('.alert-success'));
        // The form of the updated event: its collection, in the order of its keys
        self::assertSame([
            0 => self::text($first->getBody(), 0),
            1 => self::gallery($gallery, 2),
            3 => self::text(self::NEW_BODY, 1),
        ], self::getFormBlocks($crawler, 'ladb_event_event'));

        static::getEntityManager()->clear();
        $blocks = $this->findBlocks(Event::class);
        self::assertSame([Text::class, Text::class, Gallery::class], array_map('get_class', $blocks));
        self::assertSame($firstId, $blocks[0]->getId());
        self::assertSame(self::NEW_BODY, $blocks[1]->getBody());
        self::assertSame($galleryId, $blocks[2]->getId());
        self::assertNull(static::getEntityManager()->find(Text::class, $lastId));
        self::assertSame($blocks[0]->getBody()."\n".self::NEW_BODY, $this->find(Event::class)->getBody());
    }

    /**
     * Makes the blocks of the content of id 1 a text block (the one of the fixtures), a gallery
     * block (the one of the fixtures, or a new one) and a text block, as the controller saves them.
     *
     * @param class-string<BlockBodiedInterface> $class
     *
     * @return list<Text|Gallery>
     */
    private function addLastBlocks(string $class): array
    {
        $content = $this->find($class);
        $factory = static::getContainer()->get('tests.fixture_factory');
        if (1 === $content->getBodyBlocks()->count()) {
            $picture = $factory->picture(static::findUser(UserFixtures::USER), 'wood-4.jpg', 'test-body-blocks');
            $content->addBodyBlock($factory->galleryBlock([$picture], 1));
        }
        $content->addBodyBlock($factory->textBlock(self::LAST_BODY, 2));
        $factory->preprocess($content);
        static::getEntityManager()->flush();

        return $this->findBlocks($class);
    }

    /**
     * Submits the form of the edit page with these blocks instead of the ones it shows.
     *
     * @param array<int, array<string, string>> $blocks
     * @param array<string, string>             $fields
     */
    private function submitBlocks(KernelBrowser $client, string $routePrefix, string $formName, array $blocks, array $fields = []): Crawler
    {
        $crawler = $client->request('GET', static::generatePath($routePrefix.'_edit', ['id' => self::ID]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath($routePrefix.'_update', ['id' => self::ID])))->form();
        $values = $form->getPhpValues();
        $values[$formName] = array_replace($values[$formName], $fields, ['bodyBlocks' => $blocks]);

        return $client->request('POST', $form->getUri(), $values);
    }

    /**
     * The fields of a text block, in the order of the form.
     *
     * @return array<string, string>
     */
    private static function text(string $body, int $sortIndex): array
    {
        return ['body' => $body, '_type' => 'ladb_block_text', 'sortIndex' => (string) $sortIndex];
    }

    /**
     * The fields of a gallery block, in the order of the form.
     *
     * @return array<string, string>
     */
    private static function gallery(Gallery $gallery, int $sortIndex): array
    {
        return ['pictures' => implode(',', self::getPictureIds($gallery)), '_type' => 'ladb_block_gallery', 'sortIndex' => (string) $sortIndex];
    }

    /**
     * @return list<int>
     */
    private static function getPictureIds(Gallery $gallery): array
    {
        return array_map(static fn ($picture) => $picture->getId(), $gallery->getPictures()->toArray());
    }

    /**
     * The blocks of the form of the page, by key.
     *
     * @return array<int, array<string, string>>
     */
    private static function getFormBlocks(Crawler $crawler, string $formName): array
    {
        $values = $crawler->filter('form[action$="/update"]')->form()->getPhpValues();

        return $values[$formName]['bodyBlocks'];
    }

    /**
     * The blocks of the content of id 1, ordered by sortIndex.
     *
     * @param class-string<BlockBodiedInterface> $class
     *
     * @return list<Text|Gallery>
     */
    private function findBlocks(string $class): array
    {
        return $this->find($class)->getBodyBlocks()->toArray();
    }

    /**
     * @template T of BlockBodiedInterface
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class): BlockBodiedInterface
    {
        return static::getEntityManager()->getRepository($class)->find(self::ID);
    }
}
