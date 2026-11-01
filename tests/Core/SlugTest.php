<?php

namespace Tests\Core;

use Ladb\CoreBundle\Entity\Core\Tag;
use Ladb\CoreBundle\Entity\Input\Wood;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Form\DataTransformer\TagsToLabelsTransformer;
use Tests\WebTestCase;

/**
 * Slugs and tag labels keep the transliteration of behat/transliterator, the one of Gedmo up to
 * 3.20 (#219, SluggableTransliteratorPass): the slugs already in the database and the new ones
 * stay alike, and an existing tag is found again from its label. Gedmo 3.21+ alone (symfony/string)
 * gives the slugs in comments.
 */
final class SlugTest extends WebTestCase
{
    public static function provideSlugs(): iterable
    {
        yield 'straight apostrophe' => ["L'atelier", 'latelier']; // l-atelier
        yield 'typographic apostrophe' => ['L’atelier', 'latelier']; // l-atelier
        yield 'multiplication sign' => ['3 × 4', '3-x-4']; // 3-4
        yield 'degree sign' => ['Rabot n°4 Stanley restauré', 'rabot-ndeg4-stanley-restaure']; // rabot-n-4-stanley-restaure
        yield 'micro sign' => ['5 µm', '5-um']; // 5-mm
        yield 'currency signs' => ['$ £ ¥', 'ps-y']; // empty
        yield 'Cyrillic' => ['Столярная мастерская', 'stoliarnaia-masterskaia']; // stolarnaa-masterskaa
        yield 'Greek' => ['Ξυλουργική εργασία', 'ksulourgike-ergasia']; // xylourgike-ergasia
    }

    /**
     * A new tag: its slug is built from its label at the insert (not updatable).
     *
     * @dataProvider provideSlugs
     */
    public function testSlugOfNewTag(string $label, string $slug): void
    {
        $om = static::getEntityManager();
        $tag = (new Tag())->setLabel($label);
        $om->persist($tag);
        $om->flush();

        self::assertSame($slug, $tag->getSlug());
    }

    /**
     * A new input (wood, tool...), whose slug has "_" as separator.
     *
     * @dataProvider provideSlugs
     */
    public function testSlugOfNewInput(string $label, string $slug): void
    {
        $om = static::getEntityManager();
        $wood = (new Wood())->setLabel($label);
        $om->persist($wood);
        $om->flush();

        self::assertSame(str_replace('-', '_', $slug), $wood->getSlug());
    }

    /**
     * An edited title: the slug of a content follows it (updatable).
     *
     * @dataProvider provideSlugs
     */
    public function testSlugOfEditedTitle(string $title, string $slug): void
    {
        $om = static::getEntityManager();
        $creation = $om->getRepository(Creation::class)->find(1);
        $creation->setTitle($title);
        $om->flush();

        self::assertSame($slug, $creation->getSlug());
    }

    /**
     * The labels of the tags field of the forms: urlized as the slugs, the apostrophe removed. A
     * label that changed would not find its tag again, and would create a duplicate.
     */
    public function testLabelsOfTagsField(): void
    {
        $om = static::getEntityManager();
        $tags = (new TagsToLabelsTransformer($om))->reverseTransform("L'atelier, Pin d'Oregon; C'est, chêne");
        foreach ($tags as $tag) {
            $om->persist($tag);
        }
        $om->flush();

        // l-atelier, pin-d-oregon and c-est with the slugger of symfony/string
        self::assertSame(['latelier', 'pin-doregon', 'cest', 'chene'], array_map(fn (Tag $tag) => $tag->getLabel(), $tags));
        self::assertSame(['latelier', 'pin-doregon', 'cest', 'chene'], array_map(fn (Tag $tag) => $tag->getSlug(), $tags));
    }
}
