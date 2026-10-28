<?php

namespace Tests\Qa;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Activity\Answer as AnswerActivity;
use Ladb\CoreBundle\Entity\Core\Comment;
use Ladb\CoreBundle\Entity\Core\Vote;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\DomCrawler\Form;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The answers to the question of the fixtures (id 1, of "user"): "user2" answers it, "user"
 * votes for the answer. Except the deletion and the best answer, the actions are XHR.
 */
final class AnswerTest extends WebTestCase
{
    use XhrTrait;

    private const QUESTION_ID = 1;

    public function testCreate(): void
    {
        $question = $this->findQuestion();
        $answerCount = $question->getAnswerCount();
        $userAnswerCount = static::findUser(UserFixtures::ADMIN)->getMeta()->getAnswerCount();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->submitNewForm($client, 'Du chêne, mais pas en contact avec l\'eau.');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $answer = $this->findAnswer(UserFixtures::ADMIN);
        self::assertNotNull($answer);
        self::assertSame($answerCount + 1, $this->findQuestion()->getAnswerCount());
        self::assertSame($userAnswerCount + 1, static::findUser(UserFixtures::ADMIN)->getMeta()->getAnswerCount());
        self::assertCount(1, static::getEntityManager()->getRepository(AnswerActivity::class)->findBy(['answer' => $answer]));
        // A web push notification to the author of the question
        self::assertCount(1, $client->getContainer()->get('tests.webpush_notification_transport')->getSent());
    }

