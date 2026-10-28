<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Manager\Core\UserManager;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Utils\WebpushNotificationUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Entity\Core\MemberRequest;
use Ladb\CoreBundle\Manager\Core\MemberInvitationManager;
use Ladb\CoreBundle\Manager\Core\MemberManager;
use Ladb\CoreBundle\Manager\Core\MemberRequestManager;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Entity\Core\MemberInvitation;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/members')]
class MemberController extends AbstractController {

	private function _retrieveTeam($teamId, EntityManagerInterface $om) {
		$userRepository = $om->getRepository(User::CLASS_NAME);

		$team = $userRepository->findOneById($teamId);
		if (is_null($team)) {
			throw $this->createNotFoundException('Unable to find Team entity (id='.$teamId.').');
		}
		if (!$team->isEnabled()) {
			throw $this->createNotFoundException('Team not enabled');
		}

		return $team;
	}

	/////
	/**
	 * @Template("@LadbCore/Core/Member/invitation-new.html.twig")
	 */
	#[Route(path: '/{teamId}/invitation/new', requirements: ['teamId' => '\d+'], name: 'core_member_invitation_new')]
	public function newInvitationAction($teamId, EntityManagerInterface $om) {

		$team = $this->_retrieveTeam($teamId, $om);

		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		if (!$memberRepository->existsByTeamAndUser($team, $this->getUser())) {
			throw $this->createNotFoundException('Not allowed (core_member_invitation_new)');
		}

		return array(
			'team' => $team,
		);
	}

	#[Route(path: '/{teamId}/invitation/create', requirements: ['teamId' => '\d+'], name: 'core_member_invitation_create')]
	public function createInvitationAction(Request $request, $teamId, MemberInvitationManager $memberInvitationManager, EntityManagerInterface $om, UserManager $userManager, WebpushNotificationUtils $webpushNotificationUtils) {

		$team = $this->_retrieveTeam($teamId, $om);

		$this->createLock('core_member_invitation_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$memberInvitationRepository = $om->getRepository(MemberInvitation::CLASS_NAME);
		$memberRequestRepository = $om->getRepository(MemberRequest::CLASS_NAME);
		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		if (!$memberRepository->existsByTeamAndUser($team, $this->getUser())) {
			throw $this->createNotFoundException('Not allowed (core_member_invitation_create)');
		}

		$recipientUsernames = $request->get('recipients');

		$invitationCount = 0;
		foreach (explode(',', $recipientUsernames) as $username) {

			if (strlen($username) == 0) {
				continue;
			}

			$recipient = $userManager->findUserByUsername($username);
			// Check if recipient exists or is enabled or is team
			if (is_null($recipient) || !$recipient->isEnabled() || $recipient->getIsTeam()) {
				$this->addFlash('error', '<i class="ladb-icon-warning"></i> '.$recipient.' est invalide.');
				continue;
			}
			// Check if recipient already invited
			if ($memberInvitationRepository->existsByTeamAndRecipient($team, $recipient)) {
				$this->addFlash('error', '<i class="ladb-icon-warning"></i> '.$recipient.' est déjà invité.');
				continue;
			}
			// Check if recipient already requested
			if ($memberRequestRepository->existsByTeamAndSender($team, $recipient)) {
				$this->addFlash('error', '<i class="ladb-icon-warning"></i> '.$recipient.' a déjà envoyé une demande.');
				continue;
			}
			// Check if recipient already member
			if ($memberRepository->existsByTeamAndUser($team, $recipient)) {
				$this->addFlash('error', '<i class="ladb-icon-warning"></i> '.$recipient.' est déjà membre.');
				continue;
			}

			// Create invitation
			$memberInvitation = $memberInvitationManager->create($team, $this->getUser(), $recipient, false);

			// Publish a webpush notification in queue
			$webpushNotificationUtils->enqueueNewMemberInvitationNotification($memberInvitation);

			$invitationCount++;
		}

		$om->flush();

		// Flashbag
		$this->addFlash($invitationCount > 0 ? 'success' : 'error', $invitationCount.' invitation envoyée');	// TODO

		return $this->redirect($this->generateUrl($invitationCount > 0 ? 'core_user_show_invitations' : 'core_user_show', array( 'username' => $team->getUsernameCanonical() )));
	}

	#[Route(path: '/invitation/{id}/accept', requirements: ['id' => '\d+'], name: 'core_member_invitation_accept')]
	public function acceptInvitationAction($id, FollowerUtils $followerUtils, MemberInvitationManager $memberInvitationManager, MemberManager $memberManager, EntityManagerInterface $om, SearchUtils $searchUtils) {
		$memberInvitationRepository = $om->getRepository(MemberInvitation::CLASS_NAME);

		$memberInvitation = $memberInvitationRepository->findOneById($id);
		if (is_null($memberInvitation)) {
			throw $this->createNotFoundException('Invitation entity not found (id='.$id.')');
		}
		if ($memberInvitation->getRecipient() != $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Not allowed (core_member_invitation_accept)');
		}

		// Delete invitation
		$memberInvitationManager->delete($memberInvitation);

		return $this->createAction($memberInvitation->getTeam(), $memberInvitation->getRecipient(), $followerUtils, $memberManager, $searchUtils);
	}

	#[Route(path: '/invitation/{id}/delete', requirements: ['id' => '\d+'], name: 'core_member_invitation_delete')]
	public function deleteInvitationAction(Request $request, $id, MemberInvitationManager $memberInvitationManager, EntityManagerInterface $om) {
		$memberInvitationRepository = $om->getRepository(MemberInvitation::CLASS_NAME);
		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		$memberInvitation = $memberInvitationRepository->findOneById($id);
		if (is_null($memberInvitation)) {
			throw $this->createNotFoundException('Invitation entity not found (id='.$id.')');
		}
		if ($memberInvitation->getRecipient() != $this->getUser() && !$memberRepository->existsByTeamAndUser($memberInvitation->getTeam(), $this->getUser()) && !$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Not allowed (core_member_invitation_delete)');
		}

		// Delete invitation
		$memberInvitationManager->delete($memberInvitation);

		// Flashbag
		$this->addFlash('success', 'Invitation supprimée.');

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $request->headers->get('referer');
			if (is_null($returnToUrl)) {
				$returnToUrl = $this->generateUrl('core_user_show', array( 'username' => $memberInvitation->getTeam()->getUsernameCanonical() ));
			}
		}

		return $this->redirect($returnToUrl);
	}

