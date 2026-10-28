<?php

namespace Tests\Workflow;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Workflow\Task;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The tasks of the workspace of a workflow, which its JS reads and changes over HTTP; each change
 * goes to the Mercure hub, for the other workspaces open on the workflow (InMemoryHub). The
 * workflow of the fixtures, of "user", has the task "Débiter les planches" (workable), then the
 * task "Poncer et huiler" (pending), with label "Débit" and part "Plateau" on the first one.
 */
final class TaskTest extends WebTestCase
{
    use XhrTrait;

    private const WORKFLOW_ID = 1;

    private const FIRST = 'Débiter les planches';
    private const SECOND = 'Poncer et huiler';

    /**
     * The list the workspace reads, for anyone; the durations are for its author and the admins.
     *
     * @dataProvider provideUsers
     */
    public function testList(?string $username, bool $durationsHidden): void
    {
        $this->findTask(self::FIRST)->setEstimatedDuration(3600);
        static::getEntityManager()->flush();

        $client = null === $username ? static::createClient() : static::createClientAs($username);
        static::xhr($client, 'GET', $this->path('list'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        $json = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['success', 'workflowInfos', 'taskInfos', 'connections'], array_keys($json));
        self::assertTrue($json['success']);
        self::assertSame(['statusPanel'], array_keys($json['workflowInfos']));
        $first = $this->findTask(self::FIRST);
        $second = $this->findTask(self::SECOND);
        self::assertEqualsCanonicalizing([
            ['id' => $first->getId(), 'status' => Task::STATUS_WORKABLE],
            ['id' => $second->getId(), 'status' => Task::STATUS_PENDING],
        ], array_map(fn (array $info) => ['id' => $info['id'], 'status' => $info['status']], $json['taskInfos']));
        foreach ($json['taskInfos'] as $info) {
            self::assertSame(['id', 'status', 'row', 'widget'], array_keys($info));
        }
        self::assertSame([['from' => $first->getId(), 'to' => $second->getId()]], $json['connections']);
        self::assertSame(!$durationsHidden, str_contains(implode('', array_column($json['taskInfos'], 'widget')), 'ladb-duration'));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function provideUsers(): iterable
    {
        yield 'anonymous' => [null, true];
        yield 'author' => [UserFixtures::USER, false];
        yield 'other user' => [UserFixtures::USER2, true];
        yield 'admin' => [UserFixtures::ADMIN, false];
    }

    /**
     * @dataProvider provideActions
     */
    public function testActionSendsAnonymousToLogin(string $action): void
    {
        $client = static::createClient();
        static::xhr($client, 'POST', $this->path($action));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * Only the author of the workflow and the admins change its tasks.
     *
     * @dataProvider provideActions
     */
    public function testActionAsOtherUser(string $action): void
    {
        $first = $this->findTask(self::FIRST);
        $second = $this->findTask(self::SECOND);

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, 'POST', $this->path($action), [
            'taskId' => $first->getId(),
            'taskId1' => $first->getId(),
            'positionLeft1' => 100,
            'status' => Task::STATUS_DONE,
            'sourceTaskId' => $first->getId(),
            'targetTaskId' => $second->getId(),
        ]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame([], $client->getContainer()->get('tests.mercure_hub')->getUpdates());
        self::assertSame(2, $this->findWorkflow()->getTaskCount());
        self::assertSame(Task::STATUS_WORKABLE, $this->findTask(self::FIRST)->getStatus());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideActions(): iterable
    {
        yield 'new' => ['new'];
        yield 'create' => ['create'];
        yield 'edit' => ['edit'];
        yield 'update' => ['update'];
        yield 'position update' => ['position_update'];
        yield 'status update' => ['status_update'];
        yield 'delete' => ['delete'];
        yield 'connection create' => ['connection_create'];
        yield 'connection delete' => ['connection_delete'];
    }

    /**
     * @dataProvider provideEditors
     */
    public function testCreate(string $username): void
    {
        $client = static::createClientAs($username);
        $crawler = static::xhr($client, 'GET', $this->path('new', ['positionLeft' => 300, 'positionTop' => 40]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form')->form([
            'ladb_workflow_task[title]' => 'Assembler le piètement',
            'ladb_workflow_task[estimatedDuration]' => '3600',
        ]);

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        // The values of the form, as strings, until read again from the database
        static::getEntityManager()->clear();
        $task = $this->findTask('Assembler le piètement');
        self::assertSame(Task::STATUS_WORKABLE, $task->getStatus());
        self::assertSame([300, 40], [$task->getPositionLeft(), $task->getPositionTop()]);
        $workflow = $this->findWorkflow();
        self::assertSame(3, $workflow->getTaskCount());
        self::assertSame(3600, $workflow->getEstimatedDuration());

        $data = $this->getPushedData($client);
        self::assertSame(['workflowInfos', 'createdTaskInfos'], array_keys($data));
        self::assertCount(1, $data['createdTaskInfos']);
        self::assertSame(['id', 'status', 'row', 'widget'], array_keys($data['createdTaskInfos'][0]));
        self::assertSame([$task->getId(), Task::STATUS_WORKABLE], [$data['createdTaskInfos'][0]['id'], $data['createdTaskInfos'][0]['status']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEditors(): iterable
    {
        yield 'author' => [UserFixtures::USER];
        yield 'admin' => [UserFixtures::ADMIN];
    }

    /**
     * Created from another task, the task follows it: pending until it is done.
     */
    public function testCreateAfterTask(): void
    {
        $first = $this->findTask(self::FIRST);
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = static::xhr($client, 'GET', $this->path('new', ['sourceTaskId' => $first->getId()]));
        $form = $crawler->filter('form')->form(['ladb_workflow_task[title]' => 'Assembler le piètement']);

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        $task = $this->findTask('Assembler le piètement');
        self::assertSame(Task::STATUS_PENDING, $task->getStatus());
        $data = $this->getPushedData($client);
        self::assertSame(['createdConnections', 'createdTaskInfos'], array_keys($data));
        self::assertSame([['from' => $first->getId(), 'to' => $task->getId()]], $data['createdConnections']);
    }

    /**
     * An invalid form comes back, as HTML.
     */
    public function testCreateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = static::xhr($client, 'GET', $this->path('new'));
        $form = $crawler->filter('form')->form();

        $crawler = static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form'));
        self::assertSame([], $client->getContainer()->get('tests.mercure_hub')->getUpdates());
        self::assertSame(2, $this->findWorkflow()->getTaskCount());
    }

    /**
     * @dataProvider provideEditors
     */
    public function testUpdate(string $username): void
    {
        $first = $this->findTask(self::FIRST);
        $client = static::createClientAs($username);
        $crawler = static::xhr($client, 'GET', $this->path('edit', ['taskId' => $first->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form')->form([
            'ladb_workflow_task[title]' => 'Débiter et corroyer les planches',
            'ladb_workflow_task[duration]' => '7200',
        ]);

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        static::getEntityManager()->clear();
        $task = $this->findTask('Débiter et corroyer les planches');
        self::assertSame($first->getId(), $task->getId());
        self::assertSame(7200, $task->getDuration());
        self::assertSame(7200, $this->findWorkflow()->getDuration());
        $data = $this->getPushedData($client);
        self::assertSame(['workflowInfos', 'updatedTaskInfos'], array_keys($data));
        self::assertSame(['id', 'status', 'box'], array_keys($data['updatedTaskInfos'][0]));
    }

    /**
     * Done, the task makes the next one workable.
     */
    public function testStatusUpdate(): void
    {
        $first = $this->findTask(self::FIRST);
        $second = $this->findTask(self::SECOND);

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $this->path('status_update'), ['taskId' => $first->getId(), 'status' => Task::STATUS_DONE]);

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        self::assertSame(Task::STATUS_DONE, $this->findTask(self::FIRST)->getStatus());
        self::assertNotNull($this->findTask(self::FIRST)->getFinishedAt());
        self::assertSame(Task::STATUS_WORKABLE, $this->findTask(self::SECOND)->getStatus());
        self::assertSame(1, $this->findWorkflow()->getDoneTaskCount());
        $data = $this->getPushedData($client);
        self::assertSame(['workflowInfos', 'updatedTaskInfos'], array_keys($data));
        self::assertSame([[$first->getId(), Task::STATUS_DONE], [$second->getId(), Task::STATUS_WORKABLE]], array_map(fn (array $info) => [$info['id'], $info['status']], $data['updatedTaskInfos']));
    }

    /**
     * Running, the task gets a run.
     */
    public function testStatusUpdateRunning(): void
    {
        $first = $this->findTask(self::FIRST);

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $this->path('status_update'), ['taskId' => $first->getId(), 'status' => Task::STATUS_RUNNING]);

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        $task = $this->findTask(self::FIRST);
        self::assertSame(Task::STATUS_RUNNING, $task->getStatus());
        self::assertCount(1, $task->getRuns());
        self::assertSame(1, $this->findWorkflow()->getRunningTaskCount());
    }

    public function testStatusUpdateInvalid(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $this->path('status_update'), ['taskId' => $this->findTask(self::FIRST)->getId(), 'status' => 9]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(Task::STATUS_WORKABLE, $this->findTask(self::FIRST)->getStatus());
    }

    public function testPositionUpdate(): void
    {
        $first = $this->findTask(self::FIRST);
        $second = $this->findTask(self::SECOND);

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $this->path('position_update'), [
            'taskId1' => $first->getId(), 'positionLeft1' => 100, 'positionTop1' => 200,
            'taskId2' => $second->getId(), 'positionTop2' => 300,
        ]);

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        self::assertSame([100, 200], [$this->findTask(self::FIRST)->getPositionLeft(), $this->findTask(self::FIRST)->getPositionTop()]);
        self::assertSame([20, 300], [$this->findTask(self::SECOND)->getPositionLeft(), $this->findTask(self::SECOND)->getPositionTop()]);
        self::assertSame(['movedTaskInfos' => [
            ['id' => $first->getId(), 'positionLeft' => 100, 'positionTop' => 200, 'sortIndex' => 200],
            ['id' => $second->getId(), 'positionLeft' => 20, 'positionTop' => 300, 'sortIndex' => 300],
        ]], $this->getPushedData($client));
    }

    /**
     * Without the first one, the next task is workable.
     */
    public function testDelete(): void
    {
        $first = $this->findTask(self::FIRST);
        $second = $this->findTask(self::SECOND);

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $this->path('delete'), ['taskId' => $first->getId()]);

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        static::getEntityManager()->clear();
        self::assertNull(static::getEntityManager()->getRepository(Task::class)->find($first->getId()));
        self::assertSame(Task::STATUS_WORKABLE, $this->findTask(self::SECOND)->getStatus());
        self::assertSame(1, $this->findWorkflow()->getTaskCount());
        $data = $this->getPushedData($client);
        self::assertSame(['workflowInfos', 'updatedTaskInfos', 'deletedTaskId'], array_keys($data));
        self::assertSame($first->getId(), $data['deletedTaskId']);
        self::assertSame([$second->getId()], array_column($data['updatedTaskInfos'], 'id'));
    }

    /**
     * Unlinked, the next task is workable; linked again, pending.
     */
    public function testConnection(): void
    {
        $first = $this->findTask(self::FIRST);
        $second = $this->findTask(self::SECOND);
        $parameters = ['sourceTaskId' => $first->getId(), 'targetTaskId' => $second->getId()];
        $connections = [['from' => $first->getId(), 'to' => $second->getId()]];

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $this->path('connection_delete'), $parameters);

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        self::assertSame(Task::STATUS_WORKABLE, $this->findTask(self::SECOND)->getStatus());
        $data = $this->getPushedData($client);
        self::assertSame($connections, $data['deletedConnections']);
        self::assertSame([$second->getId()], array_column($data['updatedTaskInfos'], 'id'));

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $this->path('connection_create'), $parameters);

        self::assertSame(['success' => true], json_decode($client->getResponse()->getContent(), true));
        self::assertSame(Task::STATUS_PENDING, $this->findTask(self::SECOND)->getStatus());
        $data = $this->getPushedData($client);
        self::assertSame($connections, $data['createdConnections']);
        self::assertSame([$second->getId()], array_column($data['updatedTaskInfos'], 'id'));
    }

    /**
     * A task of another workflow is not found through this one.
     */
    public function testTaskOfAnotherWorkflow(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_workflow_copy', ['id' => self::WORKFLOW_ID]));
        $copy = static::getEntityManager()->getRepository(Workflow::class)->findOneBy([], ['id' => 'DESC']);
        $task = $this->findTask(self::FIRST, $copy->getId());

        $client = static::createClientAs(UserFixtures::USER);
        static::xhr($client, 'POST', $this->path('status_update'), ['taskId' => $task->getId(), 'status' => Task::STATUS_DONE]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(Task::STATUS_WORKABLE, $this->findTask(self::FIRST, $copy->getId())->getStatus());
    }

    private function path(string $action, array $parameters = []): string
    {
        $route = 'list' === $action ? 'core_workflow_task_list' : 'core_workflow_task_'.$action;

        return static::generatePath($route, ['id' => self::WORKFLOW_ID] + $parameters);
    }

    private function findWorkflow(): Workflow
    {
        return static::getEntityManager()->getRepository(Workflow::class)->find(self::WORKFLOW_ID);
    }

    private function findTask(string $title, int $workflowId = self::WORKFLOW_ID): Task
    {
        $task = static::getEntityManager()->getRepository(Task::class)->findOneBy(['workflow' => $workflowId, 'title' => $title]);
        if (null === $task) {
            throw new \LogicException(\sprintf('The workflow %d has no task "%s".', $workflowId, $title));
        }

        return $task;
    }

    /**
     * The data the last request of $client pushed to the workspaces of the workflow.
     *
     * @return array<string, mixed>
     */
    private function getPushedData(Client $client): array
    {
        $updates = $client->getContainer()->get('tests.mercure_hub')->getUpdates();
        self::assertCount(1, $updates);
        // The workflow of "user" (id 2)
        self::assertSame(['https://lairdubois.fr/workflows/1', 'https://lairdubois.fr/users/2/workflows/1'], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());

        return json_decode($updates[0]->getData(), true);
    }
}
