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

namespace OCA\Itop\Reference;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\CacheService;
use OCA\Itop\Service\ItopAPIService;
use OCA\Itop\Service\PreviewMapper;
use OCA\Itop\Service\ProfileService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;

use Psr\Log\LoggerInterface;

class ItopReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {

	private const RICH_OBJECT_TYPE = Application::APP_ID . '_ticket';
	private const RICH_OBJECT_TYPE_CI = Application::APP_ID . '_ci';

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private ItopAPIService $itopAPIService,
		private ProfileService $profileService,
		private PreviewMapper $previewMapper,
		private CacheService $cacheService,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'integration_itop';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		// Use admin-configured display name, fallback to 'iTop'
		$displayName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');
		return $displayName . ' tickets and CIs';
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'));
	}

	/**
	 * Get state-specific icon URL for a ticket
	 *
	 * @param array $ticket Ticket data with type, status, priority, close_date
	 * @return string Absolute URL to state-specific icon
	 */
	private function getTicketIconUrl(array $ticket): string {
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
		// Check for escalated state (high priority)
		elseif (stripos($priority, 'high') !== false || stripos($priority, 'critical') !== false) {
			$iconName = strtolower($type) . '-escalated.svg';
		}
		// Check for deadline state (you may need to adjust this logic based on your needs)
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

	/**
	 * @inheritDoc
	 */
	public function matchReference(string $referenceText): bool {
		if ($this->userId !== null) {
			$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
			$userItopUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
			$itopUrl = $userItopUrl ?: $adminItopUrl;

			if ($itopUrl !== '') {
				$parsed = $this->parseItopUrl($referenceText, $itopUrl);
				return $parsed !== null;
			}
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference($referenceText) && $this->userId !== null) {
			$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
			$userItopUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
			$itopUrl = $userItopUrl ?: $adminItopUrl;

			$parsed = $this->parseItopUrl($referenceText, $itopUrl);
			if ($parsed === null) {
				return null;
			}

			$class = $parsed['class'];
			$id = $parsed['id'];

			// Determine if this is a ticket or CI
			if ($class === 'UserRequest' || $class === 'Incident') {
				return $this->getTicketReference($id, $class, $referenceText);
			} elseif (in_array($class, Application::SUPPORTED_CI_CLASSES, true)) {
				return $this->getCIReference($id, $class, $referenceText);
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getCachePrefix(string $referenceId): string {
		return $this->userId ?? '';
	}

	/**
	 * @inheritDoc
	 */
	public function getCacheKey(string $referenceId): ?string {
		return $referenceId;
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedSearchProviderIds(): array {
		// Return the search provider IDs that this reference provider supports
		return ['integration_itop'];
	}

	/**
	 * @inheritDoc
	 */
	public function search(string $term): array {
		if ($this->userId === null) {
			return [];
		}

		$accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		if ($accessToken === '') {
			return [];
		}

		try {
			// Determine if user is portal-only
			$isPortalOnly = $this->profileService->isPortalOnly($this->userId);

			// Search for tickets using the existing search method
			$ticketResults = $this->itopAPIService->search($this->userId, $term, 0, 5);

			// Search for CIs using the new searchCIs method
			$ciResults = $this->itopAPIService->searchCIs($this->userId, $term, [], $isPortalOnly, 5);

			if (isset($ticketResults['error'])) {
				$this->logger->error('Error searching iTop tickets for references: ' . $ticketResults['error'], ['app' => Application::APP_ID]);
				$ticketResults = [];
			}

			if (isset($ciResults['error'])) {
				$this->logger->error('Error searching iTop CIs for references: ' . $ciResults['error'], ['app' => Application::APP_ID]);
				$ciResults = [];
			}

			$references = [];

			// Add ticket results with state-specific icons
			foreach ($ticketResults as $item) {
				$references[] = [
					'id' => $item['url'],
					'title' => $item['title'],
					'description' => strip_tags($item['description']),
					'url' => $item['url'],
					'imageUrl' => $this->getTicketIconUrl($item),
				];
			}

			// Add CI results with class-specific icons
			foreach ($ciResults as $ci) {
				$class = $ci['finalclass'] ?? $ci['class'] ?? '';
				$name = $ci['name'] ?? $ci['friendlyname'] ?? '';
				$id = $ci['id'] ?? '';

				// Build iTop URL
				$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
				$userItopUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
				$itopUrl = $userItopUrl ?: $adminItopUrl;
				$url = $itopUrl . '/pages/UI.php?operation=details&class=' . urlencode($class) . '&id=' . urlencode($id);

				// Format description with CI details
				$description = $this->formatCIDescription($ci);

				$references[] = [
					'id' => $url,
					'title' => $name,
					'description' => $description,
					'url' => $url,
					'imageUrl' => $this->getCIIconUrl($class),
				];
			}

			// Limit total results to 10
			return array_slice($references, 0, 10);
		} catch (\Exception $e) {
			$this->logger->error('Error searching iTop for references: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return [];
		}
	}

	/**
	 * Parse iTop URL to extract class and ID
	 *
	 * Works for both tickets (UserRequest, Incident) and CIs (PC, Phone, etc.)
	 *
	 * @param string $url Full iTop URL
	 * @param string $itopUrl Base iTop instance URL
	 * @return array|null ['class' => string, 'id' => int] or null if not a valid iTop URL
	 */
	private function parseItopUrl(string $url, string $itopUrl): ?array {
		// Match URLs like: http://itop.example.com/pages/UI.php?operation=details&class=PC&id=123
		// Also handles URLs with additional parameters like c[menu]=ConfigManagementOverview
		// Parse query parameters properly to handle any parameter order and additional params

		// Unescape backslashes that Nextcloud Talk might add (c\[menu\] -> c[menu])
		$url = str_replace(['\\[', '\\]'], ['[', ']'], $url);

		// First check if this is a UI.php URL with operation=details
		$basePattern = '#^' . preg_quote($itopUrl, '#') . '/pages/UI\.php\?(.+)$#';
		if (!preg_match($basePattern, $url, $matches)) {
			return null;
		}

		// Parse query string
		$queryString = $matches[1];
		parse_str($queryString, $params);

		// Check required parameters
		if (isset($params['operation'], $params['class'], $params['id'])
			&& $params['operation'] === 'details'
			&& is_numeric($params['id'])) {
			return [
				'class' => $params['class'],
				'id' => (int) $params['id']
			];
		}

		return null;
	}

	/**
	 * Get reference for a ticket
	 *
	 * @param int $ticketId
	 * @param string $class
	 * @param string $url
	 * @return IReference|null
	 */
	private function getTicketReference(int $ticketId, string $class, string $url): ?IReference {
		try {
			// Get ticket details from iTop API
			if ($class === 'UserRequest' || $class === 'Incident') {
				$ticketInfo = $this->itopAPIService->getTicketInfo($this->userId, $ticketId, $class);
			} else {
				// For other classes, we'd need a more generic API method
				return null;
			}

			// Check for errors or empty results
			if (isset($ticketInfo['error']) || empty($ticketInfo['objects'])) {
				$this->logger->debug('Ticket not found or error in iTop API response', [
					'app' => Application::APP_ID,
					'ticketId' => $ticketId,
					'class' => $class,
					'error' => $ticketInfo['error'] ?? 'No objects returned'
				]);
				return null;
			}

			$ticket = $ticketInfo['objects'][array_key_first($ticketInfo['objects'])];
			$fields = $ticket['fields'] ?? [];

			// Get iTop URL for building links
			$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
			$userItopUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
			$itopUrl = $userItopUrl ?: $adminItopUrl;

			$reference = new Reference($url);

			// Set minimal OpenGraph data - this helps Nextcloud understand the reference type
			// Without this, Talk may add "Enable interactive view" button
			$ticketRef = $fields['ref'] ?? $class . '-' . $ticketId;
			$ticketTitle = $fields['title'] ?? 'iTop Ticket';
			$reference->setTitle('[' . $ticketRef . '] ' . $ticketTitle);

			$reference->setRichObject(
				self::RICH_OBJECT_TYPE,
				[
					'id' => $ticketId,
					'class' => $class,
					'title' => $fields['title'] ?? '',
					'ref' => $fields['ref'] ?? '',
					'status' => $fields['status'] ?? '',
					'priority' => $fields['priority'] ?? '',
					'caller_id' => $fields['caller_id'] ?? '',
					'caller_id_friendlyname' => $fields['caller_id_friendlyname'] ?? '',
					'agent_id' => $fields['agent_id'] ?? '',
					'agent_id_friendlyname' => $fields['agent_id_friendlyname'] ?? '',
					'org_name' => $fields['org_name'] ?? '',
					'org_id_friendlyname' => $fields['org_id_friendlyname'] ?? '',
					'team_id_friendlyname' => $fields['team_id_friendlyname'] ?? '',
					'service_name' => $fields['service_name'] ?? '',
					'servicesubcategory_name' => $fields['servicesubcategory_name'] ?? '',
					'description' => strip_tags($fields['description'] ?? ''),
					'creation_date' => $fields['creation_date'] ?? '',
					'last_update' => $fields['last_update'] ?? '',
					'close_date' => $fields['close_date'] ?? '',
					'start_date' => $fields['start_date'] ?? '',
					'url' => $url,
					'itop_url' => $itopUrl,
				]
			);

			return $reference;
		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop ticket reference: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return null;
		}
	}

	/**
	 * Get reference for a Configuration Item (CI)
	 *
	 * Uses ProfileService, CacheService, and PreviewMapper from Phase 2
	 *
	 * @param int $ciId CI ID
	 * @param string $class CI class (PC, Phone, Tablet, etc.)
	 * @param string $url Full iTop URL
	 * @return IReference|null
	 */
	private function getCIReference(int $ciId, string $class, string $url): ?IReference {
		try {
			// Check cache first
			$cachedPreview = $this->cacheService->getCIPreview($this->userId, $class, $ciId);

			if ($cachedPreview !== null) {
				$this->logger->debug('CI preview cache hit', [
					'app' => Application::APP_ID,
					'userId' => $this->userId,
					'class' => $class,
					'id' => $ciId
				]);
				return $this->buildCIReferenceFromPreview($cachedPreview, $url);
			}

			// Determine if user is portal-only
			$isPortalOnly = $this->profileService->isPortalOnly($this->userId);

			// Fetch CI data from iTop API
			$ciData = $this->itopAPIService->getCIPreview($this->userId, $class, $ciId, $isPortalOnly);

			// Check for errors or empty results
			if (isset($ciData['error']) || empty($ciData['objects'])) {
				$this->logger->debug('CI not found or access denied', [
					'app' => Application::APP_ID,
					'userId' => $this->userId,
					'class' => $class,
					'id' => $ciId,
					'isPortalOnly' => $isPortalOnly,
					'error' => $ciData['error'] ?? 'No objects returned'
				]);
				return null;
			}

			// Transform to preview format
			$ciObject = $ciData['objects'][array_key_first($ciData['objects'])];
			$preview = $this->previewMapper->mapCIToPreview($ciObject, $class);

			// Cache the preview
			$this->cacheService->setCIPreview($this->userId, $class, $ciId, $preview);

			return $this->buildCIReferenceFromPreview($preview, $url);

		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop CI reference: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'class' => $class,
				'id' => $ciId,
				'exception' => $e
			]);
			return null;
		}
	}

	/**
	 * Build IReference object from CI preview data
	 *
	 * @param array $preview Preview data from PreviewMapper
	 * @param string $url Original iTop URL
	 * @return IReference
	 */
	private function buildCIReferenceFromPreview(array $preview, string $url): IReference {
		$reference = new Reference($url);

		// Set title with CI name
		$reference->setTitle($preview['title'] ?? 'iTop CI');

		// Build rich object with preview data
		$reference->setRichObject(
			self::RICH_OBJECT_TYPE_CI,
			[
				'id' => $preview['id'] ?? null,
				'class' => $preview['class'] ?? '',
				'title' => $preview['title'] ?? '',
				'subtitle' => $preview['subtitle'] ?? '',
				'badges' => $preview['badges'] ?? [],
				'chips' => $preview['chips'] ?? [],
				'extras' => $preview['extras'] ?? [],
				'description' => $preview['description'] ?? '',
				'timestamps' => $preview['timestamps'] ?? [],
				'url' => $url,
				'itop_url' => $preview['url'] ?? $url,
				'icon' => $preview['icon'] ?? '',
			]
		);

		return $reference;
	}

	/**
	 * Format ticket description for preview
	 *
	 * @param array $fields
	 * @return string
	 */
	private function formatTicketDescription(array $fields): string {
		$parts = [];

		if (!empty($fields['status'])) {
			$parts[] = $this->l10n->t('Status: %s', [$fields['status']]);
		}

		if (!empty($fields['priority'])) {
			$parts[] = $this->l10n->t('Priority: %s', [$fields['priority']]);
		}

		if (!empty($fields['caller_id_friendlyname'])) {
			$parts[] = $this->l10n->t('Caller: %s', [$fields['caller_id_friendlyname']]);
		}

		if (!empty($fields['creation_date'])) {
			$date = new \DateTime($fields['creation_date']);
			$parts[] = $this->l10n->t('Created: %s', [$date->format('Y-m-d')]);
		}

		$description = implode(' • ', $parts);

		if (!empty($fields['description'])) {
			$ticketDesc = strip_tags($fields['description']);
			if (strlen($ticketDesc) > 100) {
				$ticketDesc = substr($ticketDesc, 0, 97) . '...';
			}
			if ($description) {
				$description .= "\n" . $ticketDesc;
			} else {
				$description = $ticketDesc;
			}
		}

		return $description;
	}

	/**
	 * Format CI description for smart picker
	 *
	 * @param array $ci CI data
	 * @return string
	 */
	private function formatCIDescription(array $ci): string {
		$parts = [];
		$class = $ci['finalclass'] ?? $ci['class'] ?? '';

		// Status
		if (!empty($ci['status'])) {
			$parts[] = $this->l10n->t('Status: %s', [$ci['status']]);
		}

		// Organization
		if (!empty($ci['org_name'])) {
			$parts[] = $this->l10n->t('Org: %s', [$ci['org_name']]);
		}

		// Location
		if (!empty($ci['location_name'])) {
			$parts[] = $this->l10n->t('Location: %s', [$ci['location_name']]);
		}

		// Brand/Model for hardware
		if (!empty($ci['brand_name']) && !empty($ci['model_name'])) {
			$parts[] = $ci['brand_name'] . ' ' . $ci['model_name'];
		} elseif (!empty($ci['brand_name'])) {
			$parts[] = $ci['brand_name'];
		}

		// Software-specific: vendor and version
		if (in_array($class, ['PCSoftware', 'OtherSoftware'], true)) {
			if (!empty($ci['vendor'])) {
				$parts[] = $this->l10n->t('Vendor: %s', [$ci['vendor']]);
			}
			if (!empty($ci['version'])) {
				$parts[] = $this->l10n->t('Version: %s', [$ci['version']]);
			}
		}

		// WebApplication-specific: URL
		if ($class === 'WebApplication' && !empty($ci['url'])) {
			$parts[] = $ci['url'];
		}

		return implode(' • ', $parts);
	}

	/**
	 * Get icon URL for a CI class
	 *
	 * @param string $class CI class name
	 * @return string Absolute URL to icon
	 */
	private function getCIIconUrl(string $class): string {
		$iconMap = [
			'PC' => 'PC.svg',
			'Phone' => 'Phone.svg',
			'IPPhone' => 'IPPhone.svg',
			'MobilePhone' => 'MobilePhone.svg',
			'Tablet' => 'Tablet.svg',
			'Printer' => 'Printer.svg',
			'Peripheral' => 'Peripheral.svg',
			'PCSoftware' => 'PCSoftware.svg',
			'OtherSoftware' => 'OtherSoftware.svg',
			'Software' => 'Software.svg',
			'WebApplication' => 'WebApplication.svg',
		];

		$icon = $iconMap[$class] ?? 'app.svg';
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, $icon)
		);
	}
}
