<?php

namespace Ladb\CoreBundle\Controller\Core;

use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\UserControllerTrait;
use Ladb\CoreBundle\Entity\Core\Feedback;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Entity\Core\MemberInvitation;
use Ladb\CoreBundle\Entity\Core\MemberRequest;
use Ladb\CoreBundle\Entity\Core\Review;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Core\Vote;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Form\Type\Core\UserTeamSettingsType;
use Ladb\CoreBundle\Form\Type\Core\UserTeamType;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Ladb\CoreBundle\Manager\Core\MemberInvitationManager;
use Ladb\CoreBundle\Manager\Core\MemberManager;
use Ladb\CoreBundle\Manager\Core\MemberRequestManager;
use Ladb\CoreBundle\Utils\MemberUtils;
use Ladb\CoreBundle\Utils\PropertyUtils;
use Ladb\CoreBundle\Utils\TypableUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Entity\Core\UserWitness;
use Ladb\CoreBundle\Entity\Knowledge\School\Testimonial;
use Ladb\CoreBundle\Entity\Promotion\Graphic;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Entity\Core\Comment;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Core\Follower;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Entity\Core\Registration;
use Ladb\CoreBundle\Form\Type\Core\UserSettingsType;
use Ladb\CoreBundle\Utils\CryptoUtils;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\LocalisableUtils;
use Ladb\CoreBundle\Utils\MailerUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\UserUtils;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @Route("/")
 */
class UserController extends AbstractController {

	use UserControllerTrait;

	private function _isGrantedOwner(User $user, EntityManagerInterface $om) {
		if ($user->getIsTeam() && $this->isGranted('ROLE_USER')) {

			$memberRepository = $om->getRepository(Member::CLASS_NAME);
			return $memberRepository->existsByTeamAndUser($user, $this->getUser());

		}
		return $user == $this->getUser();
	}

	private function _getInvitation(User $user, EntityManagerInterface $om) {
		if ($user->getIsTeam() && $this->isGranted('ROLE_USER')) {

			$memberInvitationRepository = $om->getRepository(MemberInvitation::CLASS_NAME);
			return $memberInvitationRepository->findOneByTeamAndRecipient($user, $this->getUser());

		}
		return null;
	}

	private function _getRequest(User $user, EntityManagerInterface $om) {
		if ($user->getIsTeam() && $this->isGranted('ROLE_USER')) {

			$memberRequestRepository = $om->getRepository(MemberRequest::CLASS_NAME);
			return $memberRequestRepository->findOneByTeamAndSender($user, $this->getUser());

		}
		return null;
	}

	private function _fillCommonShowParameters(User $user, $parameters, FollowerUtils $followerUtils, EntityManagerInterface $om, $isGrantedOwner = null) {

		if ($this->isGranted('ROLE_USER')) {

			$memberInvitation = $this->_getInvitation($user, $om, $this->getUser());
			if (!is_null($memberInvitation)) {

				// Flashbag
				$this->addFlash('info', $this->renderView('@LadbCore/Core/Member/_invitation-alert.part.html.twig', array(
					'invitation' => $memberInvitation,
				)));

			}

			$memberRequest = $this->_getRequest($user, $om, $this->getUser());
			if (!is_null($memberRequest)) {

				// Flashbag
				$this->addFlash('info', $this->renderView('@LadbCore/Core/Member/_request-alert.part.html.twig', array(
					'request' => $memberRequest,
				)));

			}

		}

		return array_merge($parameters, array(
			'user'            => $user,
			'isGrantedOwner'  => is_null($isGrantedOwner) ? $this->_isGrantedOwner($user, $om) : $isGrantedOwner,
			'invitation'      => $this->_getInvitation($user, $om),
			'request'         => $this->_getRequest($user, $om),
			'followerContext' => $followerUtils->getFollowerContext($user, $this->getUser()),
		));
	}

	/////

	/**
	 * @Route("/email/check", name="core_user_email_check")
	 * @Template("@LadbCore/Core/User/emailCheck.html.twig")
	 */
	public function emailCheckAction(MailerUtils $mailerUtils, TokenGeneratorInterface $tokenGenerator, UserManager $userManager) {

		$user = $this->getUser();

		if (null === $user->getConfirmationToken()) {
			$user->setConfirmationToken($tokenGenerator->generateToken());
		}

		$userManager->updateUser($user);

		// Email
		$mailerUtils->sendConfirmationEmailMessage($user);

		return array(
			'user' => $user,
		);
	}

	/**
	 * @Route("/email/confirm/{token}", name="core_user_email_confirm")
	 * @Template("@LadbCore/Core/User/emailConfirm.html.twig")
	 */
	public function emailConfirmAction($token, TranslatorInterface $translator, UserManager $userManager) {

		$invalidToken = false;
		$invalidUser = false;

		$user = $userManager->findUserByConfirmationToken($token);
		if (null === $user) {
			$invalidToken = true;
		} else if ($this->getUser()->getId() != $user->getId()) {
			$invalidUser = true;
		}

		if (!$invalidToken && !$invalidUser) {

			$user->setConfirmationToken(null);
			$user->setEmailConfirmed(true);

			$userManager->updateUser($user);

			// Flashbag
			$this->addFlash('success', $translator->trans('user.email_confirmation.confirm.success', array( '%email%' => $user->getEmail() )));

			return $this->redirect($this->generateUrl('core_welcome'));
		}

		return array(
			'invalidToken' => $invalidToken,
			'invalidUser'  => $invalidUser,
		);
	}

