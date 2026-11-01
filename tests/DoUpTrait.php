<?php

namespace Tests;

use Ladb\CoreBundle\DataFixtures\UserFixtures;

/**
 * The "do up" checkbox of the edit form of an admin (ladb_do_up, in the body of the POST): the
 * updated content moves up the lists (its changedAt). For the contents that have it: creation,
 * find, blog post.
 */
trait DoUpTrait
{
    /**
     * @dataProvider provideDoUps
     */
    public function testUpdateWithDoUp(bool $doUp): void
    {
        $changedAt = new \DateTime('2020-01-01 00:00:00');
        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->find(static::ID)->setChangedAt($changedAt);
        static::getEntityManager()->flush();

        $crawler = $client->request('GET', $this->path('edit', ['id' => static::ID]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', $this->path('update', ['id' => static::ID])))->form();
        if ($doUp) {
            $form['ladb_do_up']->tick();
        }
        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($doUp, $this->find(static::ID)->getChangedAt() > $changedAt);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideDoUps(): iterable
    {
        yield 'ticked' => [true];
        yield 'unticked' => [false];
    }
}
