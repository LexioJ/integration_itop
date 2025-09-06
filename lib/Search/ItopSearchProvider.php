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

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\ItopAPIService;
use OCP\App\IAppManager;
use OCP\IConfig;
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
		private ItopAPIService $itopAPIService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'integration_itop';
	}

	public function getName(): string {
		return $this->l10n->t('iTop');
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
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		if ($accessToken === '') {
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
				return new ItopSearchResultEntry(
					$this->getThumbnailUrl($entry),
					$entry['title'],
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
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}

	protected function getIconUrl(string $type): string {
		switch ($type) {
			case 'UserRequest':
				return $this->urlGenerator->imagePath(Application::APP_ID, 'ticket.svg');
			case 'FunctionalCI':
				return $this->urlGenerator->imagePath(Application::APP_ID, 'ci.svg');
			default:
				return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
		}
	}

	protected function formatDescription(array $entry): string {
		$parts = [];
		
		if (!empty($entry['type'])) {
			$typeName = $entry['type'] === 'UserRequest' ? $this->l10n->t('Ticket') : $this->l10n->t('Configuration Item');
			$parts[] = $typeName;
		}
		
		if (!empty($entry['status'])) {
			$parts[] = $this->l10n->t('Status: %s', [$entry['status']]);
		}
		
		if (!empty($entry['description'])) {
			$description = strip_tags($entry['description']);
			if (strlen($description) > 100) {
				$description = substr($description, 0, 97) . '...';
			}
			$parts[] = $description;
		}
		
		return implode(' • ', $parts);
	}
}
