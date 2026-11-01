<?php

namespace Tests\Block;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Block\Gallery;
use Ladb\CoreBundle\Entity\Core\Block\Text;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Offer\Offer;
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
 * At the update, every type recreates its blocks from the form (resetBodyBlocks()), in the order
 * of the page: the validation and the merge of neighbour text blocks follow it.
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
     *
     * @dataProvider provideTypes
     *
     * @param class-string<BlockBodiedInterface> $class
     */
    public function testUpdateAddsRemovesAndReordersBlocks(string $class, string $routePrefix, string $formName): void
    {
        [$first, $gallery] = $this->addLastBlocks($class);
        $firstId = $first->getId();

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $this->submitBlocks($client, $routePrefix, $formName, [
            2 => self::text(self::LAST_BODY, 0),
            1 => self::gallery($gallery, 1),
            3 => self::text(self::NEW_BODY, 2),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], self::getErrors($crawler));
        self::assertCount(1, $crawler->filter('.alert-success'));
        // The form of the updated content, in the order of the page
        self::assertSame([
            0 => self::text(self::LAST_BODY, 0),
            1 => self::gallery($gallery, 1),
            2 => self::text(self::NEW_BODY, 2),
        ], self::getFormBlocks($crawler, $formName));

        static::getEntityManager()->clear();
        $blocks = $this->findBlocks($class);
        self::assertSame([Text::class, Gallery::class, Text::class], array_map('get_class', $blocks));
        self::assertSame(self::LAST_BODY, $blocks[0]->getBody());
        self::assertSame(self::getPictureIds($gallery), self::getPictureIds($blocks[1]));
        self::assertSame(self::NEW_BODY, $blocks[2]->getBody());
        self::assertNull(static::getEntityManager()->find(Text::class, $firstId));
        $content = $this->find($class);
        self::assertSame(self::LAST_BODY."\n".self::NEW_BODY, $content->getBody());
        // The extract of the lists and of the description of the page: its first text block
        self::assertSame('Un dernier bloc, sous la galerie.', trim($content->getBodyExtract()));
    }

    /**
     * The gallery moved to the top: the first block of the page is not a text block.
     *
     * @dataProvider provideTypes
     *
     * @param class-string<BlockBodiedInterface> $class
     */
    public function testUpdateRefusesAGalleryAtTheTop(string $class, string $routePrefix, string $formName): void
    {
        [$first, $gallery, $last] = $this->addLastBlocks($class);
        $blockIds = [$first->getId(), $gallery->getId(), $last->getId()];
        $firstBody = $first->getBody();

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $this->submitBlocks($client, $routePrefix, $formName, [
            1 => self::gallery($gallery, 0),
            0 => self::text($firstBody, 1),
            2 => self::text(self::LAST_BODY, 2),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(['Le premier bloc doit être un bloc de texte.'], self::getErrors($crawler));
        self::assertCount(0, $crawler->filter('.alert-success'));

        static::getEntityManager()->clear();
        self::assertSame($blockIds, array_map(static fn ($block) => $block->getId(), $this->findBlocks($class)));
    }

    /**
     * The last block moved between the first one and the gallery: the two text blocks, neighbours
     * on the page, are merged.
     *
     * @dataProvider provideTypes
     *
     * @param class-string<BlockBodiedInterface> $class
     */
    public function testUpdateMergesNeighbourTextBlocks(string $class, string $routePrefix, string $formName): void
    {
        [$first, $gallery] = $this->addLastBlocks($class);
        $firstBody = $first->getBody();

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $this->submitBlocks($client, $routePrefix, $formName, [
            0 => self::text($firstBody, 0),
            2 => self::text(self::LAST_BODY, 1),
            1 => self::gallery($gallery, 2),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], self::getErrors($crawler));
        self::assertCount(1, $crawler->filter('.alert-success'));

        static::getEntityManager()->clear();
        $blocks = $this->findBlocks($class);
        self::assertSame([Text::class, Gallery::class], array_map('get_class', $blocks));
        self::assertSame($firstBody."\n\n".self::LAST_BODY, $blocks[0]->getBody());
        self::assertSame(self::getPictureIds($gallery), self::getPictureIds($blocks[1]));
        self::assertSame($firstBody."\n\n".self::LAST_BODY, $this->find($class)->getBody());
    }

    /**
     * An invalid form shows the submitted blocks, under their keys and in their order, with the
     * type of their "_type".
     *
     * @dataProvider provideTypes
     *
     * @param class-string<BlockBodiedInterface> $class
     */
    public function testInvalidUpdateShowsTheSubmittedBlocks(string $class, string $routePrefix, string $formName): void
    {
        [$first, $gallery] = $this->addLastBlocks($class);
        $submitted = [
            2 => self::text(self::LAST_BODY, 0),
            1 => self::gallery($gallery, 1),
            3 => self::text(self::NEW_BODY, 2),
        ];

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $this->submitBlocks($client, $routePrefix, $formName, $submitted, ['title' => '']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('.alert-success'));
        self::assertSame($submitted, self::getFormBlocks($crawler, $formName));

        static::getEntityManager()->clear();
        self::assertSame($first->getId(), $this->findBlocks($class)[0]->getId());
    }

    /**
     * Each type with body blocks: its entity, its route prefix and its form name.
     *
     * @return iterable<string, array{class-string<BlockBodiedInterface>, string, string}>
     */
    public static function provideTypes(): iterable
    {
        yield 'creation' => [Creation::class, 'core_creation', 'ladb_wonder_creation'];
        yield 'event' => [Event::class, 'core_event', 'ladb_event_event'];
        yield 'find' => [Find::class, 'core_find', 'ladb_find_find'];
        yield 'offer' => [Offer::class, 'core_offer', 'ladb_offer_offer'];
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
     * The error messages of the form of the page.
     *
     * @return list<string>
     */
    private static function getErrors(Crawler $crawler): array
    {
        return $crawler->filter('form[action$="/update"] .alert-danger li')->each(static fn (Crawler $node): string => $node->text());
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
