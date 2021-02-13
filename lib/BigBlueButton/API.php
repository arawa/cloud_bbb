<?php

namespace OCA\BigBlueButton\BigBlueButton;

use BigBlueButton\BigBlueButton;
use BigBlueButton\Core\Record;
use BigBlueButton\Parameters\CreateMeetingParameters;
use BigBlueButton\Parameters\DeleteRecordingsParameters;
use BigBlueButton\Parameters\GetRecordingsParameters;
use BigBlueButton\Parameters\IsMeetingRunningParameters;
use BigBlueButton\Parameters\JoinMeetingParameters;
use OCA\BigBlueButton\Crypto;
use OCA\BigBlueButton\Db\Room;
use OCA\BigBlueButton\Event\MeetingStartedEvent;
use OCA\BigBlueButton\UrlHelper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;

class API {
	/** @var IConfig */
	private $config;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var BigBlueButton|null */
	private $server;

	/** @var Crypto */
	private $crypto;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var IL10N */
	private $l10n;

	/** @var UrlHelper */
	private $urlHelper;

	public function __construct(
		IConfig $config,
		IURLGenerator $urlGenerator,
		Crypto $crypto,
		IEventDispatcher $eventDispatcher,
		IL10N $l10n,
		UrlHelper $urlHelper
	) {
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->crypto = $crypto;
		$this->eventDispatcher = $eventDispatcher;
		$this->l10n = $l10n;
		$this->urlHelper = $urlHelper;
	}

	private function getServer() {
		if (!$this->server) {
			$apiUrl = $this->config->getAppValue('bbb', 'api.url');
			$secret = $this->config->getAppValue('bbb', 'api.secret');

			$this->server = new BigBlueButton($apiUrl, $secret);
		}

		return $this->server;
	}

	/**
	 * Create join url.
	 *
	 * @return string join url
	 */
	public function createJoinUrl(Room $room, float $creationTime, string $displayname, bool $isModerator, ?string $uid = null) {
		$password = $isModerator ? $room->moderatorPassword : $room->attendeePassword;

		$joinMeetingParams = new JoinMeetingParameters($room->uid, $displayname, $password);

		// ensure that float is not converted to a string in scientific notation
		$joinMeetingParams->setCreationTime(sprintf("%.0f", $creationTime));
		$joinMeetingParams->setJoinViaHtml5(true);
		$joinMeetingParams->setRedirect(true);
		$joinMeetingParams->setGuest($uid === null);

		if ($uid) {
			$joinMeetingParams->setUserId($uid);
			// $joinMeetingParams->setAvatarURL();
		}

		return $this->getServer()->getJoinMeetingURL($joinMeetingParams);
	}

	/**
	 * Create meeting room.
	 *
	 * @return int creation time
	 */
	public function createMeeting(Room $room, Presentation $presentation = null) {
		$bbb = $this->getServer();
		$meetingParams = $this->buildMeetingParams($room, $presentation);

		try {
			$response = $bbb->createMeeting($meetingParams);
		} catch (\Exception $e) {
			throw new \Exception('Can not process create request: ' . $bbb->getCreateMeetingUrl($meetingParams));
		}

		if (!$response->success()) {
			throw new \Exception('Can not create meeting');
		}

		if ($response->getMessageKey() !== 'duplicateWarning') {
			$this->eventDispatcher->dispatch(MeetingStartedEvent::class, new MeetingStartedEvent($room));
		}

		return $response->getCreationTime();
	}

