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
use OCA\Itop\Service\Binding\BindingScopeService;
use OCA\Itop\Service\Binding\IdentityBindingService;
use OCA\Itop\Service\Binding\IdentityResolver;
use OCA\Itop\Service\Binding\SuggestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
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
		private BindingScopeService $scopeService,
		private BindingConfig $bindingConfig,
		private IdentityBindingService $bindingService,
		private SuggestionService $suggestionService,
		private IdentityResolver $resolver,
		private IUserManager $userManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	public function getUsers(
		string $filter = BindingListService::FILTER_ALL,
		string $search = '',
		int $page = 1,
		int $pageSize = 25,
		string $scopeType = '',
		string $scopeId = '',
	): DataResponse {
		$restrictUids = null;

		if ($scopeType !== '') {
			if (!$this->scopeService->isKnownScope($scopeType)) {
				return new DataResponse(
					['message' => $this->l10n->t('Unknown scope type')],
					Http::STATUS_BAD_REQUEST,
				);
			}
			// A scope that resolves to nobody yields an empty table, not every
			// account: an admin who narrowed the view must never be silently
			// widened back out, because the next action may be a bulk exclude.
			$restrictUids = $this->scopeService->resolveUids($scopeType, $scopeId);
		}

		return new DataResponse(
			$this->listService->list($filter, $search, $page, $pageSize, $restrictUids),
		);
	}

	public function searchScopes(string $term = ''): DataResponse {
		return new DataResponse($this->scopeService->search($term));
	}

	public function suggest(array $uids = []): DataResponse {
		return new DataResponse([
			// Cast: the service keys results by uid, and PHP turns a
			// numeric-string key into an int. An all-numeric batch would
			// json_encode as a JSON array, and the browser -- which looks
			// results up by uid -- would read them off the wrong rows.
			'results' => (object)$this->suggestionService->suggest($uids),
			// Read by the client to explain a short result set: the service
			// truncates at MAX_BATCH, and rows beyond it would otherwise just
			// stay blank with nothing said about why.
			'maxBatch' => SuggestionService::MAX_BATCH,
		]);
	}

	/**
	 * Bind every selected account that re-resolves to an exact match.
	 *
	 * Takes nothing but uids on purpose. A personId in the body would have
	 * nowhere to land, which is what stops the tier system being bypassed by
	 * posting an identity the resolver never agreed to.
	 */
	public function apply(array $uids = []): DataResponse {
		// Cast for the same reason as suggest(): uid keys, numeric uids.
		return new DataResponse(['results' => (object)$this->suggestionService->apply($uids)]);
	}

	/**
	 * Bind one account to the person an administrator picked.
	 *
	 * Unlike apply() this does accept a personId: an administrator choosing
	 * among candidates is a deliberate, trusted decision. It still goes through
	 * bind(), which refuses a person already linked to another account.
	 */
	public function applyOne(string $uid = '', string $personId = ''): DataResponse {
		if ($uid === '' || $personId === '') {
			return new DataResponse(['message' => $this->l10n->t('uid and personId are required')], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->userManager->userExists($uid)) {
			return new DataResponse(['message' => $this->l10n->t('No such user')], Http::STATUS_NOT_FOUND);
		}
		if ($this->bindingConfig->isExcluded($uid)) {
			return new DataResponse(['message' => $this->l10n->t('This account is excluded from matching')], Http::STATUS_BAD_REQUEST);
		}
		// apply-one trusts the administrator's *choice of person*, which is not
		// the same as authorising a silent re-point of a live binding. Every
		// caller takes time to reach here -- the picker opens a modal, runs a
		// search and waits on a human -- so the account may have been bound by
		// another administrator or by occ itop:bind in the meantime. bind()
		// would overwrite person_id without complaint; apply() refuses exactly
		// this, and the single-row path must not be the way around it. Moving a
		// person to another account stays the explicit two-step act: unlink,
		// then apply.
		if ($this->bindingService->isBound($uid)) {
			return new DataResponse([
				// Distinct from the 409 below, which is about the *person*
				// already being held elsewhere. Both are genuine conflicts and
				// HTTP offers no finer code, so the discriminator is the error
				// field and the message names the account, not the person.
				'error' => 'already_bound',
				'message' => $this->l10n->t('%s is already linked in iTop; unlink it before linking it to another person.', [$uid]),
			], Http::STATUS_CONFLICT);
		}

		try {
			// userId is deliberately null: ProfileService derives the iTop User
			// from person_id on demand, and guessing it here would risk storing
			// a User belonging to a different person -- iTop profiles hang off
			// the User, so a mismatched pair can grant an unauthorised profile.
			$this->bindingService->bind(
				$uid, $personId, null,
				IdentityBindingService::METHOD_ADMIN,
				$this->bindingConfig->getMatchSource(),
			);
		} catch (\RuntimeException $e) {
			// bind() refuses both an excluded account and a person already
			// linked elsewhere. Excluded is pre-checked above, so reaching here
			// means the person is taken (or a race added an exclusion); the two
			// are not distinguishable without matching on the message text, so
			// the message itself is surfaced rather than reinterpreted.
			return new DataResponse(['error' => 'conflict', 'message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		$this->logger->info('iTop identity applied by admin: {uid} -> person {personId}', [
			'app' => Application::APP_ID,
			'uid' => $uid,
			'personId' => $personId,
		]);

		return new DataResponse(['uid' => $uid, 'personId' => $personId, 'bound' => true]);
	}

	/**
	 * Candidates for the manual picker.
	 *
	 * The term reaches OQL as an escaped literal; the class and field it is
	 * matched against are fixed in IdentityResolver, never taken from here.
	 */
	public function searchPerson(string $q = ''): DataResponse {
		return new DataResponse(['candidates' => $this->resolver->searchPersonsByName($q)]);
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
			return new DataResponse(['message' => $this->l10n->t('No such user')], Http::STATUS_NOT_FOUND);
		}

		$this->bindingService->unbind($uid);
		return new DataResponse(['uid' => $uid, 'bound' => false]);
	}

	private function setExcluded(array $uids, bool $excluded): DataResponse {
		$uids = array_values(array_filter($uids, static fn ($uid) => is_string($uid) && $uid !== ''));
		$changed = $this->bindingConfig->setExcludedMany($uids, $excluded);

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