	/**
	 * @Route("/email/unsubscribe/{list}/{encryptedEmail}", requirements={"list" = "notifications|weeknews"}, name="core_user_email_unsubscribe")
	 * @Template("@LadbCore/Core/User/emailUnsubscribe.html.twig")
	 */
	public function emailUnsubscribeAction($list, $encryptedEmail, CryptoUtils $cryptoUtils, UserManager $userManager) {

		$invalidEmail = false;

		$email = $cryptoUtils->decryptString($encryptedEmail);
		$user = $userManager->findUserByEmail($email);
		if (null === $user) {
			$invalidEmail = true;
		}

		if (!$invalidEmail) {

			switch ($list) {

				case MailerUtils::LIST_NOTIFICATIONS:
					$user->getMeta()->setIncomingMessageEmailNotificationEnabled(false);
					$user->getMeta()->setNewFollowerEmailNotificationEnabled(false);
					$user->getMeta()->setNewLikeEmailNotificationEnabled(false);
					$user->getMeta()->setNewVoteEmailNotificationEnabled(false);
					$user->getMeta()->setNewFollowingPostEmailNotificationEnabled(false);
					$user->getMeta()->setNewWatchActivityEmailNotificationEnabled(false);
					$user->getMeta()->setNewSpotlightEmailNotificationEnabled(false);
					break;

				case MailerUtils::LIST_WEEKNEWS:
					$user->getMeta()->setWeekNewsEmailEnabled(false);
					break;

			}

			$userManager->updateUser($user);

		}

		return array(
			'invalidEmail' => $invalidEmail,
			'email'        => $email,
			'list'         => $list,
		);
	}

	/**
	 * @Route("/boiseux/{filter}", requirements={"filter" = "[a-z-]+"}, name="core_user_list_filter")
	 * @Route("/boiseux/{filter}/{page}", requirements={"filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_list_filter_page")
	 */
	public function goneListAction(Request $request, $filter, $page = 0) {
		throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException();
	}

	/**
	 * @Route("/boiseux", name="core_user_list")
	 * @Route("/boiseux/{page}", requirements={"page" = "\d+"}, name="core_user_list_page")
	 * @Route("/boiseux.geojson", defaults={"_format" = "json", "page"=-1, "layout"="geojson"}, name="core_user_list_geojson")
	 * @Route("/collectifs", defaults={"family"="team"}, name="core_user_team_list")
	 * @Route("/collectifs/{page}", defaults={"family"="team"}, requirements={"page" = "\d+"}, name="core_user_team_list_page")
	 * @Route("/collectifs.geojson", defaults={"_format" = "json", "page"=-1, "layout"="geojson", "family"="team"}, name="core_user_team_list_geojson")
	 * @Template("@LadbCore/Core/User/list.html.twig")
	 */
	public function listAction(Request $request, LocalisableUtils $localisableUtils, SearchUtils $searchUtils, $page = 0, $layout = 'view', $family = 'user') {

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils, $localisableUtils) {
				switch ($facet->name) {

					// Filters /////

					case 'skill':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'meta.skills.label' ));
						$filters[] = $filter;

						break;

					case 'account-type':

						$filter = new \Elastica\Query\MatchPhrase('accountType', $facet->value);
						$filters[] = $filter;

						break;

					case 'around':

						if (isset($facet->value)) {
							$filter = new \Elastica\Query\GeoDistance('geoPoint', $facet->value, '100km');
							$filters[] = $filter;
						}

						break;

					case 'geocoded':

						$filter = new \Elastica\Query\Exists('geoPoint');
						$filters[] = $filter;

						break;

					case 'location':

						$boundsAndLocation = $localisableUtils->getBoundsAndLocation($facet->value);

						if (!is_null($boundsAndLocation)) {
							$filter = new \Elastica\Query\BoolQuery();
							if (isset($boundsAndLocation['bounds'])) {
								$geoQuery = new \Elastica\Query\GeoBoundingBox('geoPoint', $boundsAndLocation['bounds']);
								$filter->addShould($geoQuery);
							}
							if (isset($boundsAndLocation['location'])) {
								$geoQuery = new \Elastica\Query\GeoDistance('geoPoint', $boundsAndLocation['location'], '20km');
								$filter->addShould($geoQuery);
							}
							$filters[] = $filter;
						}

						break;

					// Sorters /////

