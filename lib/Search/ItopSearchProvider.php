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
use OCA\Itop\Service\ProfileService;
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
		private ProfileService $profileService,
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
		$offset = (int)$query->getCursor();
		$limit = $query->getLimit();

		try {
			// Tickets (UserRequest/Incident)
			$tickets = $this->itopAPIService->search($userId, $term, $offset, $limit);

			if (isset($tickets['error'])) {
				$this->logger->error('Error searching iTop tickets: ' . $tickets['error'], ['app' => Application::APP_ID]);
				$tickets = [];
			}

			// Score tickets
			$scoredTickets = array_map(function (array $entry) use ($term) {
				$score = 0.0;
				$lcTerm = mb_strtolower($term);
				$title = (string)($entry['title'] ?? '');
				$desc = (string)($entry['description'] ?? '');
				if (mb_strtolower($title) === $lcTerm) {
					$score += 50;
				} elseif (mb_stripos($title, $lcTerm) !== false) {
					$score += 20;
				}
				if ($desc !== '' && mb_stripos($desc, $lcTerm) !== false) {
					$score += 5;
				}
				// recency boost
				if (!empty($entry['last_update'])) {
					try {
						$dt = new \DateTime($entry['last_update']);
						$ageDays = max(0, (time() - $dt->getTimestamp()) / 86400);
						$score += max(0, 10 - $ageDays); // up to +10
					} catch (\Exception $e) { /* ignore */
					}
				}
				return ['score' => $score, 'kind' => 'ticket', 'data' => $entry];
			}, $tickets);

			// CIs (FunctionalCI subclasses)
			$isPortalOnly = true;
			try {
				$isPortalOnly = $this->profileService->isPortalOnly($userId);
			} catch (\Exception $e) {
				// Be conservative if detection fails
				$this->logger->warning('Profile detection failed, defaulting to portal-only filtering: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}

			$ciLimit = $limit; // simple strategy: request up to limit CIs as well, slice later
			$cis = $this->itopAPIService->searchCIs($userId, (string)$term, [], $isPortalOnly, $ciLimit);
			if (isset($cis['error'])) {
				$this->logger->error('Error searching iTop CIs: ' . $cis['error'], ['app' => Application::APP_ID]);
				$cis = [];
			}

			// Score CIs
			$scoredCis = array_map(function (array $ci) use ($term) {
				$score = 0.0;
				$lcTerm = mb_strtolower($term);
				$name = (string)($ci['name'] ?? '');
				$vendor = (string)($ci['vendor'] ?? '');
				if (mb_strtolower($name) === $lcTerm) {
					$score += 60;
				} elseif (mb_stripos($name, $lcTerm) !== false) {
					$score += 25;
				}
				if ($vendor !== '' && mb_stripos($vendor, $lcTerm) !== false) {
					$score += 10;
				}
				// class weighting
				$class = (string)($ci['class'] ?? '');
				if ($class === 'Software') {
					$score += 30;
				} elseif ($class === 'WebApplication') {
					$score += 15;
				} else {
					$score += 10;
				}
				// counts small boost
				$counts = $ci['counts'] ?? [];
				$score += (int)($counts['instances'] ?? 0) * 0.2;
				return ['score' => $score, 'kind' => 'ci', 'data' => $ci];
			}, $cis);

			// Merge and sort by score desc
			$scored = array_merge($scoredTickets, $scoredCis);
			usort($scored, function ($a, $b) {
				if ($a['score'] === $b['score']) {
					return 0;
				}
				return ($a['score'] > $b['score']) ? -1 : 1;
			});

			// Map top N to SearchResultEntry
			$selected = array_slice($scored, 0, $limit);
			$entries = array_map(function ($row) {
				if ($row['kind'] === 'ticket') {
					$entry = $row['data'];
					$statusEmoji = $this->getStatusEmoji($entry['status'] ?? '');
					$title = $entry['title'];
					if (!empty($entry['ref'])) {
						$title = $statusEmoji . ' [' . $entry['ref'] . '] ' . $title;
					} else {
						$title = $statusEmoji . ' ' . $title;
					}
					// Use ticket icon as thumbnail for Smart Picker display
					$iconUrl = $this->getTicketIconUrl($entry);
					return new ItopSearchResultEntry(
						$iconUrl,  // thumbnailUrl for Smart Picker
						$title,
						$this->formatDescription($entry),
						$entry['url'],
						'',  // icon empty when thumbnailUrl is set
						true
					);
				} else {
					$ci = $row['data'];
					if (($ci['class'] ?? '') === 'Software') {
						$parts = [];
						if (!empty($ci['vendor'])) {
							$parts[] = $ci['vendor'];
						}
						if (!empty($ci['name'])) {
							$parts[] = $ci['name'];
						}
						if (!empty($ci['version'])) {
							$parts[] = $ci['version'];
						}
						$title = implode(' ', $parts);
					} else {
						$title = $ci['name'] ?? '';
					}
					$subline = $this->formatCIDescription($ci);
					$icon = $this->getCIIconUrl($ci['class'] ?? 'FunctionalCI');
					// Use CI icon as thumbnail for Smart Picker display
					return new ItopSearchResultEntry(
						$icon,  // thumbnailUrl for Smart Picker
						$title,
						$subline,
						$ci['url'] ?? '',
						'',  // icon empty when thumbnailUrl is set
						true
					);
				}
			}, $selected);

			return SearchResult::paginated($this->getName(), $entries, $offset + $limit);
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
				return $this->urlGenerator->imagePath(Application::APP_ID, 'user-request.svg');
			case 'Incident':
				return $this->urlGenerator->imagePath(Application::APP_ID, 'incident.svg');
			case 'FunctionalCI':
				return $this->urlGenerator->imagePath(Application::APP_ID, 'ci.svg');
			default:
				return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
		}
	}

	/**
	 * Get state-specific icon URL for a ticket
	 *
	 * @param array $ticket Ticket data with type, status, priority, close_date
	 * @return string Path to state-specific icon
	 */
	protected function getTicketIconUrl(array $ticket): string {
		$type = $ticket['type'] ?? '';
		$status = $ticket['status'] ?? '';
		$closeDate = $ticket['close_date'] ?? '';
		$priority = $ticket['priority'] ?? '';

		// Determine icon based on ticket state
		$iconName = '';

		// Check for closed state first
		if (!empty($closeDate)) {
			$iconName = strtolower($type) . '-closed.svg';
		}
		// Check for escalated state (high priority: 1 or 2)
		elseif (is_numeric($priority) && (int)$priority <= 2) {
			$iconName = strtolower($type) . '-escalated.svg';
		}
		// Check for deadline state (pending/waiting status)
		elseif (stripos($status, 'pending') !== false || stripos($status, 'waiting') !== false) {
			$iconName = strtolower($type) . '-deadline.svg';
		}
		// Default icon for the ticket type
		else {
			$iconName = strtolower($type) . '.svg';
		}

		// Convert type names to match icon filenames
		$iconName = str_replace('userrequest', 'user-request', $iconName);

		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, $iconName)
		);
	}

	protected function getCIIconUrl(string $class): string {
		// Map Software to existing OtherSoftware.svg icon if dedicated icon is absent
		if ($class === 'Software') {
			$iconFile = 'Software.svg';
		} else {
			$iconFile = $class . '.svg';
		}

		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, $iconFile)
		);
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

	protected function formatCIDescription(array $ci): string {
		$parts = [];
		// Common fields
		if (!empty($ci['org_name'])) {
			$parts[] = '🏢 ' . $this->truncate($ci['org_name'], 30);
		}
		if (!empty($ci['status'])) {
			$parts[] = $this->l10n->t('Status: %s', [$ci['status']]);
		}
		if (!empty($ci['location'])) {
			$parts[] = '📍 ' . $this->truncate($ci['location'], 30);
		}

		// Class-specific composition
		$class = $ci['class'] ?? '';
		if ($class === 'PCSoftware' || $class === 'OtherSoftware') {
			if (!empty($ci['software'])) {
				$parts[] = '🧩 ' . $this->truncate($ci['software'], 40);
			}
			if (!empty($ci['license'])) {
				$parts[] = $this->l10n->t('License: %s', [$this->truncate($ci['license'], 30)]);
			}
			if (!empty($ci['system_name'])) {
				$parts[] = $this->l10n->t('System: %s', [$this->truncate($ci['system_name'], 30)]);
			}
			if (!empty($ci['path'])) {
				$parts[] = $this->truncate($ci['path'], 40);
			}
		} elseif ($class === 'WebApplication') {
			if (!empty($ci['web_url'])) {
				$parts[] = '🌐 ' . $this->truncate($ci['web_url'], 40);
			}
			if (!empty($ci['webserver_name'])) {
				$parts[] = $this->l10n->t('Web server: %s', [$this->truncate($ci['webserver_name'], 30)]);
			}
		} elseif ($class === 'Software') {
			$bits = [];
			$counts = $ci['counts'] ?? [];
			if (!empty($counts['documents'])) {
				$bits[] = $this->l10n->t('Documents: %s', [(string)$counts['documents']]);
			}
			if (!empty($counts['instances'])) {
				$bits[] = $this->l10n->t('Installed: %s', [(string)$counts['instances']]);
			}
			if (!empty($counts['patches'])) {
				$bits[] = $this->l10n->t('Patches: %s', [(string)$counts['patches']]);
			}
			if (!empty($counts['licenses'])) {
				$bits[] = $this->l10n->t('Licenses: %s', [(string)$counts['licenses']]);
			}
			if (!empty($bits)) {
				$parts[] = implode(' • ', $bits);
			}
		} else {
			// Hardware-like fields
			$assetBits = [];
			if (!empty($ci['asset_number'])) {
				$assetBits[] = $this->l10n->t('Asset: %s', [$ci['asset_number']]);
			}
			if (!empty($ci['serialnumber'])) {
				$assetBits[] = $this->l10n->t('SN: %s', [$ci['serialnumber']]);
			}
			if (!empty($assetBits)) {
				$parts[] = implode(' · ', $assetBits);
			}
			if (!empty($ci['brand_model'])) {
				$parts[] = $this->truncate($ci['brand_model'], 40);
			}
		}

		if (!empty($ci['description'])) {
			$desc = strip_tags($ci['description']);
			if (strlen($desc) > 120) {
				$desc = substr($desc, 0, 117) . '...';
			}
			$parts[] = $desc;
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
			return $this->l10n->t('just now');
		}

		// Less than 1 hour - show minutes
		if ($diff < 3600) {
			$minutes = floor($diff / 60);
			return $this->l10n->t('%dm ago', [$minutes]);
		}

		// Less than 24 hours - show hours and minutes
		if ($diff < 86400) {
			$hours = floor($diff / 3600);
			$minutes = floor(($diff % 3600) / 60);
			if ($minutes > 0) {
				return $this->l10n->t('%1$dh %2$dm ago', [$hours, $minutes]);
			}
			return $this->l10n->t('%dh ago', [$hours]);
		}

		// Less than 7 days - show days and hours
		if ($diff < 604800) {
			$days = floor($diff / 86400);
			$hours = floor(($diff % 86400) / 3600);
			if ($hours > 0) {
				return $this->l10n->t('%1$dd %2$dh ago', [$days, $hours]);
			}
			return $this->l10n->t('%dd ago', [$days]);
		}

		// Less than 30 days - show days
		if ($diff < 2592000) {
			$days = floor($diff / 86400);
			return $this->l10n->t('%d days ago', [$days]);
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
