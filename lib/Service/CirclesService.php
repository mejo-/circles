<?php
/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @author Vinicius Cubas Brand <vinicius@eita.org.br>
 * @author Daniel Tygel <dtygel@eita.org.br>
 *
 * @copyright 2017
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Circles\Service;


use Exception;
use OC;
use OCA\Circles\AppInfo\Application;
use OCA\Circles\Db\CircleProviderRequest;
use OCA\Circles\Db\CirclesRequest;
use OCA\Circles\Db\FederatedLinksRequest;
use OCA\Circles\Db\MembersRequest;
use OCA\Circles\Db\SharesRequest;
use OCA\Circles\Exceptions\CircleAlreadyExistsException;
use OCA\Circles\Exceptions\CircleDoesNotExistException;
use OCA\Circles\Exceptions\CircleTypeDisabledException;
use OCA\Circles\Exceptions\ConfigNoCircleAvailableException;
use OCA\Circles\Exceptions\FederatedCircleNotAllowedException;
use OCA\Circles\Exceptions\MemberDoesNotExistException;
use OCA\Circles\Exceptions\MemberIsNotOwnerException;
use OCA\Circles\Exceptions\MembersLimitException;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\GlobalScale\GSEvent;
use OCA\Circles\Model\Member;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;

class CirclesService {

	/** @var string */
	private $userId;

	/** @var IL10N */
	private $l10n;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ConfigService */
	private $configService;

	/** @var CirclesRequest */
	private $circlesRequest;

	/** @var MembersRequest */
	private $membersRequest;

	/** @var SharesRequest */
	private $sharesRequest;

	/** @var FederatedLinksRequest */
	private $federatedLinksRequest;

	/** @var GSUpstreamService */
	private $gsUpstreamService;

	/** @var EventsService */
	private $eventsService;

	/** @var CircleProviderRequest */
	private $circleProviderRequest;

	/** @var MiscService */
	private $miscService;


	/**
	 * CirclesService constructor.
	 *
	 * @param string $userId
	 * @param IL10N $l10n
	 * @param IUserSession $userSession
	 * @param IGroupManager $groupManager
	 * @param ConfigService $configService
	 * @param CirclesRequest $circlesRequest
	 * @param MembersRequest $membersRequest
	 * @param SharesRequest $sharesRequest
	 * @param FederatedLinksRequest $federatedLinksRequest
	 * @param GSUpstreamService $gsUpstreamService
	 * @param EventsService $eventsService
	 * @param CircleProviderRequest $circleProviderRequest
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId,
		IL10N $l10n,
		IUserSession $userSession,
		IGroupManager $groupManager,
		ConfigService $configService,
		CirclesRequest $circlesRequest,
		MembersRequest $membersRequest,
		SharesRequest $sharesRequest,
		FederatedLinksRequest $federatedLinksRequest,
		GSUpstreamService $gsUpstreamService,
		EventsService $eventsService,
		CircleProviderRequest $circleProviderRequest,
		MiscService $miscService
	) {

		if ($userId === null) {
			$user = $userSession->getUser();
			if ($user !== null) {
				$userId = $user->getUID();
			}
		}

		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->groupManager = $groupManager;
		$this->configService = $configService;
		$this->circlesRequest = $circlesRequest;
		$this->membersRequest = $membersRequest;
		$this->sharesRequest = $sharesRequest;
		$this->federatedLinksRequest = $federatedLinksRequest;
		$this->gsUpstreamService = $gsUpstreamService;
		$this->eventsService = $eventsService;
		$this->circleProviderRequest = $circleProviderRequest;
		$this->miscService = $miscService;
	}


	/**
	 * Create circle using this->userId as owner
	 *
	 * @param int|string $type
	 * @param string $name
	 *
	 * @param string $ownerId
	 *
	 * @return Circle
	 * @throws CircleAlreadyExistsException
	 * @throws CircleTypeDisabledException
	 * @throws Exception
	 */
	public function createCircle($type, $name, string $ownerId = '') {
		$type = $this->convertTypeStringToBitValue($type);
		$type = (int)$type;

		if ($type === '') {
			throw new CircleTypeDisabledException(
				$this->l10n->t('You need a specify a type of circle')
			);
		}

		if (!$this->configService->isCircleAllowed($type)) {
			throw new CircleTypeDisabledException(
				$this->l10n->t('You cannot create this type of circle')
			);
		}

		$circle = new Circle($type, $name);
		if ($ownerId === '') {
			$ownerId = $this->userId;
		}

		if (!$this->circlesRequest->isCircleUnique($circle, $ownerId)) {
			throw new CircleAlreadyExistsException(
				$this->l10n->t('A circle with that name exists')
			);
		}

		$circle->generateUniqueId();

		$owner = new Member($ownerId, Member::TYPE_USER);
		$owner->setCircleId($circle->getUniqueId())
			  ->setLevel(Member::LEVEL_OWNER)
			  ->setStatus(Member::STATUS_MEMBER);
		$circle->setOwner($owner)
			   ->setViewer($owner);

		$event = new GSEvent(GSEvent::CIRCLE_CREATE, true);
		$event->setCircle($circle);
		$this->gsUpstreamService->newEvent($event);

		return $circle;
	}


