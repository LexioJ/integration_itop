<?php

/**
 * Nextcloud - iTop
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Controller;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\NewsroomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Controller for iTop Newsroom notification actions.
 *
 * Provides the mark-as-read endpoint that is called when a user clicks
 * "Mark as read" on a mirrored iTop Newsroom Nextcloud notification.
 */
class NewsroomController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private NewsroomService $newsroomService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Mark an iTop Newsroom item as read and dismiss the Nextcloud notification.
	 *
	 * The endpoint validates that the newsroom item belongs to the current user
	 * by embedding the user's person_id in the iTop OQL update query.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $nid Newsroom item ID (EventNotificationNewsroom key)
	 * @return DataResponse
	 */
	public function markAsRead(string $nid): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(
				['error' => 'Unauthorized'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$result = $this->newsroomService->markAsRead($this->userId, $nid);

		if ($result['success']) {
			return new DataResponse(['status' => 'ok']);
		}

		return new DataResponse(
			['error' => $result['error'] ?? 'Unknown error'],
			Http::STATUS_BAD_REQUEST
		);
	}
}
