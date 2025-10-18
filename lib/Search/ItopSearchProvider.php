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

namespace OCA\Itop\Search;

use DateTime;
use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\ItopAPIService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

use Psr\Log\LoggerInterface;

class ItopSearchProvider implements IProvider {

	public function __construct(
		private IAppManager $appManager,
		private IL10N $l10n,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IDateTimeFormatter $dateTimeFormatter,
		private ItopAPIService $itopAPIService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'integration_itop';
	}

	public function getName(): string {
		$displayName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');
		return $displayName;
	}

	public function getOrder(string $route, array $routeParameters): int {
		if (strpos($route, Application::APP_ID . '.') === 0) {
			// Active app, prefer iTop results
			return -1;
		}

		return 20;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return SearchResult::complete($this->getName(), []);
		}

		$userId = $user->getUID();

		// Check for person_id configuration
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		if ($personId === '') {
			return SearchResult::complete($this->getName(), []);
		}

		// Check if search is enabled in user preferences (default: enabled - opt-out)
		$searchEnabled = $this->config->getUserValue($userId, Application::APP_ID, 'search_enabled', '1') === '1';
		if (!$searchEnabled) {
			return SearchResult::complete($this->getName(), []);
		}

		$term = $query->getTerm();
		$offset = (int) $query->getCursor();
		$limit = $query->getLimit();

		try {
			$searchResults = $this->itopAPIService->search($userId, $term, $offset, $limit);

			if (isset($searchResults['error'])) {
				$this->logger->error('Error searching iTop: ' . $searchResults['error'], ['app' => Application::APP_ID]);
				return SearchResult::complete($this->getName(), []);
			}

			$formattedResults = array_map(function (array $entry): SearchResultEntry {
				// Format title with status emoji + ref ID prefix
				// Example: ✅ [I-000006] Incident Title
				$statusEmoji = $this->getStatusEmoji($entry['status'] ?? '');
				$title = $entry['title'];
				if (!empty($entry['ref'])) {
					$title = $statusEmoji . ' [' . $entry['ref'] . '] ' . $title;
				} else {
					$title = $statusEmoji . ' ' . $title;
				}

				return new ItopSearchResultEntry(
					$this->getThumbnailUrl($entry),
					$title,
					$this->formatDescription($entry),
					$entry['url'],
					$this->getIconUrl($entry['type']),
					true
				);
			}, $searchResults);

			return SearchResult::paginated($this->getName(), $formattedResults, $offset + $limit);
		} catch (\Exception $e) {
			$this->logger->error('Error searching iTop: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return SearchResult::complete($this->getName(), []);
		}
	}

	protected function getThumbnailUrl(array $entry): string {
		// Return empty string to disable thumbnail (icon is sufficient)
		return '';
	}

	protected function getIconUrl(string $type): string {
		switch ($type) {
			case 'UserRequest':
				return $this->urlGenerator->imagePath(Application::APP_ID, 'ticket.svg');
			case 'Incident':
				return $this->urlGenerator->imagePath(Application::APP_ID, 'ticket.svg');
			case 'FunctionalCI':
				return $this->urlGenerator->imagePath(Application::APP_ID, 'ci.svg');
			default:
				return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
		}
	}

	protected function formatDescription(array $entry): string {
		$parts = [];

		// Priority with emoji (moved to front since status is now in title)
		if (!empty($entry['priority'])) {
			$priorityEmoji = $this->getPriorityEmoji($entry['priority']);
			$parts[] = $priorityEmoji . ' P' . $entry['priority'];
		}

		// Agent assignment with emoji
		if (!empty($entry['agent'])) {
			$parts[] = '👤 ' . $this->truncate($entry['agent'], 20);
		}

		// Timestamp with emoji
		$timeInfo = $this->getTimeInfo($entry);
		if ($timeInfo) {
			$parts[] = $timeInfo;
		}

		// Add description snippet - now with more space available
		if (!empty($entry['description'])) {
			$description = strip_tags($entry['description']);
			// Increased from 80 to 150 chars since we have more space
			if (strlen($description) > 150) {
				$description = substr($description, 0, 147) . '...';
			}
			$parts[] = $description;
		}

		return implode(' • ', $parts);
	}