	/////
	/**
	 * @Template("@LadbCore/Core/Member/request-new.html.twig")
	 */
	#[Route(path: '/{teamId}/request/new', requirements: ['teamId' => '\d+'], name: 'core_member_request_new')]
	public function newRequestAction($teamId, EntityManagerInterface $om) {

		$team = $this->_retrieveTeam($teamId, $om);
		if (!$team->getMeta()->getRequestEnabled()) {
			throw $this->createNotFoundException('Not allowed - Request disabled (core_member_request_new)');
		}

		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		if ($memberRepository->existsByTeamAndUser($team, $this->getUser())) {
			throw $this->createNotFoundException('Already member (core_member_request_new)');
		}

		return array(
			'team' => $team,
		);
	}

	#[Route(path: '/{teamId}/request/create', requirements: ['teamId' => '\d+'], name: 'core_member_request_create')]
	public function createRequestAction(Request $request, $teamId, MemberRequestManager $memberRequestManager, EntityManagerInterface $om) {

		$team = $this->_retrieveTeam($teamId, $om);
		if (!$team->getMeta()->getRequestEnabled()) {
			throw $this->createNotFoundException('Not allowed - Request disabled (core_member_request_create)');
		}

		$this->createLock('core_member_request_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$memberInvitationRepository = $om->getRepository(MemberInvitation::CLASS_NAME);
		$memberRequestRepository = $om->getRepository(MemberRequest::CLASS_NAME);
		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		if ($memberRepository->existsByTeamAndUser($team, $this->getUser())) {
			throw $this->createNotFoundException('Not allowed - Already member (core_member_request_create)');
		}
		if ($memberInvitationRepository->existsByTeamAndRecipient($team, $this->getUser())) {
			throw $this->createNotFoundException('Not allowed - Already invited (core_member_request_create)');
		}
		if ($memberRequestRepository->existsByTeamAndSender($team, $this->getUser())) {
			throw $this->createNotFoundException('Not allowed - Already requested (core_member_request_create)');
		}

		// Create request
		$memberRequestManager->create($team, $this->getUser(), false);

		$om->flush();

		return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $team->getUsernameCanonical() )));
	}

	#[Route(path: '/request/{id}/accept', requirements: ['id' => '\d+'], name: 'core_member_request_accept')]
	public function acceptRequestAction($id, FollowerUtils $followerUtils, MemberManager $memberManager, MemberRequestManager $memberRequestManager, EntityManagerInterface $om, SearchUtils $searchUtils) {
		$memberRequestRepository = $om->getRepository(MemberRequest::CLASS_NAME);
		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		$memberRequest = $memberRequestRepository->findOneById($id);
		if (is_null($memberRequest)) {
			throw $this->createNotFoundException('Request entity not found (id='.$id.')');
		}
		if ($memberRequest->getSender() != $this->getUser() && !$memberRepository->existsByTeamAndUser($memberRequest->getTeam(), $this->getUser()) && !$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Not allowed (core_member_request_accept)');
		}

		// Delete request
		$memberRequestManager->delete($memberRequest);

		return $this->createAction($memberRequest->getTeam(), $memberRequest->getSender(), $followerUtils, $memberManager, $searchUtils);
	}

	#[Route(path: '/request/{id}/delete', requirements: ['id' => '\d+'], name: 'core_member_request_delete')]
	public function deleteRequestAction(Request $request, $id, MemberRequestManager $memberRequestManager, EntityManagerInterface $om) {
		$memberRequestRepository = $om->getRepository(MemberRequest::CLASS_NAME);
		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		$memberRequest = $memberRequestRepository->findOneById($id);
		if (is_null($memberRequest)) {
			throw $this->createNotFoundException('Request entity not found (id='.$id.')');
		}
		if ($memberRequest->getSender() != $this->getUser() && !$memberRepository->existsByTeamAndUser($memberRequest->getTeam(), $this->getUser()) && !$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Not allowed (core_member_request_delete)');
		}

		// Delete request
		$memberRequestManager->delete($memberRequest);

		// Flashbag
		$this->addFlash('success', 'Demande supprimée.');

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $request->headers->get('referer');
			if (is_null($returnToUrl)) {
				$returnToUrl = $this->generateUrl('core_user_show', array( 'username' => $memberRequest->getTeam()->getUsernameCanonical() ));
			}
		}

		return $this->redirect($returnToUrl);
	}

	/////

	private function createAction(User $team, User $user, FollowerUtils $followerUtils, MemberManager $memberManager, SearchUtils $searchUtils) {

		$this->createLock('core_member_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		// Follow management /////

		// Remove new member from team followers
		$followerUtils->deleteByFollowingUserAndUser($team, $user);

		// Member management /////

		$member = $memberManager->create($team, $user);

		// Search index update
		$searchUtils->replaceEntityInIndex($member->getTeam());

		// Flashbag
		if ($user == $this->getUser()) {
			$this->addFlash('success', 'Bienvenue dans le collectif <strong>'.$team->getDisplayName().'</strong>.');
		} else {
			$this->addFlash('success', '<strong>'.$user->getDisplayName().'</strong> a rejoint le collectif <strong>'.$team->getDisplayName().'</strong>.');
		}

		return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $team->getUsernameCanonical() )));
	}

	#[Route(path: '/{teamId}/delete', requirements: ['teamId' => '\d+'], name: 'core_member_delete')]
	public function deleteAction(Request $request, $teamId, MemberManager $memberManager, EntityManagerInterface $om, SearchUtils $searchUtils) {

		$team = $this->_retrieveTeam($teamId, $om);
		if ($team->getMeta()->getMemberCount() <= 1 ) {
			throw $this->createNotFoundException('Not allowed - last survivor (core_member_delete)');
		}

		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		$member = $memberRepository->findOneByTeamAndUser($team, $this->getUser());
		if (!is_null($member)) {

			// Delete member
			$memberManager->delete($member);

			// Search index update
			$searchUtils->replaceEntityInIndex($member->getTeam());

		}

		// Flashbag
		$this->addFlash('success', 'Vous avez quitté le collectif <strong>'.$team->getDisplayName().'</strong>.');

		return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $team->getUsernameCanonical() )));
	}

	/**
	 * @Template("@LadbCore/Core/Member/list-session-teams-xhr.html.twig")
	 */
	#[Route(path: '/', name: 'core_member_list_session_teams')]
	#[Route(path: '/{page}', requirements: ['page' => '\d+'], name: 'core_member_list_session_teams_page')]
	public function listSessionTeamsAction(Request $request, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $page = 0) {

		$titleTransKey = $request->get('title-trans-key');
		$route = $request->get('route');
		if (is_null($route)) {
			throw $this->createNotFoundException('Missing route (core_member_list_session_teams)');
		}
		// The template links to the route without its parameters: an unknown one (forged URL) would fail there
		try {
			$this->generateUrl($route);
		} catch (RouteNotFoundException | MissingMandatoryParametersException $e) {
			throw $this->createNotFoundException('Invalid route (core_member_list_session_teams)');
		}

		$memberRepository = $om->getRepository(Member::class);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $memberRepository->findPaginedByUser($this->getUser(), $offset, $limit);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_member_list_session_teams_page', array('titleTransKey' => $titleTransKey, 'route' => $route ), $page, $paginator->count());

		$parameters = array(
			'members'     => $paginator,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'titleTransKey'    => $titleTransKey,
			'route'       => $route,
		);

		if ($page > 0) {
			return $this->render('@LadbCore/Core/Member/list-session-teams-n-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

}