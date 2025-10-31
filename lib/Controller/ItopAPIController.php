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
use OCA\Itop\Service\ItopAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

use Psr\Log\LoggerInterface;

class ItopAPIController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ItopAPIService $itopAPIService,
		private IConfig $config,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ?string $userId
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get user created tickets
	 *
	 * @NoAdminRequired
	 *
	 * @param int $limit
	 * @return DataResponse
	 */
	public function getTickets(int $limit = 10): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$tickets = $this->itopAPIService->getUserCreatedTickets($this->userId, null, $limit);
			return new DataResponse($tickets);
		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop tickets: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get count of user created tickets
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getTicketsCount(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$ticketCount = $this->itopAPIService->getUserCreatedTicketsCount($this->userId);
			return new DataResponse($ticketCount);
		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop ticket count: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get specific ticket details
	 *
	 * @NoAdminRequired
	 *
	 * @param int $ticketId
	 * @return DataResponse
	 */
	public function getTicket(int $ticketId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$ticket = $this->itopAPIService->getTicketInfo($this->userId, $ticketId);
			return new DataResponse($ticket);
		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop ticket: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Search iTop objects
	 *
	 * @NoAdminRequired
	 *
	 * @param string $term
	 * @param int $offset
	 * @param int $limit
	 * @return DataResponse
	 */
	public function search(string $term, int $offset = 0, int $limit = 10): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$results = $this->itopAPIService->search($this->userId, $term, $offset, $limit);
			return new DataResponse($results);
		} catch (\Exception $e) {
			$this->logger->error('Error searching iTop: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get Configuration Items
	 *
	 * @NoAdminRequired
	 *
	 * @param int $limit
	 * @return DataResponse
	 */
	public function getCIs(int $limit = 10): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$results = $this->itopAPIService->search($this->userId, '', 0, $limit);
			// Filter to only CIs
			$cis = array_filter($results, function($item) {
				return $item['type'] === 'FunctionalCI';
			});
			return new DataResponse(array_values($cis));
		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop CIs: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get avatar/image - placeholder for future implementation
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getAvatar(): DataResponse {
		// Placeholder - iTop doesn't have user avatars like Zammad
		// This could be extended to return organization logos or CI images
		return new DataResponse(['message' => 'Avatar functionality not implemented for iTop'], Http::STATUS_NOT_IMPLEMENTED);
	}

	/**
	 * Get dashboard data for current user
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getDashboardData(): DataResponse {
		$this->logger->info('getDashboardData called for user: ' . ($this->userId ?? 'null'), ['app' => Application::APP_ID]);
		
		if ($this->userId === null) {
			$this->logger->warning('getDashboardData: No user ID', ['app' => Application::APP_ID]);
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->logger->debug('Fetching ticket counts...', ['app' => Application::APP_ID]);
			// Ticket counts (UserRequest + Incident)
			$counts = $this->itopAPIService->getUserCreatedTicketsCount($this->userId);
			$this->logger->debug('Counts: ' . json_encode($counts), ['app' => Application::APP_ID]);

			$this->logger->debug('Fetching tickets by status...', ['app' => Application::APP_ID]);
			// Status breakdown and grouped tickets
			$byStatus = $this->itopAPIService->getUserTicketsByStatus($this->userId);
			$this->logger->debug('ByStatus: ' . json_encode($byStatus), ['app' => Application::APP_ID]);

			// Get iTop URL for "View All" button
			$itopUrl = $this->itopAPIService->getItopUrl($this->userId);
			$this->logger->debug('iTop URL: ' . $itopUrl, ['app' => Application::APP_ID]);

			// Get admin-configured display name (e.g., "ServicePoint" instead of "iTop")
			$displayName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');

			$response = [
				'counts' => $counts,
				'stats' => [
					'by_status' => $byStatus['by_status'] ?? [],
				],
				'tickets' => $byStatus['tickets'] ?? [],
				'recent_cis' => [],
				'itop_url' => $itopUrl,
				'display_name' => $displayName
			];
			
			$this->logger->info('getDashboardData success, returning data', ['app' => Application::APP_ID]);
			return new DataResponse($response);
		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop dashboard data: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e->getTraceAsString()
			]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