	/**
	 * Get emoji for ticket status
	 *
	 * @param string $status
	 * @return string
	 */
	private function getStatusEmoji(string $status): string {
		$status = strtolower($status);

		if ($status === 'new') {
			return '🆕';
		} elseif ($status === 'assigned') {
			return '👥';
		} elseif (in_array($status, ['pending', 'waiting_for_approval'])) {
			return '⏳';
		} elseif ($status === 'resolved') {
			return '✅';
		} elseif ($status === 'closed') {
			return '☑️';
		} elseif (in_array($status, ['escalated_tto', 'escalated_ttr'])) {
			return '⚠️';
		}

		return '⚪';
	}

	/**
	 * Get emoji for priority level
	 *
	 * @param mixed $priority
	 * @return string
	 */
	private function getPriorityEmoji($priority): string {
		$priorityNum = is_numeric($priority) ? (int)$priority : 0;

		if ($priorityNum === 1) {
			return '🔴'; // Critical
		} elseif ($priorityNum === 2) {
			return '🟠'; // High
		} elseif ($priorityNum === 3) {
			return '🟡'; // Medium
		} elseif ($priorityNum === 4) {
			return '🟢'; // Low
		}

		return '⚪';
	}

	/**
	 * Get formatted time information for a ticket
	 *
	 * @param array $entry
	 * @return string|null
	 */
	private function getTimeInfo(array $entry): ?string {
		// iTop returns dates in server local time without timezone info
		// Format: "2025-10-12 19:05:47"
		// We need to parse with the iTop server's timezone (Europe/Vienna / UTC+2)

		// Use Nextcloud's configured timezone or fallback to Europe/Vienna
		$configuredTz = $this->config->getSystemValue('default_timezone', 'Europe/Vienna');
		$serverTimezone = new \DateTimeZone($configuredTz);

		// Closed tickets - show when closed
		if (!empty($entry['close_date'])) {
			try {
				$dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $entry['close_date'], $serverTimezone);
				if ($dateTime) {
					$time = $this->formatPreciseTime($dateTime);
					return '🏁 ' . $this->l10n->t('closed %s', [$time]);
				}
			} catch (\Exception $e) {
				// Ignore invalid dates
			}
		}

		// Active tickets - show last update
		if (!empty($entry['last_update'])) {
			try {
				$dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $entry['last_update'], $serverTimezone);
				if ($dateTime) {
					$time = $this->formatPreciseTime($dateTime);
					return '🕐 ' . $this->l10n->t('updated %s', [$time]);
				}
			} catch (\Exception $e) {
				// Ignore invalid dates
			}
		}

		// New tickets - show start date
		if (!empty($entry['start_date'])) {
			try {
				$dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $entry['start_date'], $serverTimezone);
				if ($dateTime) {
					$time = $this->formatPreciseTime($dateTime);
					return '🆕 ' . $this->l10n->t('created %s', [$time]);
				}
			} catch (\Exception $e) {
				// Ignore invalid dates
			}
		}

		return null;
	}

	/**
	 * Format time with more precision than standard formatTimeSpan
	 * Shows exact minutes/hours for recent times, then falls back to days
	 *
	 * @param DateTime $dateTime
	 * @return string
	 */
	private function formatPreciseTime(DateTime $dateTime): string {
		// Get current time and calculate difference using timestamps (timezone-independent)
		$now = new DateTime();
		$diff = $now->getTimestamp() - $dateTime->getTimestamp();

		// Less than 1 minute
		if ($diff < 60) {
			return 'just now';
		}

		// Less than 1 hour - show minutes
		if ($diff < 3600) {
			$minutes = floor($diff / 60);
			return $minutes . 'min ago';
		}

		// Less than 24 hours - show hours and minutes
		if ($diff < 86400) {
			$hours = floor($diff / 3600);
			$minutes = floor(($diff % 3600) / 60);
			if ($minutes > 0) {
				return $hours . 'h ' . $minutes . 'min ago';
			}
			return $hours . 'h ago';
		}

		// Less than 7 days - show days and hours
		if ($diff < 604800) {
			$days = floor($diff / 86400);
			$hours = floor(($diff % 86400) / 3600);
			if ($hours > 0) {
				return $days . 'd ' . $hours . 'h ago';
			}
			return $days . 'd ago';
		}

		// Less than 30 days - show days
		if ($diff < 2592000) {
			$days = floor($diff / 86400);
			return $days . ' days ago';
		}

		// More than 30 days - show the actual date
		return $this->dateTimeFormatter->formatDate($dateTime, 'short');
	}

	/**
	 * Truncate string to specified length
	 *
	 * @param string $s
	 * @param int $len
	 * @return string
	 */
	private function truncate(string $s, int $len): string {
		return strlen($s) > $len
			? substr($s, 0, $len) . '…'
			: $s;
	}
}
