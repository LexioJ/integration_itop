<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Controller;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\Binding\DiscoveryService;
use OCA\Itop\Service\Binding\MatchResult;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Self-service identity discovery, for the signed-in user themselves.
 *
 * Its own controller because BindingController is admin-only by design and
 * says so at its lines 30-32: every endpoint there is an administrative
 * action, and a NoAdminRequired method among them would be one careless edit
 * away from inheriting the wrong neighbours.
 *
 * Neither method takes a single parameter. The value matched on is derived
 * from the signed-in account server-side, so there is nothing to forge and no
 * way to probe iTop for which addresses exist -- enforced by the signatures
 * rather than by validation, because validation can be loosened by accident.
 *
 * Rate-limited per user: each call costs an iTop query, and this is the one
 * binding route reachable by an untrusted account.
 */
class DiscoveryController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private DiscoveryService $discoveryService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private IAppConfig $appConfig,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 60)]
	public function discover(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->respond($this->discoveryService->discover($user));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 60)]
	public function confirm(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->respond($this->discoveryService->confirm($user));
	}

	/**
	 * Word a service result for the user and give it a status code.
	 *
	 * The result is passed through as-is rather than rebuilt, so no field the
	 * service deliberately withheld can be reintroduced here: a
	 * not_self_serviceable result carries its tier and nothing else, and the
	 * message for it never names the matched person either.
	 *
	 * @param array $result as returned by DiscoveryService
	 */
	private function respond(array $result): DataResponse {
		$name = $this->getDisplayName();
		[$message, $status] = match ($result['status'] ?? '') {
			'disabled' => [
				$this->l10n->t('Linking your own account is not enabled on this server. Please ask an administrator to link it for you.'),
				Http::STATUS_FORBIDDEN,
			],
			'excluded' => [
				$this->l10n->t('This account is excluded from %s matching. Please contact an administrator.', [$name]),
				Http::STATUS_FORBIDDEN,
			],
			'already_bound' => [
				$this->l10n->t('This account is already linked to an %s person. An administrator has to unlink it before it can be linked again.', [$name]),
				Http::STATUS_CONFLICT,
			],
			'failed' => [
				$this->l10n->t('%s could not be reached. Please try again later.', [$name]),
				Http::STATUS_BAD_GATEWAY,
			],
			'not_self_serviceable' => [$this->notSelfServiceableMessage((string)($result['tier'] ?? ''), $name), Http::STATUS_OK],
			'proposal', 'bound' => ['', Http::STATUS_OK],
			default => [$this->l10n->t('Something went wrong. Please contact an administrator.'), Http::STATUS_INTERNAL_SERVER_ERROR],
		};

		if ($message !== '') {
			$result['message'] = $message;
		}

		return new DataResponse($result, $status);
	}

	/**
	 * Why this account cannot link itself, without saying who was matched.
	 *
	 * Every one of these ends at an administrator on purpose. A user
	 * confirming that a name they are shown is their own is not a meaningful
	 * check -- an administrator comparing two names is -- and naming the
	 * matched person to someone who may not be that person would be a
	 * disclosure.
	 */
	private function notSelfServiceableMessage(string $tier, string $name): string {
		return match ($tier) {
			MatchResult::TIER_REVIEW => $this->l10n->t('A matching person was found, but the name on that person does not match your account. Please ask an administrator to link it for you.'),
			MatchResult::TIER_AMBIGUOUS => $this->l10n->t('More than one person matches your account, so it cannot be linked automatically. Please ask an administrator to link it for you.'),
			MatchResult::TIER_CONFLICT => $this->l10n->t('The matching person is already linked to a different Nextcloud account. Please ask an administrator to link it for you.'),
			MatchResult::TIER_NONE => $this->l10n->t('No %s person matches your account. Please ask an administrator to link it for you.', [$name]),
			default => $this->l10n->t('Your account cannot be linked automatically. Please ask an administrator to link it for you.'),
		};
	}

	/**
	 * The administrator-configured display name, defaulting to the real
	 * product name -- see lib/Settings/Personal.php for the same read.
	 */
	private function getDisplayName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'user_facing_name', 'iTop');
	}
}