					case 'sort-recent':
						$sort = array( 'createdAt' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-followers':
						$sort = array( 'meta.followerCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-likes':
						$sort = array( 'meta.recievedLikeCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-members':
						$sort = array( 'meta.memberCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-random':
						$sort = array( 'randomSeed' => isset($facet->value) ? $facet->value : '' );
						break;

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'displayname', 'fullname', 'username' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) use ($family) {

				$sort = array('meta.contributionCount' => array('order' => 'desc'));

			},
			function(&$filters) use ($family) {
				if ($family == 'team') {
					$filter = new \Elastica\Query\Term([ 'isTeam' => [ 'value' => true, 'boost' => 1.0 ] ]);
					$filters[] = $filter;
				} else {
					$filter = new \Elastica\Query\Term([ 'isTeam' => [ 'value' => false, 'boost' => 1.0 ] ]);
					$filters[] = $filter;
				}
			},
			'fos_elastica.index.core_user',
			\Ladb\CoreBundle\Entity\Core\User::CLASS_NAME,
			$family == 'team' ? 'core_user_team_list_page' : 'core_user_list_page'
		);

		$parameters = array_merge($searchParameters, array(
			'family' => $family,
			'users'  => $searchParameters['entities'],
		));

		if ($layout == 'geojson') {

			$features = array();
			foreach ($searchParameters['entities'] as $user) {
				$geoPoint = $user->getGeoPoint();
				if (is_null($geoPoint)) {
					continue;
				}
				$properties = array(
					'color' => array( 'orange', 'green', 'blue', 'orange', 'green' )[$user->getAccountType()],
					'cardUrl' => $this->generateUrl('core_user_card', array( 'username' => $user->getUsernameCanonical() )),
				);
				$gerometry = new \GeoJson\Geometry\Point($geoPoint);
				$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
			}
			$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
			$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

			$parameters = array_merge($parameters, array(
				'collection' => $collection,
			));

			return $this->render('@LadbCore/Core/User/list-xhr.geojson.twig', $parameters);
		}

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/User/list-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

	/**
	 * @Route("/parametres", name="core_user_settings_me_old")
	 * @Route("/@me/parametres", name="core_user_settings_me")
	 */
	public function oldSettingsAction(Request $request, $username = null) {
		$username = $this->getUser()->getUsernameCanonical();

		return $this->redirect($this->generateUrl('core_user_settings', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/parametres", name="core_user_settings")
	 * @Template("@LadbCore/Core/User/settings.html.twig")
	 */
	public function settingsAction(Request $request, $username, FieldPreprocessorUtils $fieldPreprocessorUtils, LocalisableUtils $localisableUtils, EntityManagerInterface $om, SearchUtils $searchUtils, TranslatorInterface $translator, UserManager $userManager, UserUtils $userUtils) {
		$user = $this->retrieveUserByUsername($username);
		if (!$this->isGranted('ROLE_ADMIN')) {
			if ($user->getIsTeam()) {
				$memberRepository = $om->getRepository(Member::class);
				if (!$memberRepository->existsByTeamAndUser($user, $this->getUser())) {
					throw $this->createNotFoundException('Access denied');
				}
			} else if ($user != $this->getUser()) {
				throw $this->createNotFoundException('Access denied (core_user_settings)');
			}
		}

		$oldUsername = $user->getUsernameCanonical();
		$form = $this->createForm($user->getIsTeam() ? UserTeamSettingsType::class : UserSettingsType::class, $user);

		if ($request->isMethod('post')) {
			$form->handleRequest($request);

			if ($form->isSubmitted() && $form->isValid()) {

				// Check if new username
				if ($user->getUsernameCanonical() != $oldUsername) {

					// Check if witness already exists
					$userWitnessRepository = $om->getRepository(UserWitness::class);
					$userWitness = $userWitnessRepository->findOneByUsername($oldUsername);
					if (is_null($userWitness)) {

						// No previous, create a new witness
						$userWitness = new UserWitness();
						$userWitness->setUsername($oldUsername);
						$userWitness->setUser($user);

						$om->persist($userWitness);

					}

				}

				$fieldPreprocessorUtils->preprocessFields($user->getMeta()->getBiography());

				// Geocode location
				$localisableUtils->geocodeLocation($user);

				// Default avatar
				if (is_null($user->getAvatar())) {
					$userUtils->createDefaultAvatar($user);
				}

				// Final update of user entity
				$userManager->updateUser($user, true);

				// Search index update
				$searchUtils->replaceEntityInIndex($user);

				// Flashbag
				$this->addFlash('success', $translator->trans('user.form.alert.settings_success'));

				return $this->redirect($this->generateUrl('core_user_show', array('username' => $user->getUsernameCanonical())));
			}

			// Flashbag
			$this->addFlash('error', $translator->trans('default.form.alert.error'));

		}

		return array(
			'user' => $user,
			'form' => $form->createView(),
		);
	}

	/**
	 * @Route("/@me/counters.json", name="core_user_counters_me", defaults={"_format" = "json"})
	 * @Template("@LadbCore/Core/User/counters-xhr.json.twig")
	 */
	public function countersAction(Request $request, PropertyUtils $propertyUtils, TypableUtils $typableUtils, UserUtils $userUtils) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_user_counters_me)');
		}

		$user = $this->getUser();
		if (is_null($user)) {
			throw $this->createNotFoundException('No current user (core_user_counters_me)');
		}
		$meta = $user->getMeta();

		$listedCounterKey = null;
		$listedCounterValue = null;

		// Check if a listed entity type is provided (to retrieve counter before computing a new one)
		$listedEntityType = $request->get('listed_entity_type', false);
		if ($listedEntityType) {

			$listedEntityStrippedName = $typableUtils->getStrippedNameByType($listedEntityType);

			$propertyPath = 'unlisted_'.$listedEntityStrippedName.'_count';

			try {

				// Retrieve counter value
				$listedCounterKey = $propertyUtils->camelCasePropertyAccessor('', $propertyPath);
				$listedCounterValue = $propertyUtils->getValue($meta, $propertyPath);

			} catch (\Exception $e) {}

		}

		// Compute unlisted counters
		$userUtils->computeUnlistedCounters($user, $listedEntityType);

		$counters = array(
			'unlistedWonderCreationCount' => $meta->getUnlistedWonderCreationCount(),
			'unlistedWonderPlanCount' => $meta->getUnlistedWonderPlanCount(),
			'unlistedWonderWorkshopCount' => $meta->getUnlistedWonderWorkshopCount(),
			'unlistedFindFindCount' => $meta->getUnlistedFindFindCount(),
			'unlistedHowtoHowtoCount' => $meta->getUnlistedHowtoHowtoCount(),
			'unlistedKnowledgeWoodCount' => $meta->getUnlistedKnowledgeWoodCount(),
			'unlistedKnowledgeToolCount' => $meta->getUnlistedKnowledgeToolCount(),
			'unlistedKnowledgeBookCount' => $meta->getUnlistedKnowledgeBookCount(),
			'unlistedKnowledgeSoftwareCount' => $meta->getUnlistedKnowledgeSoftwareCount(),
			'unlistedCollectionCollectionCount' => $meta->getUnlistedCollectionCollectionCount(),
			'unlistedKnowledgeProviderCount' => $meta->getUnlistedKnowledgeProviderCount(),
			'unlistedKnowledgeSchoolCount' => $meta->getUnlistedKnowledgeSchoolCount(),
			'unlistedBlogPostCount' => $meta->getUnlistedBlogPostCount(),
			'unlistedFaqQuestionCount' => $meta->getUnlistedFaqQuestionCount(),
			'unlistedQaQuestionCount' => $meta->getUnlistedQaQuestionCount(),
			'unlistedPromotionGraphicCount' => $meta->getUnlistedPromotionGraphicCount(),
			'unlistedWorkflowWorkflowCount' => $meta->getUnlistedWorkflowWorkflowCount(),
			'unlistedOfferOfferCount' => $meta->getUnlistedOfferOfferCount(),
			'unlistedEventEventCount' => $meta->getUnlistedEventEventCount(),
		);

		if (!is_null($listedCounterKey) && !is_null($listedCounterValue)) {
			$counters[$listedCounterKey] = $listedCounterValue;
		}

		return array(
			'counters' => $counters,
		);
	}

	/**
	 * @Route("/@{username}/location.geojson", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_location", defaults={"_format" = "json"})
	 * @Template("@LadbCore/Core/User/location.geojson.twig")
	 */
	public function locationAction(Request $request, $username) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_location', array( 'username' => $user->getUsernameCanonical() )));
		}

		$features = array();
		if (!is_null($user->getLongitude()) && !is_null($user->getLatitude())) {
			$properties = array(
				'color' => array( 'orange', 'green', 'blue', 'orange', 'green' )[$user->getAccountType()],
				'cardUrl' => $this->generateUrl('core_user_card', array( 'username' => $user->getUsername() )),
			);
			$gerometry = new \GeoJson\Geometry\Point($user->getGeoPoint());
			$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
		}

		$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
		$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

		return array(
			'collection' => $collection,
		);
	}

