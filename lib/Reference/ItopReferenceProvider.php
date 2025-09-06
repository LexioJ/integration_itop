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
use OCA\Itop\Service\ItopAPIService;
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

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private ItopAPIService $itopAPIService,
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
		return $this->l10n->t('iTop tickets and CIs');
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
	 * @inheritDoc
	 */
	public function matchReference(string $referenceText): bool {
		if ($this->userId !== null) {
			$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
			$userItopUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
			$itopUrl = $userItopUrl ?: $adminItopUrl;
			
			if ($itopUrl !== '') {
				return $this->getTicketIdFromUrl($referenceText, $itopUrl) !== null;
			}
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference($referenceText)) {
			$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
			$userItopUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
			$itopUrl = $userItopUrl ?: $adminItopUrl;
			
			$ticketInfo = $this->getTicketIdFromUrl($referenceText, $itopUrl);
			if ($ticketInfo !== null && $this->userId !== null) {
				return $this->getTicketReference($ticketInfo['id'], $ticketInfo['class'], $referenceText);
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
	public function search(string $term): array {
		if ($this->userId === null) {
			return [];
		}

		$accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		if ($accessToken === '') {
			return [];
		}

		try {
			$searchResults = $this->itopAPIService->search($this->userId, $term, 0, 5);
			
			if (isset($searchResults['error'])) {
				$this->logger->error('Error searching iTop for references: ' . $searchResults['error'], ['app' => Application::APP_ID]);
				return [];
			}

			$references = [];
			foreach ($searchResults as $item) {
				$references[] = [
					'id' => $item['url'],
					'title' => $item['title'],
					'description' => strip_tags($item['description']),
					'url' => $item['url'],
					'imageUrl' => $this->getIconUrl(),
				];
			}

			return $references;
		} catch (\Exception $e) {
			$this->logger->error('Error searching iTop for references: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return [];
		}
	}

	/**
	 * Get ticket ID and class from URL
	 *
	 * @param string $url
	 * @param string $itopUrl
	 * @return array|null
	 */
	private function getTicketIdFromUrl(string $url, string $itopUrl): ?array {
		// Match URLs like: https://itop.example.com/pages/UI.php?operation=details&class=UserRequest&id=123
		$pattern = '#^' . preg_quote($itopUrl, '#') . '/pages/UI\.php\?.*operation=details.*class=([^&]+).*id=(\d+)#';
		if (preg_match($pattern, $url, $matches)) {
			return [
				'class' => $matches[1],
				'id' => (int) $matches[2]
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
			if ($class === 'UserRequest') {
				$ticketInfo = $this->itopAPIService->getTicketInfo($this->userId, $ticketId);
			} else {
				// For other classes, we'd need a more generic API method
				return null;
			}
			
			if (isset($ticketInfo['error'])) {
				return null;
			}

			$ticket = $ticketInfo['objects'][array_key_first($ticketInfo['objects'])];
			$fields = $ticket['fields'];

			$reference = new Reference($url);
			$reference->setTitle($fields['title'] ?? 'iTop ' . $class . ' #' . $ticketId);
			$reference->setDescription($this->formatTicketDescription($fields));
			$reference->setImageUrl($this->getIconUrl());
			
			$reference->setRichObject(
				self::RICH_OBJECT_TYPE,
				[
					'id' => $ticketId,
					'class' => $class,
					'title' => $fields['title'] ?? '',
					'status' => $fields['status'] ?? '',
					'priority' => $fields['priority'] ?? '',
					'caller' => $fields['caller_id_friendlyname'] ?? '',
					'description' => strip_tags($fields['description'] ?? ''),
					'creation_date' => $fields['creation_date'] ?? '',
					'url' => $url,
				]
			);

			return $reference;
		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop ticket reference: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return null;
		}
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
}