	/**
	 * list Circles depends on type (or all) and name (parts) and minimum level.
	 *
	 * @param string $userId
	 * @param mixed $type
	 * @param string $name
	 * @param int $level
	 *
	 * @param bool $forceAll
	 *
	 * @return Circle[]
	 * @throws CircleTypeDisabledException
	 * @throws Exception
	 */
	public function listCircles($userId, $type, $name = '', $level = 0, $forceAll = false) {
		$type = $this->convertTypeStringToBitValue($type);

		if ($userId === '') {
			throw new Exception('UserID cannot be null');
		}

		if (!$this->configService->isCircleAllowed((int)$type)) {
			throw new CircleTypeDisabledException(
				$this->l10n->t('You cannot display this type of circle')
			);
		}

		$data = [];
		$result = $this->circlesRequest->getCircles($userId, $type, $name, $level, $forceAll);
		foreach ($result as $item) {
			$data[] = $item;
		}

		return $data;
	}


	/**
	 * returns details on circle and its members if this->userId is a member itself.
	 *
	 * @param string $circleUniqueId
	 * @param bool $forceAll
	 *
	 * @return Circle
	 * @throws Exception
	 */
	public function detailsCircle($circleUniqueId, $forceAll = false) {

		try {
			$circle = $this->circlesRequest->getCircle(
				$circleUniqueId, $this->userId, Member::TYPE_USER, '', $forceAll
			);
			if ($this->viewerIsAdmin()
				|| $circle->getHigherViewer()
						  ->isLevel(Member::LEVEL_MEMBER)
				|| $forceAll === true
			) {
				$this->detailsCircleMembers($circle);
				$this->detailsCircleLinkedGroups($circle);
				$this->detailsCircleFederatedCircles($circle);
			}
		} catch (Exception $e) {
			throw $e;
		}

		return $circle;
	}


	/**
	 * get the Members list and add the result to the Circle.
	 *
	 * @param Circle $circle
	 *
	 * @throws Exception
	 */
	private function detailsCircleMembers(Circle &$circle) {
		if ($this->viewerIsAdmin()) {
			$members = $this->membersRequest->forceGetMembers($circle->getUniqueId(), 0);
		} else {
			$members = $this->membersRequest->getMembers(
				$circle->getUniqueId(), $circle->getHigherViewer()
			);
		}

		$circle->setMembers($members);
	}


	/**
	 * // TODO - check this on GS setup
	 * get the Linked Group list and add the result to the Circle.
	 *
	 * @param Circle $circle
	 *
	 * @throws MemberDoesNotExistException
	 */
	private function detailsCircleLinkedGroups(Circle &$circle) {
		$groups = [];
		if ($this->configService->isLinkedGroupsAllowed()) {
			$groups =
				$this->membersRequest->getGroupsFromCircle(
					$circle->getUniqueId(), $circle->getHigherViewer()
				);
		}

		$circle->setGroups($groups);
	}


