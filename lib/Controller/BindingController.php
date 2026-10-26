<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Controller;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\Binding\BindingConfig;
use OCA\Itop\Service\Binding\BindingListService;
use OCA\Itop\Service\Binding\IdentityBindingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Administrative endpoints for iTop identity binding.
 *
 * Every endpoint here is an administrative action, so none carries
 * NoAdminRequired. Self-service endpoints belong to a later phase and must not
 * be added to this controller.
 *
 * Lives apart from ConfigController, which is already ~2,400 lines.
 */
class BindingController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private BindingListService $listService,
		private BindingConfig $bindingConfig,
		private IdentityBindingService $bindingService,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	public function getUsers(
		string $filter = BindingListService::FILTER_ALL,
		string $search = '',
		int $page = 1,
		int $pageSize = 25,
	): DataResponse {
		return new DataResponse($this->listService->list($filter, $search, $page, $pageSize));
	}

	public function getRule(): DataResponse {
		return new DataResponse([
			'source' => $this->bindingConfig->getMatchSource(),
			'target' => $this->bindingConfig->getMatchTarget(),
			'sources' => $this->bindingConfig->getSources(),
			'targets' => $this->bindingConfig->getTargets(),
			'source_user_mutable' => $this->bindingConfig->isSourceUserMutable(),
			'excluded_users' => $this->bindingConfig->getExcludedUsers(),
		]);
	}

	public function setRule(string $source, string $target): DataResponse {
		try {
			$this->bindingConfig->setMatchRule($source, $target);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$this->logger->info('iTop match rule set to {source} -> {target}', [
			'app' => Application::APP_ID,
			'source' => $source,
			'target' => $target,
		]);

		return new DataResponse([
			'source' => $this->bindingConfig->getMatchSource(),
			'target' => $this->bindingConfig->getMatchTarget(),
			'source_user_mutable' => $this->bindingConfig->isSourceUserMutable(),
		]);
	}

	public function exclude(array $uids): DataResponse {
		return $this->setExcluded($uids, true);
	}

	public function restore(array $uids): DataResponse {
		return $this->setExcluded($uids, false);
	}

	public function unbind(string $uid): DataResponse {
		if (!$this->userManager->userExists($uid)) {
			return new DataResponse(['message' => 'No such user'], Http::STATUS_NOT_FOUND);
		}

		$this->bindingService->unbind($uid);
		return new DataResponse(['uid' => $uid, 'bound' => false]);
	}

	private function setExcluded(array $uids, bool $excluded): DataResponse {
		$changed = [];
		foreach ($uids as $uid) {
			if (!is_string($uid) || $uid === '') {
				continue;
			}
			$this->bindingConfig->setExcluded($uid, $excluded);
			$changed[] = $uid;
		}

		$this->logger->info('iTop binding exclusions updated', [
			'app' => Application::APP_ID,
			'excluded' => $excluded,
			'uids' => $changed,
		]);

		return new DataResponse([
			'changed' => $changed,
			'excluded_users' => $this->bindingConfig->getExcludedUsers(),
		]);
	}
}
