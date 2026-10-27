<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Contribute;
use Ladb\CoreBundle\Entity\Core\Vote;
use Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Tests\WebTestCase;

/**
 * The values of a knowledge, the same for all the types: proposal, edit, deletion, votes and
 * moves, with their rights. On the wood of the fixtures, whose values ("Chêne" and its grain)
 * are from "user". Each type proposes one of its own values in its test (KnowledgeTestCase).
 */
final class ValueTest extends WebTestCase
{
    use ValueTrait;

    private const ID = 1;

    private const SCIENTIFIC_NAME = 'Quercus robur';

    public function testShowField(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $this->getFieldPath(Wood::FIELD_NAME));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Chêne', $crawler->filter('body')->text(null, false));
        // Only the users propose a value
        self::assertCount(0, $crawler->filter(\sprintf('form[action="%s"]', $this->getCreatePath(Wood::FIELD_NAME))));
    }

    public function testShowValue(): void
    {
        $value = $this->findWood()->getNameValues()->first();

        $client = static::createClient();
        $client->request('GET', static::generatePath('core_knowledge_value_show', ['id' => $value->getId()]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testContributors(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', static::generatePath('core_knowledge_contributors', ['entityType' => Wood::TYPE, 'entityId' => self::ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $crawler->filter('a[href$="/@user"]'));
        // A comment and a like are no contribution
        self::assertCount(0, $crawler->filter('a[href$="/@user2"]'));
    }

    public function testProposeAsAnonymous(): void
    {
        $client = static::createClient();
        static::xhr($client, 'POST', $this->getCreatePath(Wood::FIELD_SCIENTIFICNAME));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    public function testProposeWithoutXhr(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('POST', $this->getCreatePath(Wood::FIELD_SCIENTIFICNAME));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testProposeWithUnconfirmedEmail(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        static::findUser(UserFixtures::USER2)->setEmailConfirmed(false);
        static::getEntityManager()->flush();

        static::xhr($client, 'POST', $this->getCreatePath(Wood::FIELD_SCIENTIFICNAME));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testProposeInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $lastId = static::findLastValue()->getId();

        static::proposeValue($client, $this->findWood(), Wood::FIELD_SCIENTIFICNAME, ['ladb_knowledge_value_text[data]' => '<b>Quercus</b>']);

        // The form again, with its error
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $client->getCrawler()->filter('.alert-danger'));
        self::assertSame($lastId, static::findLastValue()->getId());
    }

    /**
     * The moderation score puts a value first, whatever its votes: only the admins set it.
     *
     * @dataProvider provideModerators
     */
    public function testProposeWithModerationScore(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $lastId = static::findLastValue()->getId();

        static::proposeValue($client, $this->findWood(), Wood::FIELD_SCIENTIFICNAME, [
            'ladb_knowledge_value_text[data]' => self::SCIENTIFIC_NAME,
            'ladb_knowledge_value_text[moderationScore]' => '1',
        ]);

        self::assertSame($status, $client->getResponse()->getStatusCode());
        if (200 === $status) {
            self::assertEquals(1, static::findLastValue()->getModerationScore());
        } else {
            self::assertSame($lastId, static::findLastValue()->getId());
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideModerators(): iterable
    {
        yield 'user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * Only its author edits a value, until someone votes for it, and the admins.
     *
     * @dataProvider provideEditors
     */
    public function testEdit(string $username, int $status): void
    {
        $value = $this->proposeScientificName();

        $client = static::createClientAs($username);
        static::xhr($client, 'GET', $this->getValuePath('edit', $value));

        self::assertSame($status, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 200];
        yield 'other user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * @dataProvider provideUpdaters
     */
    public function testUpdate(string $username): void
    {
        $value = $this->proposeScientificName();

        $client = static::createClientAs($username);
        $form = static::getValueEditForm($client, $this->findWood(), Wood::FIELD_SCIENTIFICNAME, $value);
        $form[\sprintf('ladb_knowledge_value_text_%d[data]', $value->getId())] = 'Quercus petraea';
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('Quercus petraea', $this->findWood()->getScientificname());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUpdaters(): iterable
    {
        yield 'author' => [UserFixtures::USER2];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    public function testUpdateAsOtherUser(): void
    {
        $value = $this->proposeScientificName();
        $form = static::getValueEditForm(static::createClientAs(UserFixtures::USER2), $this->findWood(), Wood::FIELD_SCIENTIFICNAME, $value);
        $form[\sprintf('ladb_knowledge_value_text_%d[data]', $value->getId())] = 'Quercus petraea';

        // The access is checked before the form: its token, the one of the author, does not matter
        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(self::SCIENTIFIC_NAME, $this->findWood()->getScientificname());
    }

    public function testEditVotedValue(): void
    {
        $value = $this->proposeScientificName();
        static::vote(static::createClientAs(UserFixtures::USER), $value, 'up');

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'GET', $this->getValuePath('edit', $value));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, int $status): void
    {
        $value = $this->proposeScientificName();
        $valueId = $value->getId();
        $contributorCount = $this->findWood()->getContributorCount();
        $proposalCount = static::findUser(UserFixtures::USER2)->getMeta()->getProposalCount();

        $client = static::createClientAs($username);
        static::xhr($client, 'GET', $this->getValuePath('delete', $value));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        if (200 !== $status) {
            self::assertNotNull(static::getEntityManager()->getRepository(BaseValue::class)->find($valueId));

            return;
        }

        self::assertNull(static::getEntityManager()->getRepository(BaseValue::class)->find($valueId));
        $wood = $this->findWood();
        // Its setter makes null an empty string (ucfirst())
        self::assertSame('', $wood->getScientificname());
        self::assertSame($contributorCount - 1, $wood->getContributorCount());
        self::assertSame($proposalCount - 1, static::findUser(UserFixtures::USER2)->getMeta()->getProposalCount());
        self::assertCount(0, static::getEntityManager()->getRepository(Contribute::class)->findBy(['value' => $valueId]));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 200];
        yield 'other user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * Even its author does not delete the value of a mandatory field. The admins neither, when it
     * is the last one.
     *
     * @dataProvider provideMandatoryDeleters
     */
    public function testDeleteMandatory(string $username): void
    {
        $value = $this->findWood()->getNameValues()->first();

        $client = static::createClientAs($username);
        static::xhr($client, 'GET', $this->getValuePath('delete', $value, Wood::FIELD_NAME));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('Chêne', $this->findWood()->getName());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMandatoryDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    /**
     * An admin deletes the value of a mandatory field that has another one.
     */
    public function testDeleteMandatoryWithAnotherValue(): void
    {
        static::proposeValue(static::createClientAs(UserFixtures::USER2), $this->findWood(), Wood::FIELD_NAME, ['ladb_knowledge_value_text[data]' => 'Oak']);
        $value = static::findLastValue();
        self::assertSame('Oak,Chêne', $this->findWood()->getName());

        $client = static::createClientAs(UserFixtures::ADMIN);
        static::xhr($client, 'GET', $this->getValuePath('delete', $value, Wood::FIELD_NAME));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('Chêne', $this->findWood()->getName());
    }

    /**
     * An admin moves a value to another field of the same type: an English name proposed as a
     * name.
     *
     * @dataProvider provideMovers
     */
    public function testMove(string $username, int $status): void
    {
        static::proposeValue(static::createClientAs(UserFixtures::USER2), $this->findWood(), Wood::FIELD_NAME, ['ladb_knowledge_value_text[data]' => 'Oak']);
        $value = static::findLastValue();
        // The newest value first
        self::assertSame('Oak,Chêne', $this->findWood()->getName());

        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath('core_knowledge_value_admin_move', [
            'entityType' => Wood::TYPE,
            'entityId' => self::ID,
            'fieldSrc' => Wood::FIELD_NAME,
            'fieldDest' => Wood::FIELD_ENGLISHNAME,
            'id' => $value->getId(),
        ]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        $wood = $this->findWood();
        if (200 === $status) {
            self::assertSame('Chêne', $wood->getName());
            self::assertSame('Oak', $wood->getEnglishname());
        } else {
            self::assertSame('Oak,Chêne', $wood->getName());
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideMovers(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * Not the last value of a mandatory field.
     */
    public function testMoveMandatory(): void
    {
        $value = $this->findWood()->getNameValues()->first();

        $client = static::createClientAs(UserFixtures::ADMIN);
        static::xhr($client, 'GET', static::generatePath('core_knowledge_value_admin_move', [
            'entityType' => Wood::TYPE,
            'entityId' => self::ID,
            'fieldSrc' => Wood::FIELD_NAME,
            'fieldDest' => Wood::FIELD_ENGLISHNAME,
            'id' => $value->getId(),
        ]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('Chêne', $this->findWood()->getName());
        self::assertSame(Wood::FIELD_NAME, static::getEntityManager()->getRepository(BaseValue::class)->find($value->getId())->getParentEntityField());
    }

    public function testVoteUp(): void
    {
        $value = $this->proposeScientificName();
        $positiveVoteCount = $this->findWood()->getPositiveVoteCount();
        $userPositiveVoteCount = static::findUser(UserFixtures::USER)->getMeta()->getPositiveVoteCount();

        $client = static::createClientAs(UserFixtures::USER);
        static::vote($client, $value, 'up');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $value = static::findLastValue();
        self::assertSame(1, $value->getVoteScore());
        self::assertSame(1, $value->getVoteCount());
        self::assertSame($positiveVoteCount + 1, $this->findWood()->getPositiveVoteCount());
        self::assertSame($userPositiveVoteCount + 1, static::findUser(UserFixtures::USER)->getMeta()->getPositiveVoteCount());
    }

    public function testVoteForOwnValue(): void
    {
        $value = $this->proposeScientificName();

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'GET', static::generatePath('core_vote_new', ['entityType' => $value->getType(), 'entityId' => $value->getId(), 'way' => 'up']));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * A down vote needs a reason, kept as a comment of the value.
     */
    public function testVoteDown(): void
    {
        $value = $this->proposeScientificName();
        $client = static::createClientAs(UserFixtures::USER);

        static::vote($client, $value, 'down');
        self::assertNotCount(0, $client->getCrawler()->filter('.alert-danger'));
        self::assertSame(0, static::findLastValue()->getVoteCount());

        // Another user: the lock against double submissions (AbstractController::createLock())
        // holds the vote of the first one for 3 seconds, even after an invalid form
        static::vote(static::createClientAs(UserFixtures::ADMIN), $value, 'down', 'Ce nom est celui du chêne pédonculé.');
        $value = static::findLastValue();
        self::assertSame(-1, $value->getVoteScore());
        self::assertSame(1, $value->getCommentCount());
        // A rejected value: the field has no more data
        self::assertSame('', $this->findWood()->getScientificname());
    }

    public function testDeleteVote(): void
    {
        $value = $this->proposeScientificName();
        static::vote(static::createClientAs(UserFixtures::USER), $value, 'up');
        $vote = static::getEntityManager()->getRepository(Vote::class)->findOneBy(['entityType' => $value->getType(), 'entityId' => $value->getId()]);

        $client = static::createClientAs(UserFixtures::ADMIN);
        static::xhr($client, 'GET', static::generatePath('core_vote_delete', ['id' => $vote->getId()]));
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'GET', static::generatePath('core_vote_delete', ['id' => $vote->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $value = static::findLastValue();
        self::assertSame(0, $value->getVoteScore());
        self::assertSame(0, $value->getVoteCount());
    }

    /**
     * The best voted value of a single valued field gives it its data.
     */
    public function testVotesChooseTheData(): void
    {
        static::proposeValue(static::createClientAs(UserFixtures::ADMIN), $this->findWood(), Wood::FIELD_FAMILY, ['ladb_knowledge_value_integer[data]' => '1']);
        $hardwood = static::findLastValue();
        static::proposeValue(static::createClientAs(UserFixtures::USER2), $this->findWood(), Wood::FIELD_FAMILY, ['ladb_knowledge_value_integer[data]' => '2']);
        $softwood = static::findLastValue();

        static::vote(static::createClientAs(UserFixtures::USER), $hardwood, 'up');
        self::assertSame(1, $this->findWood()->getFamily());

        static::vote(static::createClientAs(UserFixtures::ADMIN), $softwood, 'up');
        static::vote(static::createClientAs(UserFixtures::USER2), $hardwood, 'down', 'C\'est un bois de résineux.');
        self::assertSame(2, $this->findWood()->getFamily());
    }

    /**
     * A value of "user2" for the scientific name, that the wood of the fixtures has not.
     */
    private function proposeScientificName(): BaseValue
    {
        $client = static::createClientAs(UserFixtures::USER2);
        static::proposeValue($client, $this->findWood(), Wood::FIELD_SCIENTIFICNAME, ['ladb_knowledge_value_text[data]' => self::SCIENTIFIC_NAME]);
        self::assertSame(self::SCIENTIFIC_NAME, $this->findWood()->getScientificname());

        return static::findLastValue();
    }

    private function findWood(): Wood
    {
        return static::getEntityManager()->getRepository(Wood::class)->find(self::ID);
    }

    private function getFieldPath(string $field): string
    {
        return static::generatePath('core_knowledge_field_show', ['entityType' => Wood::TYPE, 'entityId' => self::ID, 'field' => $field]);
    }

    private function getCreatePath(string $field): string
    {
        return static::generatePath('core_knowledge_value_create', ['entityType' => Wood::TYPE, 'entityId' => self::ID, 'field' => $field]);
    }

    private function getValuePath(string $action, BaseValue $value, string $field = Wood::FIELD_SCIENTIFICNAME): string
    {
        return static::generatePath('core_knowledge_value_'.$action, ['entityType' => Wood::TYPE, 'entityId' => self::ID, 'field' => $field, 'id' => $value->getId()]);
    }
}
