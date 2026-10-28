<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Comment as CommentActivity;
use Ladb\CoreBundle\Entity\Core\Comment;
use Ladb\CoreBundle\Entity\Core\Mention;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The comments of a content, in XHR as on the site. On the creation of the fixtures, which has
 * two comments of "user2", one with an answer of "user".
 */
final class CommentTest extends InteractionTestCase
{
    private const PRAISE = 'Superbe travail, bravo !';

    private const QUESTION = 'Quelle huile as-tu utilisée ?';

    private const ANSWER = 'Une huile dure, deux couches.';

    public function testShowRedirectsToTheComment(): void
    {
        $client = static::createClient();
        $comment = $this->findComment(self::QUESTION);
        $client->request('GET', static::generatePath('core_comment_show', ['id' => $comment->getId()]));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith(static::findCreation()->getSluggedId().'.html#_comment_'.$comment->getId(), $client->getResponse()->headers->get('Location'));
    }

    public function testShownOnTheContent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', static::generatePath('core_creation_show', ['id' => self::CREATION_ID]));

        self::assertCount(1, $crawler->filter('#ladb_comment_'.$this->findComment(self::QUESTION)->getId()));
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $route, string $method): void
    {
        $client = static::createClient();
        $comment = $this->findComment(self::PRAISE);
        static::xhr($client, $method, static::generatePath($route, $this->getRouteParameters($route, $comment)));

        self::assertRedirectsToLogin($client);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideActions(): iterable
    {
        yield 'new' => ['core_comment_new', 'GET'];
        yield 'create' => ['core_comment_create', 'POST'];
        yield 'edit' => ['core_comment_edit', 'GET'];
        yield 'update' => ['core_comment_update', 'POST'];
        yield 'delete' => ['core_comment_delete', 'GET'];
    }

    public function testNewWithoutXhr(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', static::generatePath('core_comment_new', $this->getNewParameters()));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * A comment counts on the content and its author, makes an activity, mentions the users it
     * names and makes its author watch the content.
     */
    public function testCreate(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $commentCount = static::findCreation()->getCommentCount();
        $userCommentCount = static::findUser(UserFixtures::ADMIN)->getMeta()->getCommentCount();
        $watchCount = static::findCreation()->getWatchCount();

        $this->comment($client, 'Très belle table, @user2 a raison.');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $comment = $this->findComment('Très belle table, @user2 a raison.');
        self::assertSame(UserFixtures::ADMIN, $comment->getUser()->getUsername());
        self::assertSame($commentCount + 1, static::findCreation()->getCommentCount());
        self::assertSame($userCommentCount + 1, static::findUser(UserFixtures::ADMIN)->getMeta()->getCommentCount());
        self::assertNotNull(static::getEntityManager()->getRepository(CommentActivity::class)->findOneBy(['comment' => $comment]));
        self::assertSame($watchCount + 1, static::findCreation()->getWatchCount());

        $mention = static::getEntityManager()->getRepository(Mention::class)->findOneBy(['entityType' => Comment::TYPE, 'entityId' => $comment->getId()]);
        self::assertSame(UserFixtures::USER2, $mention->getMentionedUser()->getUsername());
    }

    public function testCreateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $lastId = $this->findLastComment()->getId();

        $this->comment($client, '');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $client->getCrawler()->filter('.alert-danger'));
        self::assertSame($lastId, $this->findLastComment()->getId());
    }

    public function testCreateOnDraft(): void
    {
        static::unpublishCreation();
        $client = static::createClientAs(UserFixtures::USER);
        $parameters = $this->getNewParameters();

        static::xhr($client, 'GET', static::generatePath('core_comment_new', $parameters));
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $fieldName = \sprintf('ladb_comment_%d_%d_0', Creation::TYPE, self::CREATION_ID);
        static::xhr($client, 'POST', static::generatePath('core_comment_create', $parameters), [$fieldName => ['body' => 'Un commentaire sur un brouillon.']]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testReply(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $parent = $this->findComment(self::QUESTION);
        $childCount = $parent->getChildCount();

        $this->comment($client, 'Moi aussi, je me le demandais.', $parent->getId());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($parent->getId(), $this->findLastComment()->getParent()->getId());
        self::assertSame($childCount + 1, $this->findComment(self::QUESTION)->getChildCount());
    }

    /**
     * @dataProvider provideUpdaters
     */
    public function testUpdate(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        $comment = $this->findComment(self::PRAISE);
        $crawler = static::xhr($client, 'GET', static::generatePath('core_comment_edit', ['id' => $comment->getId()]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_comment_update', ['id' => $comment->getId()])))->form();
        $form[\sprintf('ladb_comment_%d_%d[body]', Creation::TYPE, self::CREATION_ID)] = 'Superbe travail, bravo à toi !';

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        $comment = static::getEntityManager()->getRepository(Comment::class)->find($comment->getId());
        self::assertSame(200 === $status ? 'Superbe travail, bravo à toi !' : self::PRAISE, $comment->getBody());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideUpdaters(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 200];
        yield 'other user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * The edit form is the one of the update: it shows the comment to anyone, only the update
     * checks the rights.
     */
    public function testEdit(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $comment = $this->findComment(self::PRAISE);
        $crawler = static::xhr($client, 'GET', static::generatePath('core_comment_edit', ['id' => $comment->getId()]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_comment_update', ['id' => $comment->getId()]))));
    }

    /**
     * Its author deletes a comment without answer; the admins delete any, with its answers.
     *
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, string $body, int $status): void
    {
        $client = static::createClientAs($username);
        $comment = $this->findComment($body);
        $commentCount = static::findCreation()->getCommentCount();
        $answerCount = $comment->getChildCount();

        static::xhr($client, 'GET', static::generatePath('core_comment_delete', ['id' => $comment->getId()]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        if (200 === $status) {
            self::assertNull($this->findComment($body));
            self::assertSame($commentCount - 1 - $answerCount, static::findCreation()->getCommentCount());
        } else {
            self::assertNotNull($this->findComment($body));
            self::assertSame($commentCount, static::findCreation()->getCommentCount());
        }
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER2, self::PRAISE, 200];
        yield 'author of an answered comment' => [UserFixtures::USER2, self::QUESTION, 404];
        yield 'other user' => [UserFixtures::USER, self::PRAISE, 404];
        yield 'admin, with the answers' => [UserFixtures::ADMIN, self::QUESTION, 200];
    }

    /**
     * An admin moves an answer up to the first level.
     *
     * @dataProvider provideMovers
     */
    public function testAdminMoveup(?string $username, int $status): void
    {
        $client = static::createClientFor($username);
        $answer = $this->findComment(self::ANSWER);
        $client->request('GET', static::generatePath('core_comment_admin_moveup', ['id' => $answer->getId()]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(200 === $status, null === $this->findComment(self::ANSWER)->getParent());
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function provideMovers(): iterable
    {
        yield 'anonymous' => [null, 404];
        yield 'user' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * An admin turns a comment on a question into an answer, of the same author: on the comment
     * of "user2" on the question of the fixtures. The reverse is in tests/Qa/AnswerTest.php.
     *
     * @dataProvider provideConverters
     */
    public function testAdminConvertToAnswer(?string $username, bool $allowed): void
    {
        $client = static::createClientFor($username);
        $question = static::getEntityManager()->getRepository(Question::class)->find(1);
        $answerCount = $question->getAnswerCount();
        $commentId = static::getEntityManager()->getRepository(Comment::class)->findOneBy(['entityType' => Question::TYPE, 'entityId' => 1, 'body' => 'Quelle épaisseur envisages-tu ?'])->getId();

        $client->request('GET', static::generatePath('core_comment_admin_converttoanswer', ['id' => $commentId, 'questionId' => 1]));

        if (!$allowed) {
            self::assertSame(404, $client->getResponse()->getStatusCode());

            return;
        }
        $answer = static::getEntityManager()->getRepository(Answer::class)->findOneBy([], ['id' => 'DESC']);
        self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_qa_answer_show', ['id' => $answer->getId()])));
        self::assertSame(UserFixtures::USER2, $answer->getUser()->getUsername());
        self::assertStringContainsString('Quelle épaisseur envisages-tu ?', $answer->getBody());
        self::assertSame($answerCount + 1, $answer->getQuestion()->getAnswerCount());
        static::getEntityManager()->clear();
        self::assertNull(static::getEntityManager()->getRepository(Comment::class)->find($commentId));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function provideConverters(): iterable
    {
        yield 'anonymous' => [null, false];
        yield 'user' => [UserFixtures::USER2, false];
        yield 'admin' => [UserFixtures::ADMIN, true];
    }

    /**
     * Comments $body on the creation, from the form of a new comment, as the site does.
     */
    private function comment(KernelBrowser $client, string $body, int $parentId = 0): void
    {
        $parameters = $this->getNewParameters($parentId);
        $crawler = static::xhr($client, 'GET', static::generatePath('core_comment_new', $parameters));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_comment_create', $parameters)))->form();
        $form[\sprintf('ladb_comment_%d_%d_%d[body]', Creation::TYPE, self::CREATION_ID, $parentId)] = $body;

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
    }

    /**
     * @return array{entityType: int, entityId: int, parentId: int}
     */
    private function getNewParameters(int $parentId = 0): array
    {
        return ['entityType' => Creation::TYPE, 'entityId' => self::CREATION_ID, 'parentId' => $parentId];
    }

    /**
     * @return array<string, int>
     */
    private function getRouteParameters(string $route, Comment $comment): array
    {
        return \in_array($route, ['core_comment_new', 'core_comment_create'], true) ? $this->getNewParameters() : ['id' => $comment->getId()];
    }

    /**
     * A comment of the fixtures on the creation, by its body.
     */
    private function findComment(string $body): ?Comment
    {
        return static::getEntityManager()->getRepository(Comment::class)->findOneBy([
            'entityType' => Creation::TYPE,
            'entityId' => self::CREATION_ID,
            'body' => $body,
        ]);
    }

    private function findLastComment(): Comment
    {
        return static::getEntityManager()->getRepository(Comment::class)->findOneBy([], ['id' => 'DESC']);
    }
}