    /**
     * Its author answers too, without a web push notification to itself.
     */
    public function testCreateAsTheAuthorOfTheQuestion(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $this->submitNewForm($client, 'Finalement, j\'ai pris du frêne.');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->findAnswer(UserFixtures::USER));
        self::assertCount(0, $client->getContainer()->get('tests.webpush_notification_transport')->getSent());
    }

    /**
     * Its author does not thank in an answer: the comments are for that.
     */
    public function testCreateThanksAsTheAuthorOfTheQuestion(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $this->submitNewForm($client, 'Merci à tous pour vos réponses !');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotCount(0, $client->getCrawler()->filter('.has-error, .alert-danger'));
        self::assertNull($this->findAnswer(UserFixtures::USER));
    }

    public function testCreateInvalid(): void
    {
        $answerCount = $this->findQuestion()->getAnswerCount();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->submitNewForm($client, '');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNull($this->findAnswer(UserFixtures::ADMIN));
        self::assertSame($answerCount, $this->findQuestion()->getAnswerCount());
    }

    /**
     * One answer per user and question.
     */
    public function testCreateTwice(): void
    {
        $form = $this->getNewForm(static::createClientAs(UserFixtures::ADMIN));
        $form['ladb_qa_answer[bodyBlocks][0][body]'] = 'Une seconde réponse.';

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertCount(1, static::getEntityManager()->getRepository(Answer::class)->findBy(['question' => self::QUESTION_ID, 'user' => static::findUser(UserFixtures::USER2)]));
    }

    public function testCreateOnLockedQuestion(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', static::generatePath('core_qa_question_lock', ['id' => self::QUESTION_ID]));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        $client = static::createClientAs(UserFixtures::ADMIN);
        static::xhr($client, 'GET', static::generatePath('core_qa_answer_new', ['id' => self::QUESTION_ID]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCreateAsAnonymous(): void
    {
        $client = static::createClient();
        static::xhr($client, 'POST', static::generatePath('core_qa_answer_create', ['id' => self::QUESTION_ID]));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * @dataProvider provideEditors
     */
    public function testEdit(string $username, int $status): void
    {
        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath('core_qa_answer_edit', ['id' => $this->findAnswer(UserFixtures::USER2)->getId()]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
    }

    /**
     * The author of the answer and the admins, not the one of the question.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function provideEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 200];
        yield 'author of the question' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    public function testUpdate(): void
    {
        $id = $this->findAnswer(UserFixtures::USER2)->getId();
        $form = $this->getEditForm(static::createClientAs(UserFixtures::USER2), $id);
        $form['ladb_qa_answer[bodyBlocks][0][body]'] = 'Le frêne aussi, bien huilé.';

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('Le frêne aussi, bien huilé.', $this->findAnswer(UserFixtures::USER2)->getBodyBlocks()->first()->getBody());
    }

    public function testUpdateAsOtherUser(): void
    {
        $id = $this->findAnswer(UserFixtures::USER2)->getId();
        $form = $this->getEditForm(static::createClientAs(UserFixtures::ADMIN), $id);
        $form['ladb_qa_answer[bodyBlocks][0][body]'] = 'Le frêne aussi, bien huilé.';

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotSame('Le frêne aussi, bien huilé.', $this->findAnswer(UserFixtures::USER2)->getBodyBlocks()->first()->getBody());
    }

    /**
     * @dataProvider provideDeleters
     */
    public function testDelete(string $username, int $status): void
    {
        $answerCount = $this->findQuestion()->getAnswerCount();
        $id = $this->findAnswer(UserFixtures::USER2)->getId();

        $client = static::createClientAs($username);
        $client->request('GET', static::generatePath('core_qa_answer_delete', ['id' => $id]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_qa_question_show', ['id' => $this->findQuestion()->getSluggedId()])));
            self::assertNull(static::getEntityManager()->getRepository(Answer::class)->find($id));
            self::assertSame($answerCount - 1, $this->findQuestion()->getAnswerCount());
        } else {
            self::assertNotNull(static::getEntityManager()->getRepository(Answer::class)->find($id));
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideDeleters(): iterable
    {
        yield 'author' => [UserFixtures::USER2, 302];
        yield 'author of the question' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    /**
     * The page of an answer is the one of its question, at the answer; hidden with a draft
     * question.
     */
    public function testShow(): void
    {
        $id = $this->findAnswer(UserFixtures::USER2)->getId();

        $client = static::createClient();
        $client->request('GET', static::generatePath('core_qa_answer_show', ['id' => $id]));

        self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_qa_question_show', ['id' => $this->findQuestion()->getSluggedId()]).'#_answer_'.$id));
    }

    public function testShowOfDraftQuestion(): void
    {
        $id = $this->findAnswer(UserFixtures::USER2)->getId();
        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', static::generatePath('core_qa_question_unpublish', ['id' => self::QUESTION_ID, 'rtu' => '/']));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', static::generatePath('core_qa_answer_show', ['id' => $id]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideBestAnswerChoosers
     */
    public function testBestAnswer(string $username, int $status): void
    {
        $answer = $this->findAnswer(UserFixtures::USER2);

        $client = static::createClientAs($username);
        $client->request('GET', static::generatePath('core_qa_answer_best_create', ['id' => $answer->getId()]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        $bestAnswer = $this->findQuestion()->getBestAnswer();
        if (302 === $status) {
            self::assertTrue($client->getResponse()->isRedirect(static::generatePath('core_qa_answer_show', ['id' => $answer->getId()])));
            self::assertSame($answer->getId(), $bestAnswer->getId());
        } else {
            self::assertNull($bestAnswer);
        }
    }

    /**
     * The author of the question and the admins, not the one of the answer.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function provideBestAnswerChoosers(): iterable
    {
        yield 'author of the question' => [UserFixtures::USER, 302];
        yield 'author of the answer' => [UserFixtures::USER2, 404];
        yield 'admin' => [UserFixtures::ADMIN, 302];
    }

    public function testBestAnswerDelete(): void
    {
        $id = $this->findAnswer(UserFixtures::USER2)->getId();
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_qa_answer_best_create', ['id' => $id]));
        self::assertNotNull($this->findQuestion()->getBestAnswer());

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_qa_answer_best_delete', ['id' => $id]));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertNull($this->findQuestion()->getBestAnswer());
    }

    /**
     * An answer with a negative score cannot be the best one.
     */
    public function testBestAnswerNegative(): void
    {
        $answer = $this->findAnswer(UserFixtures::USER2);
        $answer->incrementVoteScore(-2);
        static::getEntityManager()->flush();

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_qa_answer_best_create', ['id' => $answer->getId()]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNull($this->findQuestion()->getBestAnswer());
    }

    public function testVoteUp(): void
    {
        $answer = $this->findAnswer(UserFixtures::USER2);
        [$voteScore, $positiveVoteScore] = [$answer->getVoteScore(), $answer->getPositiveVoteScore()];

        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->vote($client, $answer->getId(), 'up');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $answer = $this->findAnswer(UserFixtures::USER2);
        self::assertSame($voteScore + 1, $answer->getVoteScore());
        self::assertSame($positiveVoteScore + 1, $answer->getPositiveVoteScore());
        self::assertNotNull($this->findVote(UserFixtures::ADMIN, $answer->getId()));
    }

    /**
     * A down vote needs a reason, which becomes a comment on the answer.
     */
    public function testVoteDown(): void
    {
        $answer = $this->findAnswer(UserFixtures::USER2);
        $voteScore = $answer->getVoteScore();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->vote($client, $answer->getId(), 'down', 'Le hêtre bouge beaucoup avec l\'humidité.');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($voteScore - 1, $this->findAnswer(UserFixtures::USER2)->getVoteScore());
        $vote = $this->findVote(UserFixtures::ADMIN, $answer->getId());
        self::assertSame(-1, $vote->getScore());
        self::assertSame('Le hêtre bouge beaucoup avec l\'humidité.', $vote->getComment()->getBody());
    }

    public function testVoteDownWithoutReason(): void
    {
        $answer = $this->findAnswer(UserFixtures::USER2);

        $client = static::createClientAs(UserFixtures::ADMIN);
        $this->vote($client, $answer->getId(), 'down');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNull($this->findVote(UserFixtures::ADMIN, $answer->getId()));
    }

    /**
     * "user" turns its up vote into a down one, the answer becomes negative.
     */
    public function testVoteChange(): void
    {
        $answer = $this->findAnswer(UserFixtures::USER2);
        $voteScore = $answer->getVoteScore();

        $client = static::createClientAs(UserFixtures::USER);
        $this->vote($client, $answer->getId(), 'down', 'Finalement, le hêtre a fendu.');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($voteScore - 2, $this->findAnswer(UserFixtures::USER2)->getVoteScore());
        self::assertSame(-1, $this->findVote(UserFixtures::USER, $answer->getId())->getScore());
        self::assertSame(1, $this->findQuestion()->getNegativeAnswerCount());
    }

    public function testVoteTwice(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $this->vote($client, $this->findAnswer(UserFixtures::USER2)->getId(), 'up');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testVoteForOneself(): void
    {
        $parameters = ['entityType' => Answer::TYPE, 'entityId' => $this->findAnswer(UserFixtures::USER2)->getId(), 'way' => 'up'];

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'GET', static::generatePath('core_vote_new', $parameters));
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'POST', static::generatePath('core_vote_create', $parameters));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @dataProvider provideVoteDeleters
     */
    public function testVoteDelete(string $username, int $status): void
    {
        $answer = $this->findAnswer(UserFixtures::USER2);
        $voteScore = $answer->getVoteScore();
        $vote = $this->findVote(UserFixtures::USER, $answer->getId());

        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath('core_vote_delete', ['id' => $vote->getId()]));

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame(200 === $status ? $voteScore - 1 : $voteScore, $this->findAnswer(UserFixtures::USER2)->getVoteScore());
    }

    /**
     * Only the voter, even the admins cannot.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function provideVoteDeleters(): iterable
    {
        yield 'voter' => [UserFixtures::USER, 200];
        yield 'admin' => [UserFixtures::ADMIN, 404];
    }

    /**
     * An admin turns an answer into a comment on the question.
     */
    public function testConvertToComment(): void
    {
        $answer = $this->findAnswer(UserFixtures::USER2);
        $id = $answer->getId();
        $body = $answer->getBodyBlocks()->first()->getBody();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', static::generatePath('core_qa_answer_admin_converttocomment', ['id' => $id]));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        self::assertNull(static::getEntityManager()->getRepository(Answer::class)->find($id));
        $comment = static::getEntityManager()->getRepository(Comment::class)->findOneBy(['entityType' => Question::TYPE, 'entityId' => self::QUESTION_ID], ['id' => 'DESC']);
        self::assertSame(UserFixtures::USER2, $comment->getUser()->getUsername());
        self::assertStringContainsString($body, $comment->getBody());
    }

    public function testConvertToCommentAsAuthor(): void
    {
        $id = $this->findAnswer(UserFixtures::USER2)->getId();

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', static::generatePath('core_qa_answer_admin_converttocomment', ['id' => $id]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->findAnswer(UserFixtures::USER2));
    }

    private function submitNewForm(Client $client, string $body): void
    {
        $form = $this->getNewForm($client);
        $form['ladb_qa_answer[bodyBlocks][0][body]'] = $body;

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
    }

    private function getNewForm(Client $client): Form
    {
        $crawler = static::xhr($client, 'GET', static::generatePath('core_qa_answer_new', ['id' => self::QUESTION_ID]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_qa_answer_create', ['id' => self::QUESTION_ID])))->form();
    }

    private function getEditForm(Client $client, int $id): Form
    {
        $crawler = static::xhr($client, 'GET', static::generatePath('core_qa_answer_edit', ['id' => $id]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_qa_answer_update', ['id' => $id])))->form();
    }

    /**
     * Votes as the dialog of the vote widget does: its form, then its submission.
     */
    private function vote(Client $client, int $answerId, string $way, string $reason = ''): void
    {
        $crawler = static::xhr($client, 'GET', static::generatePath('core_vote_new', ['entityType' => Answer::TYPE, 'entityId' => $answerId, 'way' => $way]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form')->form();
        $form['ladb_core_new_vote[body]'] = $reason;

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
    }


    private function findQuestion(): Question
    {
        return static::getEntityManager()->getRepository(Question::class)->find(self::QUESTION_ID);
    }

    private function findAnswer(string $username): ?Answer
    {
        return static::getEntityManager()->getRepository(Answer::class)->findOneBy(['question' => self::QUESTION_ID, 'user' => static::findUser($username)]);
    }

    private function findVote(string $username, int $answerId): ?Vote
    {
        return static::getEntityManager()->getRepository(Vote::class)->findOneBy(['entityType' => Answer::TYPE, 'entityId' => $answerId, 'user' => static::findUser($username)]);
    }
}