	/**
	 * get the Federated Circles list and add the result to the Circle.
	 *
	 * @param Circle $circle
	 */
	private function detailsCircleFederatedCircles(Circle &$circle) {
		$links = [];

		try {
			if ($this->configService->isFederatedCirclesAllowed()) {
				$circle->hasToBeFederated();
				$links = $this->federatedLinksRequest->getLinksFromCircle($circle->getUniqueId());
			}
		} catch (FederatedCircleNotAllowedException $e) {
		}

		$circle->setLinks($links);
	}


	/**
	 * save new settings if current user is admin.
	 *
	 * @param string $circleUniqueId
	 * @param array $settings
	 *
	 * @return Circle
	 * @throws Exception
	 */
	public function settingsCircle(string $circleUniqueId, array $settings) {
		$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
		$this->hasToBeOwner($circle->getHigherViewer());

		if (!$this->viewerIsAdmin()) {
			$settings['members_limit'] = $circle->getSetting('members_limit');
		}

		// can only be run from the instance of the circle's owner.
		$event = new GSEvent(GSEvent::CIRCLE_UPDATE);
		$event->setCircle($circle);
		$event->getData()
			  ->sBool('local_admin', $this->viewerIsAdmin())
			  ->sArray('settings', $settings);

		$this->gsUpstreamService->newEvent($event);

		return $circle;
	}


	/**
	 * Join a circle.
	 *
	 * @param string $circleUniqueId
	 *
	 * @return null|Member
	 * @throws Exception
	 */
	public function joinCircle($circleUniqueId) {
		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			$member = $this->membersRequest->getFreshNewMember(
				$circleUniqueId, $this->userId, Member::TYPE_USER, ''
			);

			$event = new GSEvent(GSEvent::MEMBER_JOIN);
			$event->setCircle($circle);
			$event->setMember($member);
			$this->gsUpstreamService->newEvent($event);
		} catch (Exception $e) {
			throw $e;
		}

		return $member;
	}


	/**
	 * Leave a circle.
	 *
	 * @param string $circleUniqueId
	 *
	 * @return null|Member
	 * @throws Exception
	 */
	public function leaveCircle($circleUniqueId) {
		$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
		$member = $circle->getViewer();

		$event = new GSEvent(GSEvent::MEMBER_LEAVE);
		$event->setCircle($circle);
		$event->setMember($member);
		$this->gsUpstreamService->newEvent($event);

		return $member;
	}


	/**
	 * destroy a circle.
	 *
	 * @param string $circleUniqueId
	 *
	 * @param bool $force
	 *
	 * @throws CircleDoesNotExistException
	 * @throws MemberIsNotOwnerException
	 * @throws ConfigNoCircleAvailableException
	 * @throws Exception
	 */
	public function removeCircle($circleUniqueId, bool $force = false) {
		if ($force) {
			$circle = $this->circlesRequest->forceGetCircle($circleUniqueId);
		} else {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			$this->hasToBeOwner($circle->getHigherViewer());
		}

		// removing a Circle is done only by owner, so can already be done by local user, or admin, or occ
		// at this point, we already know that all condition are filled. we can force it.
		$event = new GSEvent(GSEvent::CIRCLE_DESTROY, false, true);
		$event->setCircle($circle);

		$this->gsUpstreamService->newEvent($event);
	}


	/**
	 * @param $circleName
	 *
	 * @return Circle|null
	 * @throws CircleDoesNotExistException
	 */
	public function infoCircleByName($circleName) {
		return $this->circlesRequest->forceGetCircleByName($circleName);
	}


	/**
	 * Convert a Type in String to its Bit Value
	 *
	 * @param string $type
	 *
	 * @return int|mixed
	 */
	public function convertTypeStringToBitValue($type) {
		$strings = [
			'personal' => Circle::CIRCLES_PERSONAL,
			'secret'   => Circle::CIRCLES_SECRET,
			'closed'   => Circle::CIRCLES_CLOSED,
			'public'   => Circle::CIRCLES_PUBLIC,
			'all'      => Circle::CIRCLES_ALL
		];

		if (!key_exists(strtolower($type), $strings)) {
			return $type;
		}

		return $strings[strtolower($type)];
	}


	/**
	 * getCircleIcon()
	 *
	 * Return the right imagePath for a type of circle.
	 *
	 * @param string $type
	 * @param bool $png
	 *
	 * @return string
	 */
	public static function getCircleIcon($type, $png = false) {

		$ext = '.svg';
		if ($png === true) {
			$ext = '.png';
		}

		$urlGen = OC::$server->getURLGenerator();
		switch ($type) {
			case Circle::CIRCLES_PERSONAL:
				return $urlGen->getAbsoluteURL(
					$urlGen->imagePath(Application::APP_NAME, 'personal' . $ext)
				);
			case Circle::CIRCLES_CLOSED:
				return $urlGen->getAbsoluteURL(
					$urlGen->imagePath(Application::APP_NAME, 'closed' . $ext)
				);
			case Circle::CIRCLES_SECRET:
				return $urlGen->getAbsoluteURL(
					$urlGen->imagePath(Application::APP_NAME, 'secret' . $ext)
				);
			case Circle::CIRCLES_PUBLIC:
				return $urlGen->getAbsoluteURL(
					$urlGen->imagePath(Application::APP_NAME, 'black_circle' . $ext)
				);
		}

		return $urlGen->getAbsoluteURL(
			$urlGen->imagePath(Application::APP_NAME, 'black_circle' . $ext)
		);
	}


	/**
	 * @param string $circleUniqueIds
	 * @param int $limit
	 * @param int $offset
	 *
	 * @return array
	 */
	public function getFilesForCircles($circleUniqueIds, $limit = -1, $offset = 0) {
		if (!is_array($circleUniqueIds)) {
			$circleUniqueIds = [$circleUniqueIds];
		}

		$objectIds = $this->circleProviderRequest->getFilesForCircles(
			$this->userId, $circleUniqueIds, $limit, $offset
		);

		return $objectIds;
	}


	/**
	 * @param Circle $circle
	 *
	 * @throws MembersLimitException
	 */
	public function checkThatCircleIsNotFull(Circle $circle) {
		$members =
			$this->membersRequest->forceGetMembers($circle->getUniqueId(), Member::LEVEL_MEMBER, 0, true);

		$limit = (int)$circle->getSetting('members_limit');
		if ($limit === -1) {
			return;
		}
		if ($limit === 0) {
			$limit = $this->configService->getAppValue(ConfigService::CIRCLES_MEMBERS_LIMIT);
		}

		if (sizeof($members) >= $limit) {
			throw new MembersLimitException(
				'This circle already reach its limit on the number of members'
			);
		}

	}

	/**
	 * @return bool
	 */
	public function viewerIsAdmin(): bool {
		if ($this->userId === '') {
			return false;
		}

		return ($this->groupManager->isAdmin($this->userId));
	}


	/**
	 * should be moved.
	 *
	 * @param Member $member
	 *
	 * @throws MemberIsNotOwnerException
	 */
	public function hasToBeOwner(Member $member) {
		if (!$this->groupManager->isAdmin($this->userId)
			&& $member->getLevel() < Member::LEVEL_OWNER) {
			throw new MemberIsNotOwnerException(
				$this->l10n->t('This member is not the owner of the circle')
			);
		}
	}


	/**
	 * should be moved.
	 *
	 * @param Member $member
	 *
	 * @throws MemberIsNotOwnerException
	 */
	public function hasToBeAdmin(Member $member) {
		if (!$this->groupManager->isAdmin($member->getUserId())
			&& $member->getLevel() < Member::LEVEL_ADMIN) {
			throw new MemberIsNotOwnerException(
				$this->l10n->t('This member is not an admin of the circle')
			);
		}
	}
}