	/**
	 * @Route("/@{username}/card.xhr", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_card")
	 * @Template("@LadbCore/Core/User/card-xhr.html.twig")
	 */
	public function cardAction(Request $request, $username) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_user_card)');
		}

		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_card', array( 'username' => $user->getUsernameCanonical() )));
		}

		return array(
			'user' => $user,
		);
	}

	/**
	 * @Route("/{username}/a-propos", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_about_old")
	 */
	public function oldShowAboutAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_about', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/a-propos", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_about")
	 * @Template("@LadbCore/Core/User/showAbout.html.twig")
	 */
	public function showAboutAction($username, FollowerUtils $followerUtils, EntityManagerInterface $om) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_about', array( 'username' => $user->getUsernameCanonical() )));
		}

		$testimonialRepository = $om->getRepository(Testimonial::CLASS_NAME);
		$testimonials = $testimonialRepository->findByUser($user);

		return $this->_fillCommonShowParameters($user, array(
			'tab'             => 'about',
			'hasMap'          => !is_null($user->getLatitude()) && !is_null($user->getLongitude()),
			'testimonials'    => $testimonials,
		), $followerUtils, $om);
	}

	/**
	 * @Route("/{username}/coups-de-coeur", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_likes_old")
	 */
	public function oldShowLikesAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_likes', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/coups-de-coeur", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_likes")
	 * @Route("/@{username}/coups-de-coeur/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "sent|recieved"}, name="core_user_show_likes_filter")
	 * @Route("/@{username}/coups-de-coeur/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_likes_filter_page")
	 * @Template("@LadbCore/Core/User/showLikes.html.twig")
	 */
	public function showLikesAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "sent", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_likes', array( 'username' => $user->getUsernameCanonical() )));
		}

		$likeRepository = $om->getRepository(Like::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$items = $likeRepository->findPaginedByUser($user, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_likes_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $filter == 'recieved' ? $user->getMeta()->getRecievedLikeCount() : $user->getMeta()->getSentLikeCount());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'items'       => $items,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Like/list-byuser-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'likes',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/{username}/commentaires", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_comments_old")
	 */
	public function oldShowCommentsAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_comments', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/commentaires", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_comments")
	 * @Route("/@{username}/commentaires/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "page" = "\d+"}, name="core_user_show_comments_page")
	 * @Template("@LadbCore/Core/User/showComments.html.twig")
	 */
	public function showCommentsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_comments', array( 'username' => $user->getUsernameCanonical() )));
		}

		$commentRepository = $om->getRepository(Comment::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$items = $commentRepository->findPaginedByUserGroupByEntityType($user, $offset, $limit);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_comments_page', array( 'username' => $user->getUsernameCanonical() ), $page, $user->getMeta()->getCommentCount());

		$parameters = array(
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'items'       => $items,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Comment/list-byuser-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'comments',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/{username}/votes", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_votes_old")
	 */
	public function oldShowVotesAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_votes', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/votes", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_votes")
	 * @Route("/@{username}/votes/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "positive|negative"}, name="core_user_show_votes_filter")
	 * @Route("/@{username}/votes/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "positive|negative", "page" = "\d+"}, name="core_user_show_votes_filter_page")
	 * @Template("@LadbCore/Core/User/showVotes.html.twig")
	 */
	public function showVotesAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = 'positive', $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_votes', array( 'username' => $user->getUsernameCanonical() )));
		}

		$voteRepository = $om->getRepository(Vote::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$items = $voteRepository->findPaginedByUserGroupByEntityType($user, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_votes_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $filter == 'up' ? $user->getMeta()->getPositiveVoteCount() : $user->getMeta()->getNegativeVoteCount());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'items'       => $items,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Vote/list-byuser-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'votes',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/{username}/reviews", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_reviews_old")
	 */
	public function oldShowReviewsAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_reviews', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/reviews", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_reviews")
	 * @Route("/@{username}/reviews/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_reviews_filter")
	 * @Route("/@{username}/reviews/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_reviews_filter_page")
	 * @Template("@LadbCore/Core/User/showReviews.html.twig")
	 */
	public function showReviewsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = 'recent', $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_reviews', array( 'username' => $user->getUsernameCanonical() )));
		}

		$reviewRepository = $om->getRepository(Review::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$items = $reviewRepository->findPaginedByUserGroupByEntityType($user, $offset, $limit);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_reviews_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $user->getMeta()->getReviewCount());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'items'       => $items,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Review/list-byuser-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'reviews',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/{username}/feedbacks", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_feedbacks_old")
	 */
	public function oldShowFeedbacksAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_feedbacks', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/feedbacks", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_feedbacks")
	 * @Route("/@{username}/feedbacks/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_feedbacks_filter")
	 * @Route("/@{username}/feedbacks/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_feedbacks_filter_page")
	 * @Template("@LadbCore/Core/User/showFeedbacks.html.twig")
	 */
	public function showFeedbacksAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_feedbacks', array( 'username' => $user->getUsernameCanonical() )));
		}

		$feedbackRepository = $om->getRepository(Feedback::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$items = $feedbackRepository->findPaginedByUserGroupByEntityType($user, $offset, $limit);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_feedbacks_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $user->getMeta()->getFeedbackCount());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'items'       => $items,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Feedback/list-byuser-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'feedbacks',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/{username}/creations", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_creations_old")
	 */
	public function oldShowCreationsAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_creations', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/creations", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_creations")
	 * @Route("/@{username}/creations/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_creations_filter")
	 * @Route("/@{username}/creations/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_creations_filter_page")
	 * @Template("@LadbCore/Core/User/showCreations.html.twig")
	 */
	public function showCreationsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_creations', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Creations

		$creationRepository = $om->getRepository(Creation::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $creationRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_creations_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'creations'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Wonder/Creation/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'creations',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/ateliers", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_workshops_old")
	 */
	public function oldShowWorkshopsAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_workshops', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/ateliers", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_workshops")
	 * @Route("/@{username}/ateliers/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_workshops_filter")
	 * @Route("/@{username}/ateliers/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_workshops_filter_page")
	 * @Template("@LadbCore/Core/User/showWorkshops.html.twig")
	 */
	public function showWorkshopsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_workshops', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Workshops

		$workshopRepository = $om->getRepository(Workshop::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workshopRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_workshops_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'workshops'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Wonder/Workshop/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'workshops',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/plans", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_plans_old")
	 */
	public function oldShowPlansAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_plans', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/plans", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_plans")
	 * @Route("/@{username}/plans/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_plans_filter")
	 * @Route("/@{username}/plans/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_plans_filter_page")
	 * @Template("@LadbCore/Core/User/showPlans.html.twig")
	 */
	public function showPlansAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_plans', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Plans

		$planRepository = $om->getRepository(Plan::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $planRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_plans_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'plans'       => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Wonder/Plan/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'plans',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/pas-a-pas", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_howtos_old")
	 */
	public function showOldHowtosAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_howtos', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/pas-a-pas", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_howtos")
	 * @Route("/@{username}/pas-a-pas/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_howtos_filter")
	 * @Route("/@{username}/pas-a-pas/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_howtos_filter_page")
	 * @Template("@LadbCore/Core/User/showHowtos.html.twig")
	 */
	public function showHowtosAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_howtos', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Howtos

		$howtoRepository = $om->getRepository(Howto::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $howtoRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_howtos_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'howtos'      => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Howto/Howto/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'howtos',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/trouvailles", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_finds_old")
	 */
	public function showOldFindsAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_finds', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/trouvailles", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_finds")
	 * @Route("/@{username}/trouvailles/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_finds_filter")
	 * @Route("/@{username}/trouvailles/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_finds_filter_page")
	 * @Template("@LadbCore/Core/User/showFinds.html.twig")
	 */
	public function showFindsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_finds', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Finds

		$findRepository = $om->getRepository(Find::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $findRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_finds_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'finds'       => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Find/Find/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'finds',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/questions", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_questions_old")
	 */
	public function showOldQuestionsAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_questions', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/questions", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_questions")
	 * @Route("/@{username}/questions/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_questions_filter")
	 * @Route("/@{username}/questions/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_questions_filter_page")
	 * @Template("@LadbCore/Core/User/showQuestions.html.twig")
	 */
	public function showQuestionsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_questions', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Questions

		$questionRepository = $om->getRepository(Question::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $questionRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_questions_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'questions'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Qa/Question/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'questions',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/reponses", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_answers_old")
	 */
	public function showOldAnswersAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_answers', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/reponses", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_answers")
	 * @Route("/@{username}/reponses/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_answers_filter")
	 * @Route("/@{username}/reponses/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_answers_filter_page")
	 * @Template("@LadbCore/Core/User/showAnswers.html.twig")
	 */
	public function showAnswersAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "recent", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_answers', array( 'username' => $user->getUsernameCanonical() )));
		}

		$answerRepository = $om->getRepository(Answer::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$items = $answerRepository->findPaginedByUser($user, $offset, $limit);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_answers_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $user->getMeta()->getAnswerCount());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'items'       => $items,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Qa/Answer/list-byuser-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'answers',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/{username}/graphismes", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_graphics_old")
	 */
	public function showOldGraphicsAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_graphics', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/graphismes", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_graphics")
	 * @Route("/@{username}/graphismes/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_graphics_filter")
	 * @Route("/@{username}/graphismes/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_graphics_filter_page")
	 * @Template("@LadbCore/Core/User/showGraphics.html.twig")
	 */
	public function showGraphicsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_graphics', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Graphics

		$graphicRepository = $om->getRepository(Graphic::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $graphicRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_graphics_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'graphics'    => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Promotion/Graphic/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'graphics',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/processus", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_workflows_old")
	 */
	public function showOldWorkflowsAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_workflows', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/processus", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_workflows")
	 * @Route("/@{username}/processus/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_workflows_filter")
	 * @Route("/@{username}/processus/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_workflows_filter_page")
	 * @Template("@LadbCore/Core/User/showWorkflows.html.twig")
	 */
	public function showWorkflowsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_workflows', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Workflows

		$workflowRepository = $om->getRepository(Workflow::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workflowRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_workflows_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'workflows'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Workflow/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'workflows',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/annonces", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_offers_old")
	 */
	public function showOldOffersAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_offers', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/annonces", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_offers")
	 * @Route("/@{username}/annonces/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_offers_filter")
	 * @Route("/@{username}/annonces/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_offers_filter_page")
	 * @Template("@LadbCore/Core/User/showOffers.html.twig")
	 */
	public function showOffersAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = null, $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_offers', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);

		// Default filter

		if (is_null($filter)) {
			if ($isGrantedOwner) {
				$filter = 'recent';
			} else {
				$filter = 'popular-likes';
			}
		}

		// Offers

		$offerRepository = $om->getRepository(Offer::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $offerRepository->findPaginedByUser($user, $offset, $limit, $filter, $this->isGranted('ROLE_ADMIN') || $isGrantedOwner);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_offers_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'offers'      => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Offer/Offer/list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'offers',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/{username}/abonnements", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_following_old")
	 */
	public function showOldFollowingAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_following', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/abonnements", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_following")
	 * @Route("/@{username}/abonnements/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_following_filter")
	 * @Route("/@{username}/abonnements/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_following_filter_page")
	 * @Template("@LadbCore/Core/User/showFollowing.html.twig")
	 */
	public function showFollowingAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "popular-followers", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_following', array( 'username' => $user->getUsernameCanonical() )));
		}

		// Following

		$followerRepository = $om->getRepository(Follower::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $followerRepository->findPaginedByUser($user, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_following_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'followers'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Follower/following-list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'following',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/{username}/abonnes", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_followers_old")
	 */
	public function showOldFollowersAction($username) {
		return $this->redirect($this->generateUrl('core_user_show_followers', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/abonnes", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_followers")
	 * @Route("/@{username}/abonnes/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_followers_filter")
	 * @Route("/@{username}/abonnes/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_followers_filter_page")
	 * @Template("@LadbCore/Core/User/showFollowers.html.twig")
	 */
	public function showFollowersAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "popular-followers", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_followers', array( 'username' => $user->getUsernameCanonical() )));
		}

		// Followers

		$followerRepository = $om->getRepository(Follower::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $followerRepository->findPaginedByFollowingUser($user, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_followers_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'followers'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Follower/followers-list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'followers'       => $paginator,
			'tab'             => 'followers',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/@{username}/membres", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_members")
	 * @Route("/@{username}/membres/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_members_filter")
	 * @Route("/@{username}/membres/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_members_filter_page")
	 * @Template("@LadbCore/Core/User/showMembers.html.twig")
	 */
	public function showMembersAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "popular-followers", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_members', array( 'username' => $user->getUsernameCanonical() )));
		}

		// Member

		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $memberRepository->findPaginedByTeam($user, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_members_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'members'     => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Member/members-list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'members',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/@{username}/invitations", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_invitations")
	 * @Route("/@{username}/invitations/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_invitations_filter")
	 * @Route("/@{username}/invitations/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_invitations_filter_page")
	 * @Template("@LadbCore/Core/User/showInvitations.html.twig")
	 */
	public function showInvitationsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "popular-followers", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_invitations', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);
		if (!$this->isGranted('ROLE_ADMIN') && !$isGrantedOwner) {
			return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $user->getUsernameCanonical() )));
		}

		// Member

		$memberInvitationRepository = $om->getRepository(MemberInvitation::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		if ($user->getIsTeam()) {
			$paginator = $memberInvitationRepository->findPaginedByTeam($user, $offset, $limit, $filter);
		} else {
			$paginator = $memberInvitationRepository->findPaginedByRecipient($user, $offset, $limit, $filter);
		}
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_invitations_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'invitations' => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Member/invitations-list-xhr.html.twig', array_merge(array( 'user' => $user ), $parameters));
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'invitations',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/@{username}/demandes", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_requests")
	 * @Route("/@{username}/demandes/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_requests_filter")
	 * @Route("/@{username}/demandes/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_requests_filter_page")
	 * @Template("@LadbCore/Core/User/showRequests.html.twig")
	 */
	public function showRequestsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "popular-followers", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_requests', array( 'username' => $user->getUsernameCanonical() )));
		}

		$isGrantedOwner = $this->_isGrantedOwner($user, $om);
		if (!$this->isGranted('ROLE_ADMIN') && !$isGrantedOwner) {
			return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $user->getUsernameCanonical() )));
		}

		// Member

		$memberRequestRepository = $om->getRepository(MemberRequest::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		if ($user->getIsTeam()) {
			$paginator = $memberRequestRepository->findPaginedByTeam($user, $offset, $limit, $filter);
		} else {
			$paginator = $memberRequestRepository->findPaginedBySender($user, $offset, $limit, $filter);
		}
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_requests_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'requests'    => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Member/requests-list-xhr.html.twig', array_merge(array( 'user' => $user ), $parameters));
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'requests',
		)), $followerUtils, $om, $isGrantedOwner);
	}

	/**
	 * @Route("/@{username}/collectifs", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_teams")
	 * @Route("/@{username}/collectifs/{filter}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+"}, name="core_user_show_teams_filter")
	 * @Route("/@{username}/collectifs/{filter}/{page}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_user_show_teams_filter_page")
	 * @Template("@LadbCore/Core/User/showTeams.html.twig")
	 */
	public function showTeamsAction(Request $request, $username, FollowerUtils $followerUtils, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, $filter = "popular-followers", $page = 0) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show_teams', array( 'username' => $user->getUsernameCanonical() )));
		}

		// Teams

		$memberRepository = $om->getRepository(Member::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $memberRepository->findPaginedByUser($user, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_user_show_teams_filter_page', array( 'username' => $user->getUsernameCanonical(), 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'members'     => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Follower/teams-list-xhr.html.twig', $parameters);
		}

		return $this->_fillCommonShowParameters($user, array_merge($parameters, array(
			'tab' => 'teams',
		)), $followerUtils, $om);
	}

	/**
	 * @Route("/@me", name="core_user_show_me")
	 */
	public function showMeAction() {
		$username = $this->getUser()->getUsernameCanonical();

		return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $username )));
	}

	/**
	 * @Route("/{username}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_old")
	 */
	public function oldShowAction($username) {
		return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show")
	 */
	public function showAction($username, EntityManagerInterface $om) {
		$user = $this->retrieveUserByUsername($username);
		if ($user->getUsernameCanonical() != $username) {
			return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $user->getUsernameCanonical() )));
		}

		$CrawlerDetect = new CrawlerDetect();
		if ($CrawlerDetect->isCrawler() || $user->getIsTeam() && !is_null($user->getMeta()->getBiography()) && !empty($user->getMeta()->getBiography()->getHtmlBody())) {	 /* Return about page for Crawlers */
			$forwardController = UserController::class.'::showAboutAction';
		} else if ($user->getIsTeam()) {
			$forwardController = UserController::class.'::showMembersAction';
		} else if ($user->getMeta()->getPublicCreationCount() > 0) {
			$forwardController = UserController::class.'::showCreationsAction';
		} else if ($user->getMeta()->getPublicPlanCount() > 0) {
			$forwardController = UserController::class.'::showPlansAction';
		} else if ($user->getMeta()->getPublicHowtoCount() > 0) {
			$forwardController = UserController::class.'::showHowtosAction';
		} else if ($user->getMeta()->getPublicWorkshopCount() > 0) {
			$forwardController = UserController::class.'::showWorkshopsAction';
		} else if ($user->getMeta()->getPublicFindCount() > 0) {
			$forwardController = UserController::class.'::showFindsAction';
		} else {
			$forwardController = UserController::class.'::showAboutAction';
		}

		if ($user->getIsTeam() && $this->isGranted('ROLE_USER')) {

			$memberRepository = $om->getRepository(Member::CLASS_NAME);
			$isMember = $memberRepository->existsByTeamAndUser($user, $this->getUser());

		} else {
			$isMember = false;
		}

		$response = $this->forward($forwardController, array(
			'username' => $username,
			'isMember' => $isMember,
		));
		return $response;
	}

	// Team /////

	/**
	 * @Route("/collectifs/new", name="core_user_team_new")
	 * @Template("@LadbCore/Core/User/Team/new.html.twig")
	 */
	public function teamNewAction(UserManager $userManager) {
		if (!$this->getUser()->getEmailConfirmed()) {
			throw $this->createNotFoundException('Not allowed (core_user_team_new)');
		}

		$team = $userManager->createUser();
		$form = $this->createForm(UserTeamType::class, $team);

		return array(
			'form' => $form->createView(),
		);
	}

	/**
	 * @Route("/collectifs/create", methods={"POST"}, name="core_user_team_create")
	 * @Template("@LadbCore/Core/User/Team/new.html.twig")
	 */
	public function teamCreateAction(Request $request, MailerUtils $mailerUtils, MemberManager $memberManager, EntityManagerInterface $om, SearchUtils $searchUtils, TranslatorInterface $translator, UserManager $userManager, UserUtils $userUtils) {
		if (!$this->getUser()->getEmailConfirmed()) {
			throw $this->createNotFoundException('Not allowed (core_user_team_create)');
		}

		$this->createLock('core_user_team_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$team = $userManager->createUser();
		$team->setEnabled(true);
		$team->setEmail(uniqid('', true).'-team@lairdubois.fr');		// Fake email - to bypass Registration validation on this field
		$team->setPlainPassword(bin2hex(random_bytes(20)));									// Put a random password - to bypass Registration validation on this field AND avoid logon on this account
		$form = $this->createForm(UserTeamType::class, $team);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			// Default avatar
			if (is_null($team->getAvatar())) {
				$userUtils->createDefaultAvatar($team);
			}

			$team->setIsTeam(true);
			$team->setEmailCanonical($team->getEmail());
			$team->addRole('ROLE_TEAM');
			$team->getMeta()->setRequestEnabled(true);
			$userManager->updateUser($team);

			// Add team's creator as first member
			$memberManager->create($team, $this->getUser());

			$om->flush();

			// Search index update
			$searchUtils->insertEntityToIndex($team);

			// Flashbag
			$this->addFlash('success', $translator->trans('user.form.alert.team_success', array( '%displayname%' => $team->getDisplayname() )));

			/////

			$registration = new Registration();
			$registration->setCreator($this->getUser());
			$registration->setUser($team);
			$registration->setClientIp4($request->getClientIp());
			$registration->setClientUserAgent($request->server->get('HTTP_USER_AGENT'));

			$om->persist($registration);
			$om->flush();

			// Send admin email notification
			$mailerUtils->sendNewTeamNotificationEmailMessage($this->getUser(), $team);

			return $this->redirect($this->generateUrl('core_user_show', array( 'username' => $team->getUsernameCanonical()) ));
		}

		// Flashbag
		$this->addFlash('error', $translator->trans('default.form.alert.error'));

		return array(
			'user' => $team,
			'form' => $form->createView(),
		);
	}

	// Admin /////

	/**
	 * @Route("/@{username}/admin", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_show_admin")
	 * @Template("@LadbCore/Core/User/showAdmin.html.twig")
	 */
	public function showAdminAction($username, FollowerUtils $followerUtils, EntityManagerInterface $om, UserManager $userManager) {
		if (!$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Access denied');
		}

		$user = $userManager->findUserByUsername($username);
		if (is_null($user)) {
			throw $this->createNotFoundException('User not found');
		}

		$registrationRepository = $om->getRepository(Registration::CLASS_NAME);
		$registration = $registrationRepository->findOneByUser($user);

		return $this->_fillCommonShowParameters($user, array(
			'registration' => $registration,
			'tab'          => 'admin',
		), $followerUtils, $om);
	}

	/**
	 * @Route("/@{username}/admin/converttoteam", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_admin_converttoteam")
	 */
	public function adminConvertToTeamAction($username, MemberInvitationManager $memberInvitationManager, MemberManager $memberManager, MemberRequestManager $memberRequestManager, EntityManagerInterface $om, SearchUtils $searchUtils, TranslatorInterface $translator, UserManager $userManager) {
		if (!$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Access denied');
		}

		$user = $userManager->findUserByUsername($username);
		if (is_null($user)) {
			throw $this->createNotFoundException('User not found');
		}

		// Remove all pending invitations
		$memberInvitationRepository = $om->getRepository(MemberInvitation::CLASS_NAME);
		$memberInvitations = $memberInvitationRepository->findPaginedByRecipient($user);
		foreach ($memberInvitations as $memberInvitation) {
			$memberInvitationManager->delete($memberInvitation);
		}

		// Remove all pending requests
		$memberRequestRepository = $om->getRepository(MemberRequest::CLASS_NAME);
		$memberRequests = $memberRequestRepository->findPaginedBySender($user);
		foreach ($memberRequests as $memberRequest) {
			$memberRequestManager->delete($memberRequest);
		}

		// Remove all members
		$memberRepository = $om->getRepository(Member::CLASS_NAME);
		$members = $memberRepository->findPaginedByUser($user);
		foreach ($members as $member) {
			$memberManager->delete($member);
		}

		// Convert
		$user->setIsTeam(true);
		$user->setEmail(uniqid('', true).'-team@lairdubois.fr');		// Fake email
		$user->setEmailCanonical($user->getEmail());
		$user->setPlainPassword(bin2hex(random_bytes(20)));									// Put a random password - to avoid logon on this account
		$user->getMeta()->incrementMemberCount($user->getMeta()->getMemberCount());
        $user->addRole('ROLE_TEAM');
		$user->getMeta()->setRequestEnabled(true);

		$om->flush();

		// Search index update
		$searchUtils->insertEntityToIndex($user);

		// Flashbag
		$this->addFlash('success', $translator->trans('user.admin.alert.converttoteam_success', array( '%displayname%' => $user->getDisplayname() )));

		return $this->redirect($this->generateUrl('core_user_show_admin', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/admin/activate", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_admin_activate")
	 */
	public function adminActivateAction($username, SearchUtils $searchUtils, TranslatorInterface $translator, UserManager $userManager) {
		if (!$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Access denied');
		}

		$user = $userManager->findUserByUsername($username);
		if (is_null($user)) {
			throw $this->createNotFoundException('User not found');
		}

		$user->setEnabled(true);
		$userManager->updateUser($user);

		// Search index update
		$searchUtils->insertEntityToIndex($user);

		// Flashbag
		$this->addFlash('success', $translator->trans('user.admin.alert.activate_success', array( '%displayname%' => $user->getDisplayname() )));

		return $this->redirect($this->generateUrl('core_user_show_admin', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/admin/deactivate", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_admin_deactivate")
	 */
	public function adminDeactivateAction($username, SearchUtils $searchUtils, TranslatorInterface $translator, UserManager $userManager) {
		if (!$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Access denied');
		}

		$user = $userManager->findUserByUsername($username);
		if (is_null($user)) {
			throw $this->createNotFoundException('User not found');
		}

		$user->setEnabled(false);
		$userManager->updateUser($user);

		// Search index update
		$searchUtils->deleteEntityFromIndex($user);

		// Flashbag
		$this->addFlash('success', $translator->trans('user.admin.alert.deactivate_success', array( '%displayname%' => $user->getDisplayname() )));

		return $this->redirect($this->generateUrl('core_user_show_admin', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/admin/empty", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_admin_empty")
	 */
	public function adminEmptyAction($username, FollowerUtils $followerUtils, LikableUtils $likableUtils, EntityManagerInterface $om, SearchUtils $searchUtils, TranslatorInterface $translator, UserManager $userManager) {
		if (!$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Access denied');
		}

		$user = $userManager->findUserByUsername($username);
		if (is_null($user)) {
			throw $this->createNotFoundException('User not found');
		}

		// Remove likes
		$likableUtils->deleteLikesByUser($user, false);

		// Remove followers / followings
		$followerUtils->deleteFollowersByUser($user, false);
		$followerUtils->deleteFollowingsByUser($user, false);

		// TODO

		$om->flush();

		// Search index update
		$searchUtils->replaceEntityInIndex($user);

		// Flashbag
		$this->addFlash('success', $translator->trans('user.admin.alert.empty_success', array( '%displayname%' => $user->getDisplayname() )));

		return $this->redirect($this->generateUrl('core_user_show_admin', array( 'username' => $username )));
	}

	/**
	 * @Route("/@{username}/admin/delete", requirements={"username" = "^[a-zA-Z0-9.]{3,25}$"}, name="core_user_admin_delete")
	 */
	public function adminDeleteAction($username, EntityManagerInterface $om, SearchUtils $searchUtils, TranslatorInterface $translator, UserManager $userManager) {
		if (!$this->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Access denied');
		}

		$user = $userManager->findUserByUsername($username);
		if (is_null($user)) {
			throw $this->createNotFoundException('User not found');
		}

		$avatar = $user->getAvatar();
		$banner = $user->getMeta()->getBanner();

		// Its publications, comments, likes, messages... reference the user: the database would refuse its deletion
		$userRepository = $om->getRepository(User::CLASS_NAME);
		if ($userRepository->existsReferences($user, array_filter(array( $avatar, $banner )))) {

			// Flashbag
			$this->addFlash('error', $translator->trans('user.admin.alert.delete_error', array( '%displayname%' => $user->getDisplayname() )));

			return $this->redirect($this->generateUrl('core_user_show_admin', array( 'username' => $username )));
		}

		$user->setAvatar(null);
		$user->getMeta()->setBanner(null);

		// The avatar and the banner reference the user: detach them to remove them (with their files) only once the user is, in case its deletion fails
		if (!is_null($avatar)) {
			$avatar->setUser(null);
		}
		if (!is_null($banner)) {
			$banner->setUser(null);
		}

		$om->remove($user);
		$om->flush();

		if (!is_null($avatar)) {
			$om->remove($avatar);
		}
		if (!is_null($banner)) {
			$om->remove($banner);
		}
		$om->flush();

		// Search index update
		$searchUtils->deleteEntityFromIndex($user);

		// Flashbag
		$this->addFlash('success', $translator->trans('user.admin.alert.delete_success', array( '%displayname%' => $user->getDisplayname() )));

		return $this->redirect($this->generateUrl('core_welcome'));
	}

}