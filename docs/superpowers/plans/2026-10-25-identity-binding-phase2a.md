# iTop Identity Binding — Phase 2a Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give administrators a reachable way to configure identity matching, and expose the REST API the association table will consume.

**Architecture:** A `BindingListService` answers "which Nextcloud users exist, what is each one's binding state" with filtering and paging. A new `BindingController` exposes that plus exclusion and rule-setting endpoints. The admin settings page gains a matching-rule block that writes through the controller. No new Vue: admin settings are a server-rendered PHP template plus vanilla JS, and adding Vue 2 here would enlarge the surface the deferred Vue 3 migration must cross.

**Tech Stack:** PHP 8.1+, Nextcloud 32–35 app framework, `OCP\IAppConfig` / `OCP\Config\IUserConfig` / `OCP\IUserManager`, vanilla JS + a server-rendered PHP template, PHPUnit 9.5.

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. Do not use APIs newer than 32.
- Config access uses **`getValueString` / `setValueString` only** — never the typed `getValueInt` / `getValueBool` variants. The first typed write pins a key's type permanently and a later string write raises `AppConfigTypeConflictException`.
- App id constant is `Application::APP_ID` (`'integration_itop'`). Never hard-code the string.
- `declare(strict_types=1)` is **not** used anywhere in `lib/`. Do not introduce it.
- Constructor dependencies use **promoted properties**. Services autowire — do not add registrations to `Application::register()`.
- **Tests are local-only and never committed.** `tests/`, `phpunit.xml` and `docs/testing.md` are gitignored. Write the tests, run them, but add only `lib/`, `templates/`, `src/`, `css/`, `appinfo/` and docs files to commits.
- Mocking an OCP interface needs the `nextcloud/ocp` stubs, already a `require-dev` dependency pinned to `^32.0` under `autoload-dev`. Do not re-add or downgrade it.
- OQL values must be escaped with `ItopAPIService::escapeOQLString()`. OQL field and class names come only from `BindingConfig::resolveTarget()`'s allowlist.
- Admin endpoints are admin-only. Do **not** add `#[NoAdminRequired]` to anything in this plan — every endpoint here is an administrative action.
- `ConfigController` is ~2,400 lines and must not grow. New endpoints go in `BindingController`.

### Scale limit, stated deliberately

`BindingListService` enumerates all Nextcloud users to compute filtered counts, because "how many users are unbound" cannot be answered from a single page. At helpdesk scale (hundreds to low thousands) this is fine, and it matches what `occ itop:bind` already does via `callForAllUsers`. It is recorded as a known limit in the spec's deferred list; do not silently paper over it, and do not redesign it here.

### What phase 2a does NOT include

The association table UI, the "Suggest matches" flow, and bulk apply are phase 2b. The `review` tier is therefore **not** a server-side filter in this plan — it only exists as the outcome of a suggestion, which 2b adds. Server-side filters here are `all`, `unbound`, `bound`, `excluded`.

---

## File Structure

| File | Responsibility |
|---|---|
| `lib/Service/Binding/BindingConfig.php` *(modify)* | Add rule setters and the source-mutability check |
| `lib/Service/Binding/BindingListService.php` *(create)* | Page and filter users with their binding state |
| `lib/Controller/BindingController.php` *(create)* | Admin endpoints |
| `appinfo/routes.php` *(modify)* | Register the routes |
| `lib/Settings/Admin.php` *(modify)* | Pass rule + mutability params to the template |
| `templates/adminSettings.php` *(modify)* | Matching-rule section |
| `src/admin-settings.js` *(modify)* | Save handler for the rule |
| `css/admin-settings.css` *(modify)* | Warning card styling |

---

## Task 1: Rule setters and source mutability

**Files:**
- Modify: `lib/Service/Binding/BindingConfig.php`
- Test: `tests/Unit/Service/Binding/BindingConfigTest.php` (extend the existing file)

**Interfaces:**
- Consumes: the existing `BindingConfig` constants `SOURCE_EMAIL` / `SOURCE_UID` / `SOURCE_DISPLAYNAME` and `TARGET_PERSON_EMAIL` / `TARGET_PERSON_EMPLOYEE_NUMBER` / `TARGET_USER_LOGIN`, and `resolveTarget()`.
- Produces:
  - `public function getSources(): array` — the allowlisted source values, for rendering a select
  - `public function getTargets(): array` — the allowlisted target values, for rendering a select
  - `public function setMatchRule(string $source, string $target): void` — throws `\InvalidArgumentException` if either is not allowlisted
  - `public function isSourceUserMutable(): bool` — true when the configured source attribute is one an end user can change for themselves

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Service/Binding/BindingConfigTest.php`, inside the class. Note the helper now needs a second mock, so also add this private method:

```php
	private function configWithSystem(array $values, array $system): BindingConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $values[$key] ?? $default
		);
		$sysConfig = $this->createMock(\OCP\IConfig::class);
		$sysConfig->method('getSystemValueBool')->willReturnCallback(
			static fn (string $key, bool $default = false) => $system[$key] ?? $default
		);
		return new BindingConfig($appConfig, $sysConfig);
	}

	public function testGetSourcesAndTargetsAreTheAllowlists(): void {
		$c = $this->configWithSystem([], []);
		$this->assertSame(
			[BindingConfig::SOURCE_EMAIL, BindingConfig::SOURCE_UID, BindingConfig::SOURCE_DISPLAYNAME],
			$c->getSources()
		);
		$this->assertSame(
			[BindingConfig::TARGET_PERSON_EMAIL, BindingConfig::TARGET_PERSON_EMPLOYEE_NUMBER, BindingConfig::TARGET_USER_LOGIN],
			$c->getTargets()
		);
	}

	public function testSetMatchRuleRejectsAnUnknownSource(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->configWithSystem([], [])->setMatchRule('not_a_source', BindingConfig::TARGET_PERSON_EMAIL);
	}

	public function testSetMatchRuleRejectsAnUnknownTarget(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->configWithSystem([], [])->setMatchRule(BindingConfig::SOURCE_EMAIL, "person.email' OR 1=1");
	}

	public function testUidSourceIsNeverUserMutable(): void {
		// Nextcloud exposes no setUID or rename API, so a uid cannot be forged
		// even on an instance that lets users change everything else.
		$c = $this->configWithSystem(
			['binding_match_source' => BindingConfig::SOURCE_UID],
			['allow_user_to_change_email' => true, 'allow_user_to_change_display_name' => true]
		);
		$this->assertFalse($c->isSourceUserMutable());
	}

	public function testEmailSourceIsMutableByDefault(): void {
		// Both system settings default to true, so a stock instance is mutable.
		$c = $this->configWithSystem(['binding_match_source' => BindingConfig::SOURCE_EMAIL], []);
		$this->assertTrue($c->isSourceUserMutable());
	}

	public function testEmailSourceIsNotMutableWhenTheInstanceLocksIt(): void {
		$c = $this->configWithSystem(
			['binding_match_source' => BindingConfig::SOURCE_EMAIL],
			['allow_user_to_change_email' => false]
		);
		$this->assertFalse($c->isSourceUserMutable());
	}

	public function testEmailMutabilityFallsBackToTheDisplayNameSetting(): void {
		// Mirrors lib/private/User/User.php: allow_user_to_change_email defaults
		// to the value of allow_user_to_change_display_name.
		$c = $this->configWithSystem(
			['binding_match_source' => BindingConfig::SOURCE_EMAIL],
			['allow_user_to_change_display_name' => false]
		);
		$this->assertFalse($c->isSourceUserMutable());
	}

	public function testDisplayNameSourceFollowsItsOwnSetting(): void {
		$c = $this->configWithSystem(
			['binding_match_source' => BindingConfig::SOURCE_DISPLAYNAME],
			['allow_user_to_change_display_name' => false]
		);
		$this->assertFalse($c->isSourceUserMutable());
	}
```

The existing tests in this file construct `BindingConfig` with one argument. Update that existing private `config()` helper to pass a second mock so they keep compiling:

```php
	private function config(array $values): BindingConfig {
		return $this->configWithSystem($values, []);
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter BindingConfigTest`
Expected: FAIL — `BindingConfig::__construct()` takes 1 argument, 2 given.

- [ ] **Step 3: Implement**

In `lib/Service/Binding/BindingConfig.php`, add the import:

```php
use OCP\IConfig;
```

Change the constructor to:

```php
	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}
```

Then add these methods after `getMatchTarget()`:

```php
	/**
	 * @return string[] allowlisted Nextcloud-side source attributes
	 */
	public function getSources(): array {
		return self::SOURCES;
	}

	/**
	 * @return string[] allowlisted iTop-side targets
	 */
	public function getTargets(): array {
		return array_keys(self::TARGETS);
	}

	/**
	 * @throws \InvalidArgumentException if either value is not allowlisted
	 */
	public function setMatchRule(string $source, string $target): void {
		if (!in_array($source, self::SOURCES, true)) {
			throw new \InvalidArgumentException('Unknown match source: ' . $source);
		}
		if (!isset(self::TARGETS[$target])) {
			throw new \InvalidArgumentException('Unknown match target: ' . $target);
		}

		$this->appConfig->setValueString(Application::APP_ID, 'binding_match_source', $source);
		$this->appConfig->setValueString(Application::APP_ID, 'binding_match_target', $target);
	}

	/**
	 * Whether the configured source attribute is one an end user can change
	 * for themselves.
	 *
	 * This is the whole risk surface of self-service discovery: if a user can
	 * edit the attribute the match is made on, they can point it at a
	 * colleague's value and bind to that person. A uid is never mutable --
	 * Nextcloud exposes no setUID or rename API -- so matching on uid is safe
	 * regardless of instance settings.
	 *
	 * The email case mirrors lib/private/User/User.php: allow_user_to_change_email
	 * falls back to allow_user_to_change_display_name, and both default to true,
	 * so a stock instance is mutable.
	 */
	public function isSourceUserMutable(): bool {
		$displayNameMutable = $this->config->getSystemValueBool('allow_user_to_change_display_name', true);

		return match ($this->getMatchSource()) {
			self::SOURCE_UID => false,
			self::SOURCE_DISPLAYNAME => $displayNameMutable,
			default => $this->config->getSystemValueBool('allow_user_to_change_email', $displayNameMutable),
		};
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter BindingConfigTest`
Expected: PASS, 17 tests (the original 10 plus 7 new).

- [ ] **Step 5: Confirm nothing else broke**

Run: `vendor/bin/phpunit --testsuite unit`

`IdentityResolver`, `IdentityBindingService` and `BindUsers` receive `BindingConfig` by autowiring, so the extra constructor argument is transparent to the production code. **Five local test files construct it directly and will fail until updated** — these exact lines:

| File | Line(s) |
|---|---|
| `tests/Unit/Service/Binding/IdentityResolverTest.php` | 41 |
| `tests/Unit/Service/Binding/IdentityBindingServiceTest.php` | 40, 123 |
| `tests/Unit/Service/Binding/ResolverProbeTest.php` | 43 |
| `tests/Unit/Service/Binding/BindingProbeTest.php` | 42 |
| `tests/Unit/Service/Binding/FixVerifyTest.php` | 48 |

In each, add an `IConfig` mock and pass it as the second argument. The system values are irrelevant to those tests, so returning the default is enough:

```php
		$sysConfig = $this->createMock(\OCP\IConfig::class);
		$sysConfig->method('getSystemValueBool')->willReturnCallback(
			static fn (string $k, bool $d = false) => $d
		);
		// ... then: new BindingConfig($appConfig, $sysConfig)
```

`ResolverProbeTest`, `BindingProbeTest` and `FixVerifyTest` are controller-written probes rather than plan tests, but they must keep passing — they encode the adversarial cases and the crash-ordering invariant from phase 1.

Expected after the fix: all green, 78 tests (71 existing plus 7 new).

- [ ] **Step 6: Commit**

```bash
git add lib/Service/Binding/BindingConfig.php
git commit -m "Add match-rule setters and source mutability to BindingConfig

setMatchRule() validates against the same allowlists the getters fall back
to, so a rule can never be stored that resolveTarget() would later reject.

isSourceUserMutable() answers the question the admin warning needs: can an
end user edit the attribute the match is made on. A uid never can --
Nextcloud has no setUID or rename API -- while email mirrors the server's
own fallback chain, where both settings default to true."
```

---

## Task 2: BindingListService

**Files:**
- Create: `lib/Service/Binding/BindingListService.php`
- Test: `tests/Unit/Service/Binding/BindingListServiceTest.php`

**Interfaces:**
- Consumes: `BindingConfig::isExcluded()` (Task 1's class, unchanged behaviour), `IdentityBindingService::getBinding()`.
- Produces:
  - `BindingListService::FILTERS = ['all', 'unbound', 'bound', 'excluded']`
  - `public function list(string $filter, string $search, int $page, int $pageSize): array` returning
    `['total' => int, 'page' => int, 'pageSize' => int, 'rows' => array]`
    where each row is
    `['uid' => string, 'displayName' => string, 'email' => string, 'bound' => bool, 'personId' => ?string, 'userId' => ?string, 'method' => ?string, 'boundAt' => ?int, 'excluded' => bool]`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Binding/BindingListServiceTest.php`:

```php
<?php

namespace OCA\Itop\Tests\Unit\Service\Binding;

use OCA\Itop\Service\Binding\BindingConfig;
use OCA\Itop\Service\Binding\BindingListService;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class BindingListServiceTest extends TestCase {

	/** @param array<string,array{0:string,1:string}> $users uid => [displayName, email] */
	private function service(array $users, array $bound, array $excluded = []): BindingListService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $a, string $k, string $d = '') =>
				$k === 'binding_excluded_users' ? json_encode($excluded) : $d
		);
		$sysConfig = $this->createMock(IConfig::class);
		$sysConfig->method('getSystemValueBool')->willReturnCallback(
			static fn (string $k, bool $d = false) => $d
		);

		$userObjs = [];
		foreach ($users as $uid => [$dn, $mail]) {
			$u = $this->createMock(IUser::class);
			$u->method('getUID')->willReturn($uid);
			$u->method('getDisplayName')->willReturn($dn);
			$u->method('getSystemEMailAddress')->willReturn($mail);
			$userObjs[$uid] = $u;
		}

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForAllUsers')->willReturnCallback(
			static function (\Closure $cb) use ($userObjs) {
				foreach ($userObjs as $u) { $cb($u); }
			}
		);

		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturnCallback(
			static fn (string $uid, string $a, string $k, string $d = '') => $bound[$uid][$k] ?? $d
		);

		return new BindingListService(
			new BindingConfig($appConfig, $sysConfig),
			$userManager,
			$userConfig
		);
	}

	private function fixture(): BindingListService {
		return $this->service(
			[
				'a.bound'   => ['Alice Bound', 'alice@corp.at'],
				'b.unbound' => ['Bob Unbound', 'bob@corp.at'],
				'c.excl'    => ['Carol Excluded', 'carol@corp.at'],
				'd.unbound' => ['Dave Unbound', 'dave@corp.at'],
			],
			['a.bound' => ['person_id' => '16', 'user_id' => '2', 'binding_method' => 'admin', 'binding_at' => '1700000000']],
			['c.excl']
		);
	}

	public function testAllFilterReturnsEveryUser(): void {
		$r = $this->fixture()->list('all', '', 1, 50);
		$this->assertSame(4, $r['total']);
		$this->assertCount(4, $r['rows']);
	}

	public function testBoundFilterReturnsOnlyBoundUsers(): void {
		$r = $this->fixture()->list('bound', '', 1, 50);
		$this->assertSame(1, $r['total']);
		$this->assertSame('a.bound', $r['rows'][0]['uid']);
		$this->assertTrue($r['rows'][0]['bound']);
		$this->assertSame('16', $r['rows'][0]['personId']);
		$this->assertSame('admin', $r['rows'][0]['method']);
		$this->assertSame(1700000000, $r['rows'][0]['boundAt']);
	}

	public function testUnboundFilterExcludesBoundAndExcludedUsers(): void {
		$r = $this->fixture()->list('unbound', '', 1, 50);
		$uids = array_column($r['rows'], 'uid');
		sort($uids);
		$this->assertSame(['b.unbound', 'd.unbound'], $uids,
			'an excluded account is not an unbound candidate');
	}

	public function testExcludedFilterReturnsOnlyExcludedUsers(): void {
		$r = $this->fixture()->list('excluded', '', 1, 50);
		$this->assertSame(1, $r['total']);
		$this->assertSame('c.excl', $r['rows'][0]['uid']);
		$this->assertTrue($r['rows'][0]['excluded']);
	}

	public function testSearchMatchesUidDisplayNameAndEmailCaseInsensitively(): void {
		$s = $this->fixture();
		$this->assertSame(1, $s->list('all', 'CAROL', 1, 50)['total'], 'display name');
		$this->assertSame(1, $s->list('all', 'b.unb', 1, 50)['total'], 'uid');
		$this->assertSame(1, $s->list('all', 'dave@', 1, 50)['total'], 'email');
	}

	public function testPagingSlicesAndReportsTheUnpagedTotal(): void {
		$r = $this->fixture()->list('all', '', 2, 2);
		$this->assertSame(4, $r['total'], 'total is the filtered count, not the page size');
		$this->assertCount(2, $r['rows']);
		$this->assertSame(2, $r['page']);
	}

	public function testPageBeyondTheEndReturnsNoRowsNotAnError(): void {
		$r = $this->fixture()->list('all', '', 99, 50);
		$this->assertSame(4, $r['total']);
		$this->assertSame([], $r['rows']);
	}

	public function testUnknownFilterFallsBackToAll(): void {
		$this->assertSame(4, $this->fixture()->list('nonsense', '', 1, 50)['total']);
	}

	public function testRowsAreSortedByUidSoPagingIsStable(): void {
		$r = $this->fixture()->list('all', '', 1, 50);
		$uids = array_column($r['rows'], 'uid');
		$sorted = $uids;
		sort($sorted);
		$this->assertSame($sorted, $uids, 'unstable order would duplicate or skip rows across pages');
	}

	public function testABoundUserWithoutAUserIdReportsNull(): void {
		$s = $this->service(
			['p.only' => ['Portal Only', 'p@corp.at']],
			['p.only' => ['person_id' => '12', 'binding_method' => 'admin']]
		);
		$row = $s->list('bound', '', 1, 50)['rows'][0];
		$this->assertSame('12', $row['personId']);
		$this->assertNull($row['userId'], 'a Person with no iTop User is still a valid binding');
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter BindingListServiceTest`
Expected: FAIL — `Class "OCA\Itop\Service\Binding\BindingListService" not found`.

- [ ] **Step 3: Implement**

Create `lib/Service/Binding/BindingListService.php`:

```php
<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCA\Itop\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Lists Nextcloud users together with their iTop binding state.
 *
 * Enumerates every user to compute filtered totals: "how many accounts are
 * still unbound" cannot be answered from a single page, and a page-local
 * answer would under-report. That is fine at helpdesk scale and matches what
 * occ itop:bind already does; it is recorded as a known limit in the spec.
 */
class BindingListService {

	public const FILTER_ALL = 'all';
	public const FILTER_UNBOUND = 'unbound';
	public const FILTER_BOUND = 'bound';
	public const FILTER_EXCLUDED = 'excluded';

	public const FILTERS = [
		self::FILTER_ALL,
		self::FILTER_UNBOUND,
		self::FILTER_BOUND,
		self::FILTER_EXCLUDED,
	];

	public function __construct(
		private BindingConfig $bindingConfig,
		private IUserManager $userManager,
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * @return array{total: int, page: int, pageSize: int, rows: array}
	 */
	public function list(string $filter, string $search, int $page, int $pageSize): array {
		if (!in_array($filter, self::FILTERS, true)) {
			$filter = self::FILTER_ALL;
		}
		$page = max(1, $page);
		$pageSize = max(1, min(500, $pageSize));

		$rows = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$rows, $filter, $search) {
			$row = $this->describe($user);

			if (!$this->matchesSearch($row, $search)) {
				return;
			}
			if (!$this->matchesFilter($row, $filter)) {
				return;
			}

			$rows[] = $row;
		});

		// Stable order: without it, paging can repeat or skip rows between calls.
		usort($rows, static fn (array $a, array $b) => strcmp($a['uid'], $b['uid']));

		return [
			'total' => count($rows),
			'page' => $page,
			'pageSize' => $pageSize,
			'rows' => array_values(array_slice($rows, ($page - 1) * $pageSize, $pageSize)),
		];
	}

	/**
	 * @return array{uid: string, displayName: string, email: string, bound: bool,
	 *               personId: ?string, userId: ?string, method: ?string,
	 *               boundAt: ?int, excluded: bool}
	 */
	private function describe(IUser $user): array {
		$uid = $user->getUID();

		$personId = $this->userConfig->getValueString($uid, Application::APP_ID, 'person_id', '');
		$userId = $this->userConfig->getValueString($uid, Application::APP_ID, 'user_id', '');
		$method = $this->userConfig->getValueString($uid, Application::APP_ID, 'binding_method', '');
		$boundAt = $this->userConfig->getValueString($uid, Application::APP_ID, 'binding_at', '');

		return [
			'uid' => $uid,
			'displayName' => $user->getDisplayName(),
			'email' => (string)$user->getSystemEMailAddress(),
			'bound' => $personId !== '',
			'personId' => $personId !== '' ? $personId : null,
			'userId' => $userId !== '' ? $userId : null,
			'method' => $method !== '' ? $method : null,
			'boundAt' => $boundAt !== '' ? (int)$boundAt : null,
			'excluded' => $this->bindingConfig->isExcluded($uid),
		];
	}

	private function matchesSearch(array $row, string $search): bool {
		$needle = trim(mb_strtolower($search));
		if ($needle === '') {
			return true;
		}

		foreach (['uid', 'displayName', 'email'] as $field) {
			if (str_contains(mb_strtolower((string)$row[$field]), $needle)) {
				return true;
			}
		}

		return false;
	}

	private function matchesFilter(array $row, string $filter): bool {
		return match ($filter) {
			// An excluded account is not an unbound candidate: it is deliberately
			// out of scope for matching, so listing it as work-to-do is wrong.
			self::FILTER_UNBOUND => !$row['bound'] && !$row['excluded'],
			self::FILTER_BOUND => $row['bound'],
			self::FILTER_EXCLUDED => $row['excluded'],
			default => true,
		};
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter BindingListServiceTest`
Expected: `OK (10 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add lib/Service/Binding/BindingListService.php
git commit -m "Add user listing with binding state

Enumerates all users to compute filtered totals: 'how many accounts are
still unbound' cannot be answered from one page, and a page-local answer
would under-report the work remaining. Acceptable at helpdesk scale and
consistent with occ itop:bind; recorded as a known limit in the spec.

Rows are sorted by uid because an unstable order makes paging repeat or
skip entries between requests. An excluded account is not counted as an
unbound candidate - it is deliberately out of scope, not outstanding work."
```

---

## Task 3: BindingController and routes

**Files:**
- Create: `lib/Controller/BindingController.php`
- Modify: `appinfo/routes.php`
- Test: manual, against the dev VM

**Interfaces:**
- Consumes: `BindingListService::list()` and `BindingListService::FILTERS` (Task 2), `BindingConfig::setMatchRule()` / `getMatchSource()` / `getMatchTarget()` / `getSources()` / `getTargets()` / `isSourceUserMutable()` / `setExcluded()` (Task 1), `IdentityBindingService::unbind()`.
- Produces these routes, all admin-only:

| Method | URL | Purpose |
|---|---|---|
| GET | `/binding/users` | `filter`, `search`, `page`, `pageSize` → the list payload |
| GET | `/binding/rule` | current rule, the allowlists, and the mutability flag |
| POST | `/binding/rule` | `{source, target}` → saves, 400 on a non-allowlisted value |
| POST | `/binding/exclude` | `{uids: []}` → excludes each |
| POST | `/binding/restore` | `{uids: []}` → un-excludes each |
| DELETE | `/binding/{uid}` | unbind one account |

- [ ] **Step 1: Implement the controller**

Create `lib/Controller/BindingController.php`:

```php
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
```

- [ ] **Step 2: Register the routes**

In `appinfo/routes.php`, add these entries inside the `'routes'` array, immediately after the existing `config#checkVersion` line:

```php
		// Identity binding (admin)
		['name' => 'binding#getUsers', 'url' => '/binding/users', 'verb' => 'GET'],
		['name' => 'binding#getRule', 'url' => '/binding/rule', 'verb' => 'GET'],
		['name' => 'binding#setRule', 'url' => '/binding/rule', 'verb' => 'POST'],
		['name' => 'binding#exclude', 'url' => '/binding/exclude', 'verb' => 'POST'],
		['name' => 'binding#restore', 'url' => '/binding/restore', 'verb' => 'POST'],
		['name' => 'binding#unbind', 'url' => '/binding/{uid}', 'verb' => 'DELETE'],
```

- [ ] **Step 3: Verify it parses and the unit suite still passes**

```bash
php -l lib/Controller/BindingController.php
php -l appinfo/routes.php
vendor/bin/phpunit --testsuite unit
```

Expected: no syntax errors, all tests green.

- [ ] **Step 4: Verify the endpoints on the dev VM**

Run this and paste the real output into your report:

```bash
orb -m nextcloud-dev sudo bash -lc '
PW=$(cat /home/lexioj/.nextadmin-password)
J=/tmp/bk.txt; rm -f $J
T=$(curl -s -c $J -b $J -u "nextadmin:$PW" "http://localhost/index.php/settings/admin/integration_itop" | grep -o "requesttoken=\"[^\"]*\"" | head -1 | sed "s/^requesttoken=\"//; s/\"$//")
B=http://localhost/index.php/apps/integration_itop/binding
echo "--- rule ---";    curl -s -c $J -b $J -u "nextadmin:$PW" -H "requesttoken: $T" "$B/rule"
echo; echo "--- users (all) ---";  curl -s -c $J -b $J -u "nextadmin:$PW" -H "requesttoken: $T" "$B/users?filter=all&pageSize=3"
echo; echo "--- users (unbound) ---"; curl -s -c $J -b $J -u "nextadmin:$PW" -H "requesttoken: $T" "$B/users?filter=unbound"
echo; echo "--- bad rule rejected ---"; curl -s -o /dev/null -w "%{http_code}\n" -c $J -b $J -u "nextadmin:$PW" -X POST -H "requesttoken: $T" -H "Content-Type: application/json" -d "{\"source\":\"bogus\",\"target\":\"person.email\"}" "$B/rule"
'
```

Expected: the rule payload lists all three sources and targets with `source_user_mutable: true` (the dev instance does not lock email); the user list shows `nextadmin` as bound with `method: "token"` and `pablo`/`test1`/`test2` unbound; the bad rule returns **400**.

- [ ] **Step 5: Verify exclusion round-trips, then restore state**

```bash
orb -m nextcloud-dev sudo bash -lc '
PW=$(cat /home/lexioj/.nextadmin-password)
J=/tmp/bk.txt
T=$(curl -s -c $J -b $J -u "nextadmin:$PW" "http://localhost/index.php/settings/admin/integration_itop" | grep -o "requesttoken=\"[^\"]*\"" | head -1 | sed "s/^requesttoken=\"//; s/\"$//")
B=http://localhost/index.php/apps/integration_itop/binding
curl -s -c $J -b $J -u "nextadmin:$PW" -X POST -H "requesttoken: $T" -H "Content-Type: application/json" -d "{\"uids\":[\"test2\"]}" "$B/exclude"; echo
curl -s -c $J -b $J -u "nextadmin:$PW" -H "requesttoken: $T" "$B/users?filter=excluded"; echo
curl -s -c $J -b $J -u "nextadmin:$PW" -X POST -H "requesttoken: $T" -H "Content-Type: application/json" -d "{\"uids\":[\"test2\"]}" "$B/restore"; echo
sudo -u www-data php /var/www/nextcloud/occ config:app:get integration_itop binding_excluded_users
'
```

Expected: `test2` appears under the `excluded` filter, then the restore empties the list again. **Do not leave any exclusion behind.** Do not call the DELETE unbind endpoint against `nextadmin` — it is the only bound account on the instance and unbinding it would require a personal token to restore.

- [ ] **Step 6: Commit**

```bash
git add lib/Controller/BindingController.php appinfo/routes.php
git commit -m "Add admin binding endpoints

Listing, the match rule, exclusions and unbind, in their own controller
rather than ConfigController, which is already ~2,400 lines.

No endpoint carries NoAdminRequired: every action here is administrative.
A non-allowlisted source or target is rejected with 400 rather than
stored, so a rule that resolveTarget() would later refuse can never be
persisted."
```

---

## Task 4: Matching-rule settings UI

**Files:**
- Modify: `lib/Settings/Admin.php`
- Modify: `templates/adminSettings.php`
- Modify: `src/admin-settings.js`
- Modify: `css/admin-settings.css`
- Test: manual, in the browser

**Interfaces:**
- Consumes: `GET`/`POST /binding/rule` (Task 3), `BindingConfig::getMatchSource()` / `getMatchTarget()` / `getSources()` / `getTargets()` / `isSourceUserMutable()` (Task 1).
- Produces: a configurable matching rule in the admin UI. No new interface for later tasks.

- [ ] **Step 1: Pass the rule to the template**

In `lib/Settings/Admin.php`, add the import:

```php
use OCA\Itop\Service\Binding\BindingConfig;
```

Add `private BindingConfig $bindingConfig,` to the promoted constructor properties, then add these keys to the `$parameters` array, just before its closing bracket:

```php
			// Identity binding match rule
			'binding_match_source' => $this->bindingConfig->getMatchSource(),
			'binding_match_target' => $this->bindingConfig->getMatchTarget(),
			'binding_sources' => $this->bindingConfig->getSources(),
			'binding_targets' => $this->bindingConfig->getTargets(),
			'binding_source_user_mutable' => $this->bindingConfig->isSourceUserMutable(),
```

- [ ] **Step 2: Render the section**

In `templates/adminSettings.php`, add this immediately before the final closing `</div>` of the file. Keep the file's existing tab indentation.

```php
<div id="itop-binding-rule" class="section">
	<h2><?php p($l->t('Identity binding')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('How Nextcloud accounts are matched to people in %s. Used by admin-side binding and by the occ itop:bind command.', [$_['user_facing_name']])); ?>
	</p>

	<?php
	$sourceLabels = [
		'email' => $l->t('Email address'),
		'uid' => $l->t('User ID'),
		'displayname' => $l->t('Display name'),
	];
	$targetLabels = [
		'person.email' => $l->t('Person: email'),
		'person.employee_number' => $l->t('Person: employee number'),
		'user.login' => $l->t('User: login'),
	];
	?>

	<div class="itop-binding-rule-row">
		<label for="itop-binding-source"><?php p($l->t('Match Nextcloud')); ?></label>
		<select id="itop-binding-source">
			<?php foreach ($_['binding_sources'] as $source) { ?>
				<option value="<?php p($source); ?>" <?php if ($source === $_['binding_match_source']) { p('selected'); } ?>>
					<?php p($sourceLabels[$source] ?? $source); ?>
				</option>
			<?php } ?>
		</select>

		<label for="itop-binding-target"><?php p($l->t('against iTop')); ?></label>
		<select id="itop-binding-target">
			<?php foreach ($_['binding_targets'] as $target) { ?>
				<option value="<?php p($target); ?>" <?php if ($target === $_['binding_match_target']) { p('selected'); } ?>>
					<?php p($targetLabels[$target] ?? $target); ?>
				</option>
			<?php } ?>
		</select>

		<button id="itop-binding-rule-save"><?php p($l->t('Save')); ?></button>
	</div>

	<div id="itop-binding-mutable-warning" class="itop-binding-warning<?php if (!$_['binding_source_user_mutable']) { p(' hidden'); } ?>">
		<strong><?php p($l->t('Users can change this attribute themselves on this instance.')); ?></strong>
		<?php p($l->t('If self-service discovery is enabled later, a user could set their value to a colleague\'s and bind to that person, gaining read access to their tickets. Set allow_user_to_change_email to false, or match on User ID, which cannot be changed. Admin-side binding and occ itop:bind are unaffected.')); ?>
	</div>
</div>
```

- [ ] **Step 3: Style the warning**

Append to `css/admin-settings.css`:

```css
/* Identity binding: match rule */
.itop-binding-rule-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.itop-binding-warning {
	padding: 10px 12px;
	border-left: 4px solid var(--color-error, #c00);
	background-color: var(--color-background-hover, #f5f5f5);
	border-radius: var(--border-radius, 3px);
	max-width: 700px;
}

.itop-binding-warning.hidden {
	display: none;
}
```

- [ ] **Step 4: Wire the save handler**

In `src/admin-settings.js`, inside the existing `attachEventHandlers()` function, add:

```js
		const bindingRuleSave = document.getElementById('itop-binding-rule-save')
		if (bindingRuleSave) {
			bindingRuleSave.addEventListener('click', saveBindingRule)
		}
```

Then add this function alongside the other save functions in the same file:

```js
	/**
	 * Persist the identity-binding match rule.
	 */
	function saveBindingRule() {
		const source = document.getElementById('itop-binding-source').value
		const target = document.getElementById('itop-binding-target').value

		fetch(generateUrl('/apps/integration_itop/binding/rule'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ source, target }),
		})
			.then(response => response.json().then(data => ({ ok: response.ok, data })))
			.then(({ ok, data }) => {
				if (!ok) {
					showNotification(data.message || t('integration_itop', 'Could not save the matching rule'), true)
					return
				}
				// The warning depends on the chosen source, so refresh it from the
				// server's answer rather than guessing client-side.
				const warning = document.getElementById('itop-binding-mutable-warning')
				if (warning) {
					warning.classList.toggle('hidden', !data.source_user_mutable)
				}
				showNotification(t('integration_itop', 'Matching rule saved'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Could not save the matching rule'), true)
			})
	}
```

- [ ] **Step 5: Build and lint**

```bash
npm run lint
npm run build
```

Expected: eslint reports no **errors** (pre-existing JSDoc and `v-html` warnings are fine); the build succeeds.

- [ ] **Step 6: Verify in the browser**

The dev VM symlinks this worktree, so the build is live. Load `https://nextcloud-dev.orb.local/index.php/settings/admin/integration_itop` and confirm:

- An **Identity binding** section renders with both selects pre-filled from the stored rule (`email` → `person.email` by default).
- The red mutability warning is **visible**, because the dev instance does not set `allow_user_to_change_email`.
- Changing the source to **User ID** and clicking Save makes the warning **disappear** without a page reload, and a success toast appears.
- Reloading the page keeps User ID selected and the warning hidden.
- Setting it back to **Email address** and saving makes the warning reappear.

Then confirm the stored value and restore the default:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:get integration_itop binding_match_source
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:set integration_itop binding_match_source --value=email
```

Report what you actually saw, including console errors if any.

- [ ] **Step 7: Commit**

```bash
git add lib/Settings/Admin.php templates/adminSettings.php src/admin-settings.js css/admin-settings.css
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Add the identity binding match rule to admin settings

The 1.5.0 notes describe an admin-configured source and target, but the
only way to set them was occ config:app:set. This makes them reachable.

The mutability warning names the specific attack and its remedies rather
than being generic, and is refreshed from the server's response after a
save because whether it applies depends on the chosen source."
```

---

## Task 5: Documentation

**Files:**
- Modify: `CHANGELOG.md`, `README.md`

- [ ] **Step 1: Add an Unreleased changelog section**

In `CHANGELOG.md`, directly beneath the `# Changelog` header block and its `---` separator, and **above** `## [1.5.0] - 2026-10-24`, insert:

```markdown
## [Unreleased]

### Added
- **Identity binding settings and API**: the matching rule (which Nextcloud attribute is matched against which iTop attribute) is now configurable from Admin settings → iTop instead of only through `occ config:app:set`, and shows a warning when the chosen attribute is one users can change for themselves. Adds admin endpoints for listing accounts with their binding state, managing exclusions, and unbinding, which the association table builds on

---
```

- [ ] **Step 2: Replace the README occ-configuration block**

In `README.md`, find the fenced `bash` block added in 1.5.0 that documents `occ config:app:set integration_itop binding_match_source` and replace the whole block with:

```markdown
The matching rule is configured in **Admin settings → iTop → Identity binding**. Accounts can be
excluded from matching there too. The equivalent `occ` commands remain available for scripted
setups:

```bash
occ config:app:set integration_itop binding_match_source --value=email   # email | uid | displayname
occ config:app:set integration_itop binding_match_target --value=person.email  # person.email | person.employee_number | user.login
occ config:app:set integration_itop binding_excluded_users --value='["svc.monitor"]'
```
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md README.md
git commit -m "Document the identity binding settings UI"
```

---

## Out of scope for this plan

Phase 2b, built on these endpoints:

- The association table: paging, filters, search, multi-select, bulk action bar, origin badges, per-row actions.
- "Suggest matches" and bulk apply, including the `exact` / `review` / `ambiguous` / `none` tiers in the UI. The `review` tier is an outcome of a suggestion, not a stored state, which is why it is not a server-side filter here.
- A `POST /binding/suggest` endpoint and a `POST /binding/apply` endpoint that re-resolves before writing.
- `GET /binding/search-person` for the manual "Choose…" picker on ambiguous rows.

Phase 4 remains self-service discovery.

## Carried-over findings to address while in this code

From the spec's deferred list, these sit in code this plan touches:

- `IdentityResolver::findUserIdForPerson()` duplicates `ProfileService::getUserIdByPersonId()`, and the two have already diverged on validation. Collapse them when 2b touches the resolver.
- A Person with several iTop Users resolves arbitrarily via `reset()`. 2b's bulk apply persists that pick at scale, so handle it there.
- `getBinding()` returns `method => null` for every pre-existing binding. The origin badge in 2b must render that case as something meaningful rather than blank.
