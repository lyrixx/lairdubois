<?php

namespace Tests\Workflow;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Workflow\Label;
use Ladb\CoreBundle\Entity\Workflow\Part;
use Ladb\CoreBundle\Entity\Workflow\Task;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Symfony\Bundle\FrameworkBundle\Client;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The labels and the parts of a workflow, which its workspace edits in modals (XHR). The
 * workflow of the fixtures, of "user", has the label "Débit" and the part "Plateau" (count 1),
 * both on its task "Débiter les planches".
 */
final class LabelAndPartTest extends WebTestCase
{
    use XhrTrait;

    private const WORKFLOW_ID = 1;

    private const TASK = 'Débiter les planches';

    /**
     * Only the author of the workflow and the admins see and change them.
     *
     * @dataProvider provideActions
     */
    public function testActionRights(string $kind, string $action, string $method): void
    {
        $item = $this->findItem($kind);
        $parameters = ['id' => \in_array($action, ['new', 'create', 'list'], true) ? self::WORKFLOW_ID : $item->getId()];

        $client = static::createClient();
        static::xhr($client, $method, static::generatePath(\sprintf('core_workflow_%s_%s', $kind, $action), $parameters));
        if ('list' === $action) {
            // Not behind the firewall (access_control of security.yml)
            self::assertSame(404, $client->getResponse()->getStatusCode());
        } else {
            self::assertTrue($client->getResponse()->isRedirect());
            self::assertStringEndsWith('/login', $client->getResponse()->headers->get('Location'));
        }

        $client = static::createClientAs(UserFixtures::USER2);
        static::xhr($client, $method, static::generatePath(\sprintf('core_workflow_%s_%s', $kind, $action), $parameters));
        self::assertSame(404, $client->getResponse()->getStatusCode());

        self::assertNotNull($this->findItem($kind));
        self::assertSame([], $client->getContainer()->get('tests.wamp_pusher')->getMessages());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideActions(): iterable
    {
        foreach (['label', 'part'] as $kind) {
            yield $kind.' list' => [$kind, 'list', 'GET'];
            yield $kind.' new' => [$kind, 'new', 'GET'];
            yield $kind.' create' => [$kind, 'create', 'POST'];
            yield $kind.' edit' => [$kind, 'edit', 'GET'];
            yield $kind.' update' => [$kind, 'update', 'POST'];
            yield $kind.' delete' => [$kind, 'delete', 'GET'];
        }
    }

    /**
     * @dataProvider provideEditors
     */
    public function testList(string $kind, string $username): void
    {
        $client = static::createClientAs($username);
        $crawler = static::xhr($client, 'GET', static::generatePath(\sprintf('core_workflow_%s_list', $kind), ['id' => self::WORKFLOW_ID]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('label' === $kind ? 'Débit' : 'Plateau', $crawler->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEditors(): iterable
    {
        foreach (['label', 'part'] as $kind) {
            yield $kind.', author' => [$kind, UserFixtures::USER];
            yield $kind.', admin' => [$kind, UserFixtures::ADMIN];
        }
    }

    /**
     * @dataProvider provideEditors
     */
    public function testCreate(string $kind, string $username): void
    {
        $client = static::createClientAs($username);
        $crawler = static::xhr($client, 'GET', static::generatePath(\sprintf('core_workflow_%s_new', $kind), ['id' => self::WORKFLOW_ID]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form')->form('label' === $kind
            ? ['ladb_workflow_label[name]' => 'Finition', 'ladb_workflow_label[color]' => '#e67e22']
            : ['ladb_workflow_part[number]' => 'B', 'ladb_workflow_part[name]' => 'Pied', 'ladb_workflow_part[count]' => '4']);

        $crawler = static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('form'));
        $workflow = static::getEntityManager()->getRepository(Workflow::class)->find(self::WORKFLOW_ID);
        if ('label' === $kind) {
            self::assertSame(['Débit', 'Finition'], array_values($workflow->getLabels()->map(fn (Label $label) => $label->getName())->toArray()));
        } else {
            self::assertSame(['Plateau', 'Pied'], array_values($workflow->getParts()->map(fn (Part $part) => $part->getName())->toArray()));
        }
        self::assertSame([], $client->getContainer()->get('tests.wamp_pusher')->getMessages());
    }

    /**
     * The workspaces get the boxes of the tasks with the label or the part.
     *
     * @dataProvider provideEditors
     */
    public function testUpdate(string $kind, string $username): void
    {
        $item = $this->findItem($kind);
        $client = static::createClientAs($username);
        $crawler = static::xhr($client, 'GET', static::generatePath(\sprintf('core_workflow_%s_edit', $kind), ['id' => $item->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form')->form('label' === $kind
            ? ['ladb_workflow_label[name]' => 'Débit et corroyage']
            : ['ladb_workflow_part[count]' => '2']);

        $crawler = static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('form'));
        static::getEntityManager()->clear();
        if ('label' === $kind) {
            self::assertSame('Débit et corroyage', $this->findItem($kind)->getName());
        } else {
            self::assertSame(2, $this->findItem($kind)->getCount());
            self::assertSame(2, $this->findTask()->getPartCount());
        }
        $this->assertPushedBoxOfTheTask($client);
    }

    /**
     * @dataProvider provideEditors
     */
    public function testDelete(string $kind, string $username): void
    {
        $item = $this->findItem($kind);
        $client = static::createClientAs($username);
        static::xhr($client, 'GET', static::generatePath(\sprintf('core_workflow_%s_delete', $kind), ['id' => $item->getId()]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        static::getEntityManager()->clear();
        self::assertNull($this->findItem($kind));
        self::assertCount(0, 'label' === $kind ? $this->findTask()->getLabels() : $this->findTask()->getParts());
        $this->assertPushedBoxOfTheTask($client);
    }

    /**
     * @return Label|Part|null
     */
    private function findItem(string $kind): ?object
    {
        return 'label' === $kind
            ? static::getEntityManager()->getRepository(Label::class)->findOneBy(['workflow' => self::WORKFLOW_ID])
            : static::getEntityManager()->getRepository(Part::class)->findOneBy(['workflow' => self::WORKFLOW_ID]);
    }

    private function findTask(): Task
    {
        return static::getEntityManager()->getRepository(Task::class)->findOneBy(['workflow' => self::WORKFLOW_ID, 'title' => self::TASK]);
    }

    private function assertPushedBoxOfTheTask(Client $client): void
    {
        $messages = $client->getContainer()->get('tests.wamp_pusher')->getMessages();
        self::assertCount(1, $messages);
        self::assertSame('workflow_show_topic', $messages[0]['routeName']);
        self::assertSame(['id' => self::WORKFLOW_ID], $messages[0]['routeParameters']);
        self::assertSame(['updatedTaskInfos'], array_keys($messages[0]['data']));
        self::assertSame([$this->findTask()->getId()], array_column($messages[0]['data']['updatedTaskInfos'], 'id'));
        self::assertSame(['id', 'box'], array_keys($messages[0]['data']['updatedTaskInfos'][0]));
    }
}