	private function buildMeetingParams(Room $room, Presentation $presentation = null): CreateMeetingParameters {
		$createMeetingParams = new CreateMeetingParameters($room->uid, $room->name);
		$createMeetingParams->setAttendeePassword($room->attendeePassword);
		$createMeetingParams->setModeratorPassword($room->moderatorPassword);
		$createMeetingParams->setRecord($room->record);
		$createMeetingParams->setAllowStartStopRecording($room->record);
		$createMeetingParams->setLogoutUrl($this->urlGenerator->getBaseUrl());

		$mac = $this->crypto->calculateHMAC($room->uid);

		$endMeetingUrl = $this->urlGenerator->linkToRouteAbsolute('bbb.hook.meetingEnded', ['token' => $room->uid, 'mac' => $mac]);
		$createMeetingParams->setEndCallbackUrl($endMeetingUrl);

		$recordingReadyUrl = $this->urlGenerator->linkToRouteAbsolute('bbb.hook.recordingReady', ['token' => $room->uid, 'mac' => $mac]);
		$createMeetingParams->setRecordingReadyCallbackUrl($recordingReadyUrl);

		$invitationUrl = $this->urlHelper->linkToInvitationAbsolute($room);
		$createMeetingParams->setModeratorOnlyMessage($this->l10n->t('To invite someone to the meeting, send them this link: %s', [$invitationUrl]));

		if (!empty($room->welcome)) {
			$createMeetingParams->setWelcomeMessage($room->welcome);
		}

		if ($room->maxParticipants > 0) {
			$createMeetingParams->setMaxParticipants($room->maxParticipants);
		}

		if ($presentation !== null && $presentation->isValid()) {
			$createMeetingParams->addPresentation($presentation->getUrl(), null, $presentation->getFilename());
		}

		if ($room->access === Room::ACCESS_WAITING_ROOM) {
			$createMeetingParams->setGuestPolicyAskModerator();
		}

		return $createMeetingParams;
	}

	public function getRecording(string $recordId) {
		$recordingParams = new GetRecordingsParameters();
		$recordingParams->setRecordId($recordId);
		$recordingParams->setState('any');

		$response = $this->getServer()->getRecordings($recordingParams);

		if (!$response->success()) {
			throw new \Exception('Could not process get recording request');
		}

		$records = $response->getRecords();

		if (count($records) === 0) {
			throw new \Exception('Found no record with given id');
		}

		return $this->recordToArray($records[0]);
	}

	public function getRecordings(Room $room) {
		$recordingParams = new GetRecordingsParameters();
		$recordingParams->setMeetingId($room->uid);
		$recordingParams->setState('processing,processed,published,unpublished');

		$response = $this->getServer()->getRecordings($recordingParams);

		if (!$response->success()) {
			throw new \Exception('Could not process get recordings request');
		}

		$records = $response->getRecords();

		return array_map(function ($record) {
			return $this->recordToArray($record);
		}, $records);
	}

	public function deleteRecording(string $recordingId): bool {
		$deleteParams = new DeleteRecordingsParameters($recordingId);

		$response = $this->getServer()->deleteRecordings($deleteParams);

		return $response->isDeleted();
	}

	private function recordToArray(Record $record) {
		return [
			'id'           => $record->getRecordId(),
			'meetingId'    => $record->getMeetingId(),
			'name'         => $record->getName(),
			'published'    => $record->isPublished(),
			'state'        => $record->getState(),
			'startTime'    => $record->getStartTime(),
			'participants' => $record->getParticipantCount(),
			'type'         => $record->getPlaybackType(),
			'length'       => $record->getPlaybackLength(),
			'url'          => $record->getPlaybackUrl(),
			'metas'        => $record->getMetas(),
		];
	}

	public function check(string $url, string $secret) {
		$server = new BigBlueButton($url, $secret);

		$meetingParams = new IsMeetingRunningParameters('foobar');

		try {
			$response = $server->isMeetingRunning($meetingParams);

			if (!$response->success() && !$response->failed()) {
				return 'invalid-url';
			}

			if (!$response->success()) {
				return 'invalid-secret';
			}

			return 'success';
		} catch (\Exception $e) {
			return 'invalid-url';
		}
	}

	/**
	 * @param null|string $url
	 */
	public function getVersion(?string $url = null) {
		$server = $url === null ? $this->getServer() : new BigBlueButton($url, '');

		return $server->getApiVersion()->getVersion();
	}

	public function isRunning(Room $room): bool {
		$isMeetingRunningParams = new IsMeetingRunningParameters($room->getUid());

		$response = $this->getServer()->isMeetingRunning($isMeetingRunningParams);

		return $response->success() && $response->isRunning();
	}
}
