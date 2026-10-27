<?php

namespace Tests\User;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Entity\Core\MemberInvitation;
use Ladb\CoreBundle\Entity\Core\MemberRequest;
use Ladb\CoreBundle\Entity\Core\Registration;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Fos\UserManager;
use Tests\Double\OfflineLocalisableUtils;
use Tests\WebTestCase;

/**
 * Teams ("collectifs"): creation, settings, invitations, membership requests, leaving. In the
 * fixtures, user and user2 are the members of team, which invited admin.
 */
final class TeamTest extends WebTestCase
{
    public function testCreateTeam(): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', '/collectifs/new');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->enableProfiler();
        $client->submit($crawler->filter('form[name="ladb_userteam"]')->form(['ladb_userteam[username]' => 'ebenistes']));

        self::assertSame('/@ebenistes', $client->getResponse()->headers->get('Location'));
        $messages = static::getSentEmails($client);
        self::assertCount(1, $messages);
        self::assertSame(['contact@lairdubois.fr'], static::getEmailRecipients($messages[0]));

        $team = static::findUser('ebenistes');
        self::assertTrue($team->getIsTeam());
        self::assertContains('ROLE_TEAM', $team->getRoles());
        self::assertNotNull($team->getAvatar());
        self::assertTrue($this->isMember($team, UserFixtures::USER));
        $registration = static::getEntityManager()->getRepository(Registration::class)->findOneBy(['user' => $team]);
        self::assertSame(UserFixtures::USER, $registration->getCreator()->getUsername());

        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testCreateTeamWithUnconfirmedEmail(): void
    {
        static::getEntityManager()->getConnection()->update('tbl_core_user', ['email_confirmed' => 0], ['username_canonical' => UserFixtures::USER]);
        $client = static::createClientAs(UserFixtures::USER);

        $client->request('GET', '/collectifs/new');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('POST', '/collectifs/create', ['ladb_userteam' => ['username' => 'ebenistes']]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNull(static::getEntityManager()->getRepository(User::class)->findOneBy(['usernameCanonical' => 'ebenistes']));
    }

    public function testCreateTeamAnonymous(): void
    {
        $client = static::createClient();

        $client->request('GET', '/collectifs/new');

        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
    }

    public function testEditTeamSettingsAsMember(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $crawler = $client->request('GET', '/@team/parametres');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->submit($crawler->filter('form[name="ladb_userteamsettings"]')->form([
            'ladb_userteamsettings[displayname]' => 'Atelier Partagé',
            'ladb_userteamsettings[location]' => 'Bordeaux, France',
        ]));

        self::assertSame('/@team', $client->getResponse()->headers->get('Location'));
        $team = static::findUser(UserFixtures::TEAM);
        self::assertSame('Atelier Partagé', $team->getDisplayname());
        self::assertSame(OfflineLocalisableUtils::ADDRESSES['Bordeaux, France']['latitude'], $team->getLatitude());
    }

    public function testEditTeamSettingsAsAdmin(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);

        $client->request('GET', '/@team/parametres');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTeamPages(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/@team');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@user"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@user2"]')->count());

        // Invitations and requests: for the members and the admins only
        $client->request('GET', '/@team/invitations');
        self::assertSame('/@team', $client->getResponse()->headers->get('Location'));

        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', '/@team/invitations');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@admin"]')->count());
        $client->request('GET', '/@team/demandes');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $crawler = $client->request('GET', '/@user/collectifs');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="/@team"]')->count());
    }

    public function testInvite(): void
    {
        $this->createUser('apprenti');
        $client = static::createClientAs(UserFixtures::USER);
        $team = static::findUser(UserFixtures::TEAM);
        $client->request('GET', "/members/{$team->getId()}/invitation/new");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', "/members/{$team->getId()}/invitation/create", ['recipients' => 'apprenti,admin,user2']);

        self::assertSame('/@team/invitations', $client->getResponse()->headers->get('Location'));
        self::assertNotNull($this->findInvitation('apprenti'));
        // A webpush notification to the recipient
        $messages = $client->getContainer()->get('tests.webpush_notification_producer')->getMessages();
        self::assertCount(1, $messages);

        // admin is already invited, user2 is already a member: the page shows the first error only
        self::assertNull($this->findInvitation(UserFixtures::USER2));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('admin est déjà invité.', $crawler->filter('.alert-error')->text(null, false));
        self::assertStringContainsString('1 invitation envoyée', $crawler->filter('.alert-success')->text(null, false));
    }

    public function testInviteAsNotMember(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $team = static::findUser(UserFixtures::TEAM);

        $client->request('GET', "/members/{$team->getId()}/invitation/new");
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('POST', "/members/{$team->getId()}/invitation/create", ['recipients' => 'admin']);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testAcceptInvitation(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $invitation = $this->findInvitation(UserFixtures::ADMIN);

        $client->request('GET', "/members/invitation/{$invitation->getId()}/accept");

        self::assertSame('/@team', $client->getResponse()->headers->get('Location'));
        self::assertTrue($this->isMember(static::findUser(UserFixtures::TEAM), UserFixtures::ADMIN));
        self::assertNull($this->findInvitation(UserFixtures::ADMIN));
        self::assertSame(3, static::findUser(UserFixtures::TEAM)->getMeta()->getMemberCount());
    }

    public function testAcceptInvitationOfAnotherUser(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $invitation = $this->findInvitation(UserFixtures::ADMIN);

        $client->request('GET', "/members/invitation/{$invitation->getId()}/accept");

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->findInvitation(UserFixtures::ADMIN));
    }

    public function testDeclineInvitation(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $invitationId = $this->findInvitation(UserFixtures::ADMIN)->getId();

        $client->request('GET', "/members/invitation/{$invitationId}/delete", [], [], ['HTTP_REFERER' => 'https://lairdubois.test/@team']);

        self::assertSame('https://lairdubois.test/@team', $client->getResponse()->headers->get('Location'));
        self::assertNull($this->findInvitation(UserFixtures::ADMIN));
        self::assertFalse($this->isMember(static::findUser(UserFixtures::TEAM), UserFixtures::ADMIN));
    }

    public function testDeclineInvitationWithoutReferer(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $invitationId = $this->findInvitation(UserFixtures::ADMIN)->getId();

        $client->request('GET', "/members/invitation/{$invitationId}/delete");

        self::assertSame('/@team', $client->getResponse()->headers->get('Location'));
        self::assertNull($this->findInvitation(UserFixtures::ADMIN));
    }

    public function testRequestMembership(): void
    {
        $this->createUser('apprenti');
        $client = static::createClientAs('apprenti');
        $team = static::findUser(UserFixtures::TEAM);
        $client->request('GET', "/members/{$team->getId()}/request/new");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', "/members/{$team->getId()}/request/create");

        self::assertSame('/@team', $client->getResponse()->headers->get('Location'));
        $request = $this->findRequest('apprenti');
        self::assertNotNull($request);

        // A member accepts it
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', "/members/request/{$request->getId()}/accept");

        self::assertSame('/@team', $client->getResponse()->headers->get('Location'));
        self::assertTrue($this->isMember(static::findUser(UserFixtures::TEAM), 'apprenti'));
        self::assertNull($this->findRequest('apprenti'));
    }

    public function testRequestMembershipWhenInvited(): void
    {
        $client = static::createClientAs(UserFixtures::ADMIN);
        $team = static::findUser(UserFixtures::TEAM);

        $client->request('GET', "/members/{$team->getId()}/request/create");

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testRefuseRequest(): void
    {
        $this->createUser('apprenti');
        $client = static::createClientAs('apprenti');
        $team = static::findUser(UserFixtures::TEAM);
        $client->request('GET', "/members/{$team->getId()}/request/create");
        $requestId = $this->findRequest('apprenti')->getId();

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', "/members/request/{$requestId}/delete", [], [], ['HTTP_REFERER' => 'https://lairdubois.test/@team/demandes']);

        self::assertSame('https://lairdubois.test/@team/demandes', $client->getResponse()->headers->get('Location'));
        self::assertNull($this->findRequest('apprenti'));
        self::assertFalse($this->isMember(static::findUser(UserFixtures::TEAM), 'apprenti'));
    }

    public function testRefuseRequestWithoutReferer(): void
    {
        $this->createUser('apprenti');
        $client = static::createClientAs('apprenti');
        $team = static::findUser(UserFixtures::TEAM);
        $client->request('GET', "/members/{$team->getId()}/request/create");
        $requestId = $this->findRequest('apprenti')->getId();

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', "/members/request/{$requestId}/delete");

        self::assertSame('/@team', $client->getResponse()->headers->get('Location'));
        self::assertNull($this->findRequest('apprenti'));
    }

    public function testListSessionTeams(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $crawler = $client->request('GET', '/members/', ['title-trans-key' => 'wonder.creation.new', 'route' => 'core_creation_new'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('[onclick*="/creations/new?owner=team"]')->count());
    }

    public function testListSessionTeamsWithoutRoute(): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $client->request('GET', '/members/', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * A route that the site never sends (forged URL): unknown, or with parameters that the list
     * does not give.
     *
     * @dataProvider provideInvalidRoutes
     */
    public function testListSessionTeamsWithInvalidRoute(string $route): void
    {
        $client = static::createClientAs(UserFixtures::USER);

        $client->request('GET', '/members/', ['title-trans-key' => 'wonder.creation.new', 'route' => $route], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public static function provideInvalidRoutes(): iterable
    {
        yield 'unknown' => ['core_unknown'];
        yield 'with parameters' => ['core_creation_show'];
    }

    public function testListSessionTeamsAnonymous(): void
    {
        $client = static::createClient();

        $client->request('GET', '/members/');

        self::assertSame('https://lairdubois.test/login', $client->getResponse()->headers->get('Location'));
    }

    public function testLeaveTeam(): void
    {
        $client = static::createClientAs(UserFixtures::USER2);
        $team = static::findUser(UserFixtures::TEAM);

        $client->request('GET', "/members/{$team->getId()}/delete");

        self::assertSame('/@team', $client->getResponse()->headers->get('Location'));
        self::assertFalse($this->isMember(static::findUser(UserFixtures::TEAM), UserFixtures::USER2));
        self::assertSame(1, static::findUser(UserFixtures::TEAM)->getMeta()->getMemberCount());

        // No more access to the settings of the team
        $client->request('GET', '/@team/parametres');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        // The last member cannot leave
        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', "/members/{$team->getId()}/delete");
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertTrue($this->isMember(static::findUser(UserFixtures::TEAM), UserFixtures::USER));
    }

    private function createUser(string $username): User
    {
        static::bootKernel();
        $userManager = static::$container->get(UserManager::NAME);
        $user = $userManager->createUser();
        $user->setUsername($username);
        $user->setDisplayname(ucfirst($username));
        $user->setEmail($username.'@lairdubois.test');
        $user->setEmailConfirmed(true);
        $user->setPlainPassword($username);
        $user->setEnabled(true);
        $userManager->updateUser($user);

        return $user;
    }

    private function isMember(User $team, string $username): bool
    {
        return static::getEntityManager()->getRepository(Member::class)->existsByTeamAndUser($team, static::findUser($username));
    }

    private function findInvitation(string $recipient): ?MemberInvitation
    {
        return static::getEntityManager()->getRepository(MemberInvitation::class)->findOneBy(['team' => static::findUser(UserFixtures::TEAM), 'recipient' => static::findUser($recipient)]);
    }

    private function findRequest(string $sender): ?MemberRequest
    {
        return static::getEntityManager()->getRepository(MemberRequest::class)->findOneBy(['team' => static::findUser(UserFixtures::TEAM), 'sender' => static::findUser($sender)]);
    }
}
