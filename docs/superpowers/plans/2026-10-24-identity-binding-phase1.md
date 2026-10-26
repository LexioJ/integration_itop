# iTop Identity Binding — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an administrator bind Nextcloud users to their iTop identity from the command line, without those users ever touching iTop.

**Architecture:** One `IdentityResolver` answers "which iTop identity matches this Nextcloud user?" and is the single matcher that later phases (admin UI, self-service discovery) reuse. `IdentityBindingService` applies the result to per-user config. `BindUsers` is an occ command that drives both. Matching logic is split into pure functions so it is unit-testable without a server.

**Tech Stack:** PHP 8.1+, Nextcloud 32–35 app framework, `OCP\IAppConfig` / `OCP\Config\IUserConfig`, Symfony Console (via `OCP\AppFramework\Command`), PHPUnit 9.5.

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. Do not use APIs newer than 32.
- Config access uses **`getValueString` / `setValueString` only** — never the typed `getValueInt` / `getValueBool` variants. The first typed write pins a key's type permanently and a later string write raises `AppConfigTypeConflictException`. See CHANGELOG 1.5.0.
- App id constant is `Application::APP_ID` (`'integration_itop'`). Never hard-code the string.
- `declare(strict_types=1)` is **not** used anywhere in `lib/`. Do not introduce it — it would change coercion behaviour in existing call paths.
- Constructor dependencies use **promoted properties**, matching every existing service.
- Services autowire. Only the two dashboard widgets are explicitly registered in `Application::register()`; do **not** add registrations for the classes in this plan.
- **Tests are local-only and never committed.** `tests/`, `phpunit.xml` and `docs/testing.md` are gitignored. Write the tests, run them, but add only `lib/` files to commits.
- Mocking an OCP interface in a test needs the `nextcloud/ocp` stubs, which are a `require-dev` dependency pinned to `^32.0` and mapped under **`autoload-dev`** (Nextcloud supplies OCP at runtime, so the stubs must never reach the production autoloader). This is already in place — do not re-add it, and do not downgrade the pin: the v29 stubs lack `OCP\Config\IUserConfig`, which is `@since 32` and is mocked in Task 6.
- OQL values must be escaped with `ItopAPIService::escapeOQLString()`. OQL **field and class names** must come from an allowlist — escaping does not protect identifiers.
- The lookup value is always derived server-side from the Nextcloud account. No code in this plan accepts an identity value from a request.

### Deviation from the spec's phasing

The spec listed the occ command as phase 3. It is pulled into phase 1 here because phases 1 and 2 of the spec would otherwise produce no runnable entry point, leaving the resolver verifiable only by unit tests. With the command included, phase 1 delivers working admin-side binding end to end. The admin UI (spec phase 2) and discovery (spec phase 4) each become their own plan.

### Blocker this plan resolves

`ItopAPIService::request()` refuses any user without a bound `person_id`:

```php
$personId = $this->getPersonId($userId);
if (!$personId) {
    return ['error' => $this->l10n->t('User not configured'), ...];
}
```

Unbound users are exactly the population binding must query, so Task 1 adds an application-scoped request path that does not require a binding. This is the same reason `ConfigController` currently makes its own raw HTTP calls during token validation.

---

## File Structure

| File | Responsibility |
|---|---|
| `lib/Service/ItopAPIService.php` *(modify)* | Add `appRequest()`; widen `escapeOQLString()` to public |
| `lib/Service/Binding/BindingConfig.php` *(create)* | Read/write binding app config; source and target allowlists |
| `lib/Service/Binding/NameMatcher.php` *(create)* | Pure name normalisation and comparison |
| `lib/Service/Binding/MatchResult.php` *(create)* | Immutable result value object + tier constants |
| `lib/Service/Binding/IdentityResolver.php` *(create)* | Build OQL, query iTop, assign tier |
| `lib/Service/Binding/IdentityBindingService.php` *(create)* | Apply/remove bindings, exclusions, audit log |
| `lib/Command/BindUsers.php` *(create)* | `occ itop:bind` |
| `appinfo/info.xml` *(modify)* | Register the command |

A `Binding/` sub-namespace keeps six small focused classes together rather than growing `lib/Service/` flat. `ConfigController` is deliberately untouched — it is already ~2,400 lines.

---

## Task 1: Application-scoped iTop requests

**Files:**
- Modify: `lib/Service/ItopAPIService.php:136` (visibility), and add a new method after `request()`
- Test: none — this method performs HTTP and is verified manually in Task 7

**Interfaces:**
- Consumes: nothing from earlier tasks
- Produces:
  - `public function escapeOQLString(string $value): string`
  - `public function appRequest(array $params): array` — posts `$params` to iTop with the **application token** and the admin instance URL. Returns the decoded response array, or `['error' => string]`. Does not require the caller to be a bound user and does not use the query cache.

- [ ] **Step 1: Make the OQL escaper public**

In `lib/Service/ItopAPIService.php`, change line 136 from:

```php
	private function escapeOQLString(string $value): string {
```

to:

```php
	public function escapeOQLString(string $value): string {
```

- [ ] **Step 2: Add `appRequest()`**

Insert this method immediately after the closing brace of `request()` in `lib/Service/ItopAPIService.php`:

```php
	/**
	 * Make an application-scoped request to the iTop REST API
	 *
	 * Unlike request(), this does not require the caller to be a bound user. It is
	 * used by identity binding, which by definition runs for users that have no
	 * person_id yet. Always uses the admin instance URL and the application token,
	 * and never consults the query cache, because callers act across users.
	 *
	 * @param array $params API parameters (operation, class, key, output_fields)
	 * @return array Decoded response, or ['error' => string] on failure
	 */
	public function appRequest(array $params): array {
		$itopUrl = $this->appConfig->getValueString(Application::APP_ID, 'admin_instance_url', '');
		if ($itopUrl === '') {
			return ['error' => $this->l10n->t('iTop URL not configured')];
		}

		$accessToken = $this->getApplicationToken();
		if (!$accessToken) {
			return ['error' => $this->l10n->t('Application token not configured')];
		}

		try {
			$response = $this->client->post(rtrim($itopUrl, '/') . '/webservices/rest.php?version=1.3', [
				'headers' => [
					'User-Agent' => 'Nextcloud iTop integration',
					'Auth-Token' => $accessToken,
				],
				'form_params' => [
					'json_data' => json_encode($params),
				],
			]);

			$result = json_decode($response->getBody(), true);
			if ($result === null) {
				return ['error' => $this->l10n->t('Invalid JSON response from iTop')];
			}

			return $this->normalizeResponseDates($result);
		} catch (ServerException|ClientException $e) {
			$this->logger->warning('iTop app request failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			return ['error' => $e->getMessage()];
		}
	}
```

- [ ] **Step 3: Verify it parses**

Run: `php -l lib/Service/ItopAPIService.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Verify no caller depended on the old visibility**

Run: `grep -rn "escapeOQLString" lib/`
Expected: only definition plus existing internal `$this->escapeOQLString(...)` calls. Widening visibility breaks nothing.

- [ ] **Step 5: Commit**

```bash
git add lib/Service/ItopAPIService.php
git commit -m "Add application-scoped iTop requests for identity binding

request() refuses any user without a bound person_id, which is exactly
the population identity binding has to query. appRequest() uses the
application token and the admin instance URL, needs no binding, and
skips the per-user query cache because callers act across users.

escapeOQLString() becomes public so the resolver can reuse it rather
than reimplementing OQL escaping."
```

---

## Task 2: Binding configuration and allowlists

**Files:**
- Create: `lib/Service/Binding/BindingConfig.php`
- Test: `tests/Unit/Service/Binding/BindingConfigTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks
- Produces:
  - `BindingConfig::SOURCE_EMAIL = 'email'`, `SOURCE_UID = 'uid'`, `SOURCE_DISPLAYNAME = 'displayname'`
  - `BindingConfig::TARGET_PERSON_EMAIL = 'person.email'`, `TARGET_PERSON_EMPLOYEE_NUMBER = 'person.employee_number'`, `TARGET_USER_LOGIN = 'user.login'`
  - `public function isTokenBindingEnabled(): bool`
  - `public function isDiscoveryEnabled(): bool`
  - `public function getMatchSource(): string` — always a valid `SOURCE_*`
  - `public function getMatchTarget(): string` — always a valid `TARGET_*`
  - `public function resolveTarget(string $target): array` — `['class' => 'Person'|'User', 'field' => string]`; throws `InvalidArgumentException` for anything not allowlisted
  - `public function getExcludedUsers(): array` — list of uid strings
  - `public function isExcluded(string $uid): bool`
  - `public function setExcluded(string $uid, bool $excluded): void`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Binding/BindingConfigTest.php`:

```php
<?php

namespace OCA\Itop\Tests\Unit\Service\Binding;

use OCA\Itop\Service\Binding\BindingConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class BindingConfigTest extends TestCase {

	private function config(array $values): BindingConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $values[$key] ?? $default
		);
		return new BindingConfig($appConfig);
	}

	public function testTokenBindingDefaultsToEnabled(): void {
		$this->assertTrue($this->config([])->isTokenBindingEnabled());
	}

	public function testDiscoveryDefaultsToDisabled(): void {
		$this->assertFalse($this->config([])->isDiscoveryEnabled());
	}

	public function testDiscoveryCanBeEnabled(): void {
		$this->assertTrue($this->config(['binding_discovery_enabled' => '1'])->isDiscoveryEnabled());
	}

	public function testMatchSourceDefaultsToEmail(): void {
		$this->assertSame(BindingConfig::SOURCE_EMAIL, $this->config([])->getMatchSource());
	}

	public function testUnknownStoredSourceFallsBackToDefault(): void {
		$c = $this->config(['binding_match_source' => 'something_invalid']);
		$this->assertSame(BindingConfig::SOURCE_EMAIL, $c->getMatchSource());
	}

	public function testUnknownStoredTargetFallsBackToDefault(): void {
		$c = $this->config(['binding_match_target' => 'person.injected OR 1=1']);
		$this->assertSame(BindingConfig::TARGET_PERSON_EMAIL, $c->getMatchTarget());
	}

	public function testResolveTargetMapsToClassAndField(): void {
		$this->assertSame(
			['class' => 'User', 'field' => 'login'],
			$this->config([])->resolveTarget(BindingConfig::TARGET_USER_LOGIN)
		);
	}

	public function testResolveTargetRejectsAnythingNotAllowlisted(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->config([])->resolveTarget("email' OR 1=1 --");
	}

	public function testExclusionsAreParsedFromJson(): void {
		$c = $this->config(['binding_excluded_users' => '["svc.monitor","room.boardroom"]']);
		$this->assertTrue($c->isExcluded('svc.monitor'));
		$this->assertFalse($c->isExcluded('b.vian'));
	}

	public function testCorruptExclusionJsonYieldsNoExclusions(): void {
		$c = $this->config(['binding_excluded_users' => 'not json']);
		$this->assertSame([], $c->getExcludedUsers());
	}
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --filter BindingConfigTest`
Expected: FAIL — `Class "OCA\Itop\Service\Binding\BindingConfig" not found`

- [ ] **Step 3: Implement**

Create `lib/Service/Binding/BindingConfig.php`:

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
use OCP\IAppConfig;

/**
 * Reads and writes the identity binding settings.
 *
 * Admin-side binding is always available and therefore has no toggle. Only the
 * two self-service paths can be switched off.
 */
class BindingConfig {

	public const SOURCE_EMAIL = 'email';
	public const SOURCE_UID = 'uid';
	public const SOURCE_DISPLAYNAME = 'displayname';

	public const TARGET_PERSON_EMAIL = 'person.email';
	public const TARGET_PERSON_EMPLOYEE_NUMBER = 'person.employee_number';
	public const TARGET_USER_LOGIN = 'user.login';

	private const SOURCES = [
		self::SOURCE_EMAIL,
		self::SOURCE_UID,
		self::SOURCE_DISPLAYNAME,
	];

	/**
	 * Allowlist of iTop query targets.
	 *
	 * These end up as OQL class and field *identifiers*. String escaping only
	 * protects literals, so an identifier must never come from stored input
	 * without passing through this map.
	 */
	private const TARGETS = [
		self::TARGET_PERSON_EMAIL => ['class' => 'Person', 'field' => 'email'],
		self::TARGET_PERSON_EMPLOYEE_NUMBER => ['class' => 'Person', 'field' => 'employee_number'],
		self::TARGET_USER_LOGIN => ['class' => 'User', 'field' => 'login'],
	];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function isTokenBindingEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'binding_token_enabled', '1') === '1';
	}

	public function isDiscoveryEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'binding_discovery_enabled', '0') === '1';
	}

	public function getMatchSource(): string {
		$source = $this->appConfig->getValueString(Application::APP_ID, 'binding_match_source', self::SOURCE_EMAIL);
		return in_array($source, self::SOURCES, true) ? $source : self::SOURCE_EMAIL;
	}

	public function getMatchTarget(): string {
		$target = $this->appConfig->getValueString(Application::APP_ID, 'binding_match_target', self::TARGET_PERSON_EMAIL);
		return isset(self::TARGETS[$target]) ? $target : self::TARGET_PERSON_EMAIL;
	}

	/**
	 * @return array{class: string, field: string}
	 * @throws \InvalidArgumentException if the target is not allowlisted
	 */
	public function resolveTarget(string $target): array {
		if (!isset(self::TARGETS[$target])) {
			throw new \InvalidArgumentException('Unknown iTop match target: ' . $target);
		}
		return self::TARGETS[$target];
	}

	/**
	 * @return string[] Nextcloud user ids excluded from matching
	 */
	public function getExcludedUsers(): array {
		$json = $this->appConfig->getValueString(Application::APP_ID, 'binding_excluded_users', '');
		if ($json === '') {
			return [];
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_filter($decoded, 'is_string'));
	}

	public function isExcluded(string $uid): bool {
		return in_array($uid, $this->getExcludedUsers(), true);
	}

	public function setExcluded(string $uid, bool $excluded): void {
		$current = $this->getExcludedUsers();
		$next = $excluded
			? array_values(array_unique([...$current, $uid]))
			: array_values(array_diff($current, [$uid]));

		$this->appConfig->setValueString(Application::APP_ID, 'binding_excluded_users', json_encode($next));
	}
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/phpunit --filter BindingConfigTest`
Expected: `OK (10 tests, ...)`

- [ ] **Step 5: Commit (lib only — tests are gitignored)**

```bash
git add lib/Service/Binding/BindingConfig.php
git commit -m "Add identity binding configuration

Admin-side binding is always available so it has no toggle; only the two
self-service paths can be switched off. Token binding defaults to on to
preserve current behaviour, discovery to off because it is the only path
where an untrusted attribute decides an identity.

Match targets resolve through an allowlist. They become OQL identifiers,
and escaping only protects literals, so a stored value must never reach
a query unmapped."
```

---

## Task 3: Name normalisation and comparison

**Files:**
- Create: `lib/Service/Binding/NameMatcher.php`
- Test: `tests/Unit/Service/Binding/NameMatcherTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks
- Produces:
  - `public static function normalize(string $name): string` — lowercased, diacritics stripped, punctuation to spaces, whitespace collapsed, tokens sorted and rejoined with single spaces
  - `public static function matches(string $a, string $b): bool` — true when both normalize identically and are non-empty

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Binding/NameMatcherTest.php`:

```php
<?php

namespace OCA\Itop\Tests\Unit\Service\Binding;

use OCA\Itop\Service\Binding\NameMatcher;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase {

	/** @dataProvider matchProvider */
	public function testMatches(string $a, string $b, bool $expected, string $because): void {
		$this->assertSame($expected, NameMatcher::matches($a, $b), $because);
	}

	public function matchProvider(): array {
		return [
			'identical' => ['Boris Vian', 'Boris Vian', true, 'same name'],
			'case differs' => ['boris vian', 'BORIS VIAN', true, 'case is not significant'],
			'padding differs' => ['  Boris   Vian ', 'Boris Vian', true, 'whitespace is collapsed'],
			'first/last order' => ['Vian Boris', 'Boris Vian', true, 'iTop reports first+last, Nextcloud one string'],
			'diacritics' => ['Jose Garcia', 'José García', true, 'accents must not split a match'],
			'hyphen vs space' => ['Anna Schmidt-Berger', 'Anna Schmidt Berger', true, 'punctuation is a separator'],
			'different people' => ['Anna Schmidt', 'Anneliese Schmidt-Berger', false, 'this is the review case'],
			'abbreviated first name' => ['Alex Askin', 'Alexander Askin', false, 'strict: must land in review'],
			'empty vs name' => ['', 'Boris Vian', false, 'an empty name never matches'],
			'both empty' => ['', '', false, 'two empty names are not a match'],
		];
	}

	public function testNormalizeSortsTokens(): void {
		$this->assertSame('boris vian', NameMatcher::normalize('Vian, Boris'));
	}
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --filter NameMatcherTest`
Expected: FAIL — `Class "OCA\Itop\Service\Binding\NameMatcher" not found`

- [ ] **Step 3: Implement**

Create `lib/Service/Binding/NameMatcher.php`:

```php
<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

/**
 * Compares a Nextcloud display name with an iTop person name.
 *
 * Comparison is strict: anything that does not normalise to exactly the same
 * token set is treated as a mismatch, which sends the match to the "review"
 * tier for a human to judge. "Alex Askin" and "Alexander Askin" therefore do
 * not match. That costs an administrator a click and avoids silently binding
 * a user to the wrong person.
 *
 * Pure functions: no API or cache access, so they are directly unit-testable.
 */
class NameMatcher {

	/**
	 * Reduce a name to a canonical form: lowercase, unaccented, punctuation
	 * treated as a separator, tokens sorted so word order does not matter
	 * (iTop reports first and last name separately, Nextcloud as one string).
	 */
	public static function normalize(string $name): string {
		$name = self::stripDiacritics($name);
		$name = mb_strtolower($name);

		// Any non-alphanumeric run is a separator, so "Schmidt-Berger" and
		// "Schmidt Berger" collapse to the same tokens.
		$tokens = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		sort($tokens, SORT_STRING);

		return implode(' ', $tokens);
	}

	public static function matches(string $a, string $b): bool {
		$normalizedA = self::normalize($a);
		if ($normalizedA === '') {
			return false;
		}
		return $normalizedA === self::normalize($b);
	}

	/**
	 * Decompose and drop combining marks, so "José" becomes "Jose".
	 *
	 * intl ships with every supported Nextcloud install, but guard anyway: a
	 * missing extension should degrade to plain comparison rather than fatal.
	 */
	private static function stripDiacritics(string $value): string {
		if (!class_exists(\Normalizer::class)) {
			return $value;
		}

		$decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
		if ($decomposed === false) {
			return $value;
		}

		return preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $value;
	}
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/phpunit --filter NameMatcherTest`
Expected: `OK (11 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add lib/Service/Binding/NameMatcher.php
git commit -m "Add strict name comparison for identity matching

Normalises case, diacritics and punctuation, and sorts tokens so word
order does not matter -- iTop reports first and last name separately
while Nextcloud stores one display name.

Deliberately strict: 'Alex Askin' does not match 'Alexander Askin'. Near
matches go to the review tier for a human to judge rather than being
bound automatically."
```

---

## Task 4: Match result value object

**Files:**
- Create: `lib/Service/Binding/MatchResult.php`
- Modify: `composer.json:12` — PHP constraint
- Test: `tests/Unit/Service/Binding/MatchResultTest.php`

> This task introduces `readonly` properties, which need PHP 8.1. `composer.json`
> currently declares `"php": "^8.0"`, which is already wrong for `min-version` 32
> and would mis-declare the floor. Step 0 corrects it. In practice there is no
> runtime risk: Nextcloud 35 refuses to boot below PHP 8.3
> (`lib/versioncheck.php:9`, `PHP_VERSION_ID < 80300`).

**Interfaces:**
- Consumes: nothing from earlier tasks
- Produces:
  - `MatchResult::TIER_EXACT = 'exact'`, `TIER_REVIEW = 'review'`, `TIER_AMBIGUOUS = 'ambiguous'`, `TIER_NONE = 'none'`
  - `MatchResult::exact(string $personId, ?string $userId, string $itopName, string $sourceValue): self`
  - `MatchResult::review(string $personId, ?string $userId, string $itopName, string $sourceValue, string $ncName): self`
  - `MatchResult::ambiguous(array $candidates, string $sourceValue): self` — each candidate `['person_id' => string, 'user_id' => ?string, 'name' => string]`
  - `MatchResult::none(string $sourceValue): self`
  - Readonly properties: `tier`, `personId`, `userId`, `itopName`, `ncName`, `sourceValue`, `candidates`
  - `public function isBindable(): bool` — true only for `TIER_EXACT`

- [ ] **Step 0: Correct the PHP constraint**

In `composer.json`, change line 12 from:

```json
		"php": "^8.0"
```

to:

```json
		"php": "^8.1"
```

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Binding/MatchResultTest.php`:

```php
<?php

namespace OCA\Itop\Tests\Unit\Service\Binding;

use OCA\Itop\Service\Binding\MatchResult;
use PHPUnit\Framework\TestCase;

class MatchResultTest extends TestCase {

	public function testExactIsBindable(): void {
		$r = MatchResult::exact('16', '2', 'Boris Vian', 'boris.vian@it.com');
		$this->assertSame(MatchResult::TIER_EXACT, $r->tier);
		$this->assertTrue($r->isBindable());
		$this->assertSame('16', $r->personId);
		$this->assertSame('2', $r->userId);
	}

	public function testReviewIsNotBindable(): void {
		$r = MatchResult::review('41', null, 'Anneliese Schmidt-Berger', 'a@corp.at', 'Anna Schmidt');
		$this->assertSame(MatchResult::TIER_REVIEW, $r->tier);
		$this->assertFalse($r->isBindable(), 'a name mismatch must never bind automatically');
		$this->assertSame('Anna Schmidt', $r->ncName);
	}

	public function testAmbiguousIsNotBindableAndKeepsCandidates(): void {
		$r = MatchResult::ambiguous([
			['person_id' => '52', 'user_id' => null, 'name' => 'John Doe'],
			['person_id' => '53', 'user_id' => null, 'name' => 'Jane Doe'],
		], 'shared@corp.at');
		$this->assertSame(MatchResult::TIER_AMBIGUOUS, $r->tier);
		$this->assertFalse($r->isBindable());
		$this->assertCount(2, $r->candidates);
		$this->assertNull($r->personId, 'ambiguous must not pick a winner');
	}

	public function testNoneIsNotBindable(): void {
		$r = MatchResult::none('svc@corp.at');
		$this->assertSame(MatchResult::TIER_NONE, $r->tier);
		$this->assertFalse($r->isBindable());
		$this->assertNull($r->personId);
	}

	public function testUserIdMayBeAbsentForAPersonWithoutAUser(): void {
		$r = MatchResult::exact('12', null, 'Anna Schmidt', 'anna@corp.at');
		$this->assertTrue($r->isBindable(), 'a Person with no User is still bindable');
		$this->assertNull($r->userId);
	}
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --filter MatchResultTest`
Expected: FAIL — `Class "OCA\Itop\Service\Binding\MatchResult" not found`

- [ ] **Step 3: Implement**

Create `lib/Service/Binding/MatchResult.php`:

```php
<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

/**
 * Outcome of matching one Nextcloud user against iTop.
 *
 * Only TIER_EXACT is bindable without a human decision. TIER_REVIEW means the
 * attribute matched but the names disagree; TIER_AMBIGUOUS means several
 * people matched and the app refuses to guess.
 */
class MatchResult {

	public const TIER_EXACT = 'exact';
	public const TIER_REVIEW = 'review';
	public const TIER_AMBIGUOUS = 'ambiguous';
	public const TIER_NONE = 'none';

	/**
	 * @param array $candidates list of ['person_id' => string, 'user_id' => ?string, 'name' => string]
	 */
	private function __construct(
		public readonly string $tier,
		public readonly ?string $personId,
		public readonly ?string $userId,
		public readonly string $itopName,
		public readonly string $ncName,
		public readonly string $sourceValue,
		public readonly array $candidates,
	) {
	}

	public static function exact(string $personId, ?string $userId, string $itopName, string $sourceValue): self {
		return new self(self::TIER_EXACT, $personId, $userId, $itopName, '', $sourceValue, []);
	}

	public static function review(string $personId, ?string $userId, string $itopName, string $sourceValue, string $ncName): self {
		return new self(self::TIER_REVIEW, $personId, $userId, $itopName, $ncName, $sourceValue, []);
	}

	public static function ambiguous(array $candidates, string $sourceValue): self {
		return new self(self::TIER_AMBIGUOUS, null, null, '', '', $sourceValue, $candidates);
	}

	public static function none(string $sourceValue): self {
		return new self(self::TIER_NONE, null, null, '', '', $sourceValue, []);
	}

	/**
	 * Whether this result may be applied without a human confirming it.
	 */
	public function isBindable(): bool {
		return $this->tier === self::TIER_EXACT;
	}
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/phpunit --filter MatchResultTest`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add lib/Service/Binding/MatchResult.php composer.json
git commit -m "Add match result value object

Four tiers, of which only 'exact' is bindable without a human decision.
'ambiguous' deliberately carries no personId so no code path can treat a
multi-candidate match as a winner."
```

---

## Task 5: IdentityResolver

**Files:**
- Create: `lib/Service/Binding/IdentityResolver.php`
- Test: `tests/Unit/Service/Binding/IdentityResolverTest.php`

**Interfaces:**
- Consumes: `BindingConfig` (Task 2), `NameMatcher` (Task 3), `MatchResult` (Task 4), `ItopAPIService::appRequest()` and `escapeOQLString()` (Task 1)
- Produces:
  - `public function resolve(IUser $user): MatchResult`
  - `public function sourceValueFor(IUser $user): string` — the Nextcloud-side value for the configured source attribute; `''` when the account has no such value

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Binding/IdentityResolverTest.php`:

```php
<?php

namespace OCA\Itop\Tests\Unit\Service\Binding;

use OCA\Itop\Service\Binding\BindingConfig;
use OCA\Itop\Service\Binding\IdentityResolver;
use OCA\Itop\Service\Binding\MatchResult;
use OCA\Itop\Service\ItopAPIService;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class IdentityResolverTest extends TestCase {

	private function user(string $uid, ?string $email, string $display): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getSystemEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn($display);
		return $user;
	}

	private function resolver(array $itopResponse, array $config = [], ?array &$captured = null): IdentityResolver {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $config[$key] ?? $default
		);

		$api = $this->createMock(ItopAPIService::class);
		$api->method('escapeOQLString')->willReturnCallback(
			static fn (string $v) => str_replace("'", "\\'", $v)
		);
		$api->method('appRequest')->willReturnCallback(
			function (array $params) use (&$itopResponse, &$captured) {
				$captured[] = $params;
				return array_shift($itopResponse) ?? ['code' => 0, 'objects' => []];
			}
		);

		return new IdentityResolver(new BindingConfig($appConfig), $api, new NullLogger());
	}

	public function testExactWhenEmailAndNameAgree(): void {
		$r = $this->resolver([
			['code' => 0, 'objects' => ['Person::16' => ['key' => '16', 'fields' => [
				'id' => '16', 'first_name' => 'Boris', 'name' => 'Vian', 'email' => 'boris.vian@it.com',
			]]]],
			['code' => 0, 'objects' => ['User::2' => ['key' => '2', 'fields' => ['id' => '2']]]],
		])->resolve($this->user('b.vian', 'boris.vian@it.com', 'Boris Vian'));

		$this->assertSame(MatchResult::TIER_EXACT, $r->tier);
		$this->assertSame('16', $r->personId);
		$this->assertSame('2', $r->userId);
	}

	public function testReviewWhenNamesDisagree(): void {
		$r = $this->resolver([
			['code' => 0, 'objects' => ['Person::41' => ['key' => '41', 'fields' => [
				'id' => '41', 'first_name' => 'Anneliese', 'name' => 'Schmidt-Berger', 'email' => 'a@corp.at',
			]]]],
			['code' => 0, 'objects' => []],
		])->resolve($this->user('a.schmidt', 'a@corp.at', 'Anna Schmidt'));

		$this->assertSame(MatchResult::TIER_REVIEW, $r->tier);
		$this->assertSame('Anna Schmidt', $r->ncName);
		$this->assertFalse($r->isBindable());
	}

	public function testAmbiguousWhenSeveralPeopleMatch(): void {
		$r = $this->resolver([
			['code' => 0, 'objects' => [
				'Person::52' => ['key' => '52', 'fields' => ['id' => '52', 'first_name' => 'John', 'name' => 'Doe']],
				'Person::53' => ['key' => '53', 'fields' => ['id' => '53', 'first_name' => 'Jane', 'name' => 'Doe']],
			]],
		])->resolve($this->user('j.doe', 'shared@corp.at', 'John Doe'));

		$this->assertSame(MatchResult::TIER_AMBIGUOUS, $r->tier);
		$this->assertCount(2, $r->candidates);
	}

	public function testNoneWhenNothingMatches(): void {
		$r = $this->resolver([['code' => 0, 'objects' => []]])
			->resolve($this->user('svc.monitor', 'svc@corp.at', 'Monitoring Bot'));
		$this->assertSame(MatchResult::TIER_NONE, $r->tier);
	}

	public function testNoneWhenTheAccountHasNoSourceValue(): void {
		$captured = [];
		$r = $this->resolver([], [], $captured)
			->resolve($this->user('no.email', null, 'No Email'));

		$this->assertSame(MatchResult::TIER_NONE, $r->tier);
		$this->assertSame([], $captured, 'must not query iTop with an empty value');
	}

	public function testApiErrorYieldsNoneRatherThanAFalseMatch(): void {
		$r = $this->resolver([['error' => 'Connection refused']])
			->resolve($this->user('b.vian', 'boris.vian@it.com', 'Boris Vian'));
		$this->assertSame(MatchResult::TIER_NONE, $r->tier);
	}

	public function testOqlEscapesTheValueAndUsesTheAllowlistedField(): void {
		$captured = [];
		$this->resolver([['code' => 0, 'objects' => []]], [], $captured)
			->resolve($this->user("o.brien", "o'brien@corp.at", "O'Brien"));

		$this->assertStringContainsString("email = 'o\\'brien@corp.at'", $captured[0]['key']);
		$this->assertSame('Person', $captured[0]['class']);
	}

	public function testUserLoginTargetQueriesTheUserClassDirectly(): void {
		$captured = [];
		$this->resolver([
			['code' => 0, 'objects' => ['User::2' => ['key' => '2', 'fields' => [
				'id' => '2', 'contactid' => '16', 'contactid_friendlyname' => 'Boris Vian',
			]]]],
		], [
			'binding_match_source' => BindingConfig::SOURCE_UID,
			'binding_match_target' => BindingConfig::TARGET_USER_LOGIN,
		], $captured)->resolve($this->user('boris', 'x@y.z', 'Boris Vian'));

		$this->assertSame('User', $captured[0]['class']);
		$this->assertStringContainsString("login = 'boris'", $captured[0]['key']);
		$this->assertCount(1, $captured, 'User.login yields both ids in one query');
	}
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --filter IdentityResolverTest`
Expected: FAIL — `Class "OCA\Itop\Service\Binding\IdentityResolver" not found`

- [ ] **Step 3: Implement**

Create `lib/Service/Binding/IdentityResolver.php`:

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
use OCA\Itop\Service\ItopAPIService;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Finds the iTop identity that corresponds to a Nextcloud account.
 *
 * The single matcher behind admin-side binding, the occ command and (later)
 * self-service discovery, so the tier rules and OQL construction cannot drift
 * between those paths.
 *
 * The lookup value is always read from the Nextcloud account here. It is never
 * accepted from a request, which is what stops a user forging an identity or
 * probing iTop for which addresses exist.
 */
class IdentityResolver {

	public function __construct(
		private BindingConfig $bindingConfig,
		private ItopAPIService $itopAPIService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The Nextcloud-side value for the configured source attribute.
	 *
	 * Uses the system email rather than the primary email: the system address
	 * is the one an administrator or the user backend sets.
	 */
	public function sourceValueFor(IUser $user): string {
		return trim(match ($this->bindingConfig->getMatchSource()) {
			BindingConfig::SOURCE_UID => $user->getUID(),
			BindingConfig::SOURCE_DISPLAYNAME => $user->getDisplayName(),
			default => (string)$user->getSystemEMailAddress(),
		});
	}

	public function resolve(IUser $user): MatchResult {
		$value = $this->sourceValueFor($user);
		if ($value === '') {
			// Nothing to match on. Never query with an empty value: in OQL that
			// would match every record with an empty field.
			return MatchResult::none('');
		}

		$target = $this->bindingConfig->resolveTarget($this->bindingConfig->getMatchTarget());
		$escaped = $this->itopAPIService->escapeOQLString($value);

		$isUserTarget = $target['class'] === 'User';
		$outputFields = $isUserTarget
			? 'id,login,contactid,contactid_friendlyname'
			: 'id,first_name,name,email';

		$response = $this->itopAPIService->appRequest([
			'operation' => 'core/get',
			'class' => $target['class'],
			'key' => sprintf("SELECT %s WHERE %s = '%s'", $target['class'], $target['field'], $escaped),
			'output_fields' => $outputFields,
		]);

		if (isset($response['error'])) {
			$this->logger->warning('Identity resolution failed for {uid}: {error}', [
				'app' => Application::APP_ID,
				'uid' => $user->getUID(),
				'error' => $response['error'],
			]);
			return MatchResult::none($value);
		}

		$objects = $response['objects'] ?? [];
		if (!is_array($objects) || $objects === []) {
			return MatchResult::none($value);
		}

		$candidates = array_values(array_map(
			fn (array $object) => $this->toCandidate($object, $isUserTarget),
			$objects
		));

		if (count($candidates) > 1) {
			// Never pick a winner. A human decides.
			return MatchResult::ambiguous($candidates, $value);
		}

		$candidate = $candidates[0];
		if ($candidate['person_id'] === '') {
			return MatchResult::none($value);
		}

		$userId = $isUserTarget
			? $candidate['user_id']
			: $this->findUserIdForPerson($candidate['person_id']);

		if (!NameMatcher::matches($user->getDisplayName(), $candidate['name'])) {
			return MatchResult::review(
				$candidate['person_id'], $userId, $candidate['name'], $value, $user->getDisplayName()
			);
		}

		return MatchResult::exact($candidate['person_id'], $userId, $candidate['name'], $value);
	}

	/**
	 * @return array{person_id: string, user_id: ?string, name: string}
	 */
	private function toCandidate(array $object, bool $isUserTarget): array {
		$fields = $object['fields'] ?? [];

		if ($isUserTarget) {
			return [
				'person_id' => (string)($fields['contactid'] ?? ''),
				'user_id' => isset($fields['id']) ? (string)$fields['id'] : null,
				'name' => (string)($fields['contactid_friendlyname'] ?? ''),
			];
		}

		$name = trim(($fields['first_name'] ?? '') . ' ' . ($fields['name'] ?? ''));

		return [
			'person_id' => (string)($fields['id'] ?? $object['key'] ?? ''),
			'user_id' => null,
			'name' => $name,
		];
	}

	/**
	 * iTop profiles hang off the User, not the Person, so the User id is what
	 * later drives portal-only detection. A Person with no User is valid and
	 * yields null; ProfileService treats that as portal-only.
	 */
	private function findUserIdForPerson(string $personId): ?string {
		if (!is_numeric($personId)) {
			return null;
		}

		$response = $this->itopAPIService->appRequest([
			'operation' => 'core/get',
			'class' => 'User',
			'key' => 'SELECT User WHERE contactid = ' . (int)$personId,
			'output_fields' => 'id,login',
		]);

		if (isset($response['error']) || empty($response['objects'])) {
			return null;
		}

		$object = reset($response['objects']);
		$id = $object['fields']['id'] ?? null;

		return $id !== null ? (string)$id : null;
	}
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/phpunit --filter IdentityResolverTest`
Expected: `OK (8 tests, ...)`

- [ ] **Step 5: Run the whole suite to check nothing else broke**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: all green

- [ ] **Step 6: Commit**

```bash
git add lib/Service/Binding/IdentityResolver.php
git commit -m "Add iTop identity resolver

One matcher behind admin binding, the occ command and later self-service
discovery, so tier rules and OQL construction cannot drift apart.

The lookup value is read from the Nextcloud account, never from a
request: that is what stops a user forging an identity or probing iTop
for which addresses exist. An empty source value short-circuits before
any query, because 'field = \'\'' in OQL matches every empty record.

A multi-candidate result is returned as ambiguous with no personId, so
no caller can mistake it for a winner."
```

---

## Task 6: IdentityBindingService

**Files:**
- Create: `lib/Service/Binding/IdentityBindingService.php`
- Test: `tests/Unit/Service/Binding/IdentityBindingServiceTest.php`

**Interfaces:**
- Consumes: `BindingConfig` (Task 2), `MatchResult` (Task 4)
- Produces:
  - `IdentityBindingService::METHOD_ADMIN = 'admin'`, `METHOD_TOKEN = 'token'`, `METHOD_DISCOVERY = 'discovery'`
  - `public function isBound(string $uid): bool`
  - `public function getBinding(string $uid): ?array` — `['person_id' => string, 'user_id' => ?string, 'method' => string, 'bound_at' => int]`
  - `public function bind(string $uid, string $personId, ?string $userId, string $method, string $sourceAttribute = ''): void` — throws `\RuntimeException` if the user is excluded
  - `public function unbind(string $uid): void`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Binding/IdentityBindingServiceTest.php`:

```php
<?php

namespace OCA\Itop\Tests\Unit\Service\Binding;

use OCA\Itop\Service\Binding\BindingConfig;
use OCA\Itop\Service\Binding\IdentityBindingService;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class IdentityBindingServiceTest extends TestCase {

	private array $stored = [];

	private function service(array $appValues = []): IdentityBindingService {
		$this->stored = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $a, string $k, string $d = '') => $appValues[$k] ?? $d
		);

		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('setValueString')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value) {
				$this->stored[$uid][$key] = $value;
				return true;
			}
		);
		$userConfig->method('getValueString')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $d = '') => $this->stored[$uid][$key] ?? $d
		);
		$userConfig->method('deleteUserConfig')->willReturnCallback(
			function (string $uid, string $app, string $key) {
				unset($this->stored[$uid][$key]);
			}
		);

		return new IdentityBindingService(new BindingConfig($appConfig), $userConfig, new NullLogger());
	}

	public function testBindWritesAllFourKeys(): void {
		$s = $this->service();
		$s->bind('b.vian', '16', '2', IdentityBindingService::METHOD_ADMIN);

		$this->assertSame('16', $this->stored['b.vian']['person_id']);
		$this->assertSame('2', $this->stored['b.vian']['user_id']);
		$this->assertSame('admin', $this->stored['b.vian']['binding_method']);
		$this->assertNotEmpty($this->stored['b.vian']['binding_at']);
	}

	public function testBindWithoutAUserIdStillWritesPersonId(): void {
		$s = $this->service();
		$s->bind('a.schmidt', '12', null, IdentityBindingService::METHOD_ADMIN);

		$this->assertSame('12', $this->stored['a.schmidt']['person_id']);
		$this->assertArrayNotHasKey('user_id', $this->stored['a.schmidt']);
	}

	public function testBindClearsStalePortalCache(): void {
		$s = $this->service();
		$this->stored['b.vian']['is_portal_only'] = '1';
		$s->bind('b.vian', '16', '2', IdentityBindingService::METHOD_ADMIN);

		$this->assertArrayNotHasKey('is_portal_only', $this->stored['b.vian'],
			'a new identity must not inherit the previous one\'s portal verdict');
	}

	public function testBindRefusesAnExcludedAccount(): void {
		$s = $this->service(['binding_excluded_users' => '["svc.monitor"]']);
		$this->expectException(\RuntimeException::class);
		$s->bind('svc.monitor', '16', '2', IdentityBindingService::METHOD_ADMIN);
	}

	public function testIsBoundReflectsState(): void {
		$s = $this->service();
		$this->assertFalse($s->isBound('b.vian'));
		$s->bind('b.vian', '16', '2', IdentityBindingService::METHOD_ADMIN);
		$this->assertTrue($s->isBound('b.vian'));
	}

	public function testUnbindRemovesEverything(): void {
		$s = $this->service();
		$s->bind('b.vian', '16', '2', IdentityBindingService::METHOD_ADMIN);
		$s->unbind('b.vian');

		$this->assertFalse($s->isBound('b.vian'));
		$this->assertArrayNotHasKey('binding_method', $this->stored['b.vian']);
	}

	public function testGetBindingReturnsNullWhenUnbound(): void {
		$this->assertNull($this->service()->getBinding('nobody'));
	}
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --filter IdentityBindingServiceTest`
Expected: FAIL — `Class "OCA\Itop\Service\Binding\IdentityBindingService" not found`

- [ ] **Step 3: Implement**

Create `lib/Service/Binding/IdentityBindingService.php`:

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
use Psr\Log\LoggerInterface;

/**
 * Applies and removes the link between a Nextcloud account and an iTop person.
 *
 * person_id is the authorisation boundary for everything downstream, so every
 * write is logged with who was bound to whom and by which method.
 */
class IdentityBindingService {

	public const METHOD_ADMIN = 'admin';
	public const METHOD_TOKEN = 'token';
	public const METHOD_DISCOVERY = 'discovery';

	public function __construct(
		private BindingConfig $bindingConfig,
		private IUserConfig $userConfig,
		private LoggerInterface $logger,
	) {
	}

	public function isBound(string $uid): bool {
		return $this->userConfig->getValueString($uid, Application::APP_ID, 'person_id', '') !== '';
	}

	/**
	 * @return array{person_id: string, user_id: ?string, method: string, bound_at: int}|null
	 */
	public function getBinding(string $uid): ?array {
		$personId = $this->userConfig->getValueString($uid, Application::APP_ID, 'person_id', '');
		if ($personId === '') {
			return null;
		}

		$userId = $this->userConfig->getValueString($uid, Application::APP_ID, 'user_id', '');

		return [
			'person_id' => $personId,
			'user_id' => $userId !== '' ? $userId : null,
			'method' => $this->userConfig->getValueString($uid, Application::APP_ID, 'binding_method', ''),
			'bound_at' => (int)$this->userConfig->getValueString($uid, Application::APP_ID, 'binding_at', '0'),
		];
	}

	/**
	 * @throws \RuntimeException if the account is excluded from matching
	 */
	public function bind(string $uid, string $personId, ?string $userId, string $method, string $sourceAttribute = ''): void {
		if ($this->bindingConfig->isExcluded($uid)) {
			throw new \RuntimeException('User ' . $uid . ' is excluded from iTop identity binding');
		}

		// Drop derived state first. A crash mid-write must never leave a user_id
		// belonging to a different person than person_id: iTop profiles hang off
		// the User, so a mismatched pair can grant a profile that was never
		// authorised. ProfileService re-derives user_id from person_id when it
		// is absent, so clearing is always recoverable while a stale pair is
		// not. Portal status belongs to the previous identity too, so it is
		// dropped here rather than inherited by the new one.
		$this->clearProfileCache($uid);
		$this->userConfig->deleteUserConfig($uid, Application::APP_ID, 'user_id');

		$this->userConfig->setValueString($uid, Application::APP_ID, 'person_id', $personId);

		if ($userId !== null && $userId !== '') {
			$this->userConfig->setValueString($uid, Application::APP_ID, 'user_id', $userId);
		}

		$this->userConfig->setValueString($uid, Application::APP_ID, 'binding_method', $method);
		$this->userConfig->setValueString($uid, Application::APP_ID, 'binding_at', (string)time());

		$this->logger->info('iTop identity bound: {uid} -> person {personId} via {method}', [
			'app' => Application::APP_ID,
			'uid' => $uid,
			'personId' => $personId,
			'itopUserId' => $userId,
			'method' => $method,
			'sourceAttribute' => $sourceAttribute,
		]);
	}

	public function unbind(string $uid): void {
		foreach (['person_id', 'user_id', 'binding_method', 'binding_at'] as $key) {
			$this->userConfig->deleteUserConfig($uid, Application::APP_ID, $key);
		}

		$this->clearProfileCache($uid);

		$this->logger->info('iTop identity unbound: {uid}', [
			'app' => Application::APP_ID,
			'uid' => $uid,
		]);
	}

	private function clearProfileCache(string $uid): void {
		$this->userConfig->deleteUserConfig($uid, Application::APP_ID, 'is_portal_only');
		$this->userConfig->deleteUserConfig($uid, Application::APP_ID, 'profiles_last_check');
	}
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/phpunit --filter IdentityBindingServiceTest`
Expected: `OK (7 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add lib/Service/Binding/IdentityBindingService.php
git commit -m "Add identity binding service

person_id is the authorisation boundary for every downstream query, so
each write is logged with who was bound to whom and by which method.

Binding clears the cached portal verdict: that value describes the
previously bound person and must not be inherited by a new identity.
Excluded accounts are refused here rather than only in the UI, so every
caller gets the check."
```

---

## Task 7: `occ itop:bind` command

**Files:**
- Create: `lib/Command/BindUsers.php`
- Modify: `appinfo/info.xml` — add the command inside the existing `<commands>` block
- Test: manual, against the dev VM

**Interfaces:**
- Consumes: `IdentityResolver` (Task 5), `IdentityBindingService` (Task 6), `BindingConfig` (Task 2)
- Produces: `occ itop:bind [--apply] [--include-review] [--user UID] [--limit N]`

- [ ] **Step 1: Implement the command**

Create `lib/Command/BindUsers.php`:

```php
<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Command;

use OCA\Itop\Service\Binding\BindingConfig;
use OCA\Itop\Service\Binding\IdentityBindingService;
use OCA\Itop\Service\Binding\IdentityResolver;
use OCA\Itop\Service\Binding\MatchResult;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Binds Nextcloud users to their iTop identity from the command line.
 *
 * Dry-run by default: --apply is required before anything is written.
 */
class BindUsers extends Command {

	public function __construct(
		private IUserManager $userManager,
		private IdentityResolver $resolver,
		private IdentityBindingService $bindingService,
		private BindingConfig $bindingConfig,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('itop:bind')
			->setDescription('Bind Nextcloud users to their iTop identity')
			->addOption('apply', null, InputOption::VALUE_NONE,
				'Write the bindings. Without this the command only reports what it would do.')
			->addOption('include-review', null, InputOption::VALUE_NONE,
				'Also bind matches whose name differs from the iTop person. Requires --apply.')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED,
				'Only process this Nextcloud user id')
			->addOption('limit', null, InputOption::VALUE_REQUIRED,
				'Stop after examining this many users');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = (bool)$input->getOption('apply');
		$includeReview = (bool)$input->getOption('include-review');
		$limit = $input->getOption('limit') !== null ? (int)$input->getOption('limit') : null;

		if ($includeReview && !$apply) {
			$output->writeln('<error>--include-review only makes sense together with --apply</error>');
			return 1;
		}

		$output->writeln(sprintf(
			'Matching Nextcloud <info>%s</info> against iTop <info>%s</info>%s',
			$this->bindingConfig->getMatchSource(),
			$this->bindingConfig->getMatchTarget(),
			$apply ? '' : '  (dry run - nothing will be written)'
		));

		$only = $input->getOption('user');
		$users = $this->collectUsers($only);

		if ($only === null) {
			$output->writeln(sprintf('Examining %d Nextcloud account(s).', count($users)));
		}
		$output->writeln('');

		$counts = [MatchResult::TIER_EXACT => 0, MatchResult::TIER_REVIEW => 0,
			MatchResult::TIER_AMBIGUOUS => 0, MatchResult::TIER_NONE => 0];
		$bound = 0;
		$skipped = 0;
		$examined = 0;
		$failed = 0;
		$stoppedAtLimit = false;

		foreach ($users as $user) {
			if ($limit !== null && $examined >= $limit) {
				$stoppedAtLimit = true;
				break;
			}
			$examined++;

			$uid = $user->getUID();

			if ($this->bindingConfig->isExcluded($uid)) {
				$skipped++;
				continue;
			}
			if ($this->bindingService->isBound($uid)) {
				$skipped++;
				continue;
			}

			$result = $this->resolver->resolve($user);
			$counts[$result->tier]++;

			$shouldBind = $result->isBindable()
				|| ($includeReview && $result->tier === MatchResult::TIER_REVIEW);

			$output->writeln($this->describe($uid, $result));

			if ($apply && $shouldBind && $result->personId !== null) {
				try {
					$this->bindingService->bind(
						$uid, $result->personId, $result->userId,
						IdentityBindingService::METHOD_ADMIN,
						$this->bindingConfig->getMatchSource()
					);
					$bound++;
				} catch (\Throwable $e) {
					$failed++;
					$output->writeln(sprintf('  <error>failed</error>     %-24s %s', $uid, $e->getMessage()));
				}
			}
		}

		$output->writeln('');

		if ($stoppedAtLimit) {
			$remaining = count($users) - $examined;
			$output->writeln(sprintf(
				'<comment>stopped after --limit %d; %d account(s) were not examined</comment>',
				$limit, $remaining
			));
		}

		$output->writeln(sprintf(
			'examined %d · exact %d · review %d · ambiguous %d · no match %d · skipped %d · failed %d',
			$examined, $counts[MatchResult::TIER_EXACT], $counts[MatchResult::TIER_REVIEW],
			$counts[MatchResult::TIER_AMBIGUOUS], $counts[MatchResult::TIER_NONE], $skipped, $failed
		));

		if ($apply) {
			$output->writeln(sprintf('<info>bound %d user(s)</info>', $bound));
		} else {
			$output->writeln('<comment>dry run - re-run with --apply to write these bindings</comment>');
		}

		return $failed > 0 ? 1 : 0;
	}

	/**
	 * @return IUser[]
	 */
	private function collectUsers(?string $only): array {
		if ($only !== null) {
			$user = $this->userManager->get($only);
			return $user !== null ? [$user] : [];
		}

		$users = [];
		$this->userManager->callForAllUsers(static function (IUser $user) use (&$users) {
			$users[] = $user;
		});

		return $users;
	}

	private function describe(string $uid, MatchResult $result): string {
		return match ($result->tier) {
			MatchResult::TIER_EXACT => sprintf('  <info>exact</info>      %-24s -> %s (person %s)',
				$uid, $result->itopName, $result->personId),
			MatchResult::TIER_REVIEW => sprintf('  <comment>review</comment>     %-24s -> %s (person %s)  names differ: "%s" vs "%s"',
				$uid, $result->itopName, $result->personId, $result->ncName, $result->itopName),
			MatchResult::TIER_AMBIGUOUS => sprintf('  <error>ambiguous</error>  %-24s -> %d candidates for "%s"',
				$uid, count($result->candidates), $result->sourceValue),
			default => sprintf('  no match   %-24s (%s)',
				$uid, $result->sourceValue === '' ? 'no source value' : $result->sourceValue),
		};
	}
}
```

- [ ] **Step 2: Register the command**

In `appinfo/info.xml`, change the `<commands>` block from:

```xml
	<commands>
		<command>OCA\Itop\Command\NotificationsTestUser</command>
	</commands>
```

to:

```xml
	<commands>
		<command>OCA\Itop\Command\NotificationsTestUser</command>
		<command>OCA\Itop\Command\BindUsers</command>
	</commands>
```

- [ ] **Step 3: Verify it parses and registers**

```bash
php -l lib/Command/BindUsers.php
python3 -c "import xml.dom.minidom; xml.dom.minidom.parse('appinfo/info.xml'); print('info.xml OK')"
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ list 2>&1 | grep itop
```

Expected: no syntax errors, `info.xml OK`, and `itop:bind` listed.

- [ ] **Step 4: Dry run against the real instance**

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ itop:bind
```

Expected: a per-user line and a tier summary, ending with the dry-run notice. **Nothing written** — confirm with:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop person_id
```

Expected: `The setting does not exist for user "test1".`

- [ ] **Step 5: Apply for a single user and verify**

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ itop:bind --user test1 --apply
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop person_id
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop binding_method
```

Expected: `person_id` set to the matched iTop person, `binding_method` = `admin`.

> If `test1` has no email in Nextcloud it will report `no match (no source value)`. Give it the address of an existing iTop contact first:
> `occ user:setting test1 settings email anna@...` — use the address of the `anna` contact (person 12), then re-run.

- [ ] **Step 6: Confirm re-running skips the now-bound user**

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ itop:bind --user test1 --apply
```

Expected: summary reports `skipped 1`, and no second binding is written.

- [ ] **Step 7: Confirm `--include-review` is refused without `--apply`**

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ itop:bind --include-review
```

Expected: `--include-review only makes sense together with --apply`, exit code 1.

- [ ] **Step 8: Clean up the test binding**

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop person_id --delete
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop binding_method --delete
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop binding_at --delete
```

- [ ] **Step 9: Run the full unit suite once more**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: all green

- [ ] **Step 10: Commit**

```bash
git add lib/Command/BindUsers.php appinfo/info.xml
git commit -m "Add occ itop:bind for admin-side identity binding

Dry-run by default; --apply is required before anything is written, and
--include-review is refused without it so a name mismatch can never be
bound by a stray flag.

Binds only the exact tier unless review is explicitly requested, never
binds ambiguous or no-match, and skips excluded and already-bound
accounts. This is the only path portal-only users have, since iTop does
not issue personal tokens to them."
```

---

## Task 8: Changelog

**Files:**
- Modify: `CHANGELOG.md`, `README.md`

- [ ] **Step 1: Add the changelog entry**

In `CHANGELOG.md`, inside the existing `## [1.5.0] - 2026-10-24` section, add a new `### Added` block directly beneath the heading `### 🧹 Deprecated API Cleanup`:

```markdown
### Added
- **Admin-side identity binding (`occ itop:bind`)**: administrators can link Nextcloud accounts to their iTop person without those users touching iTop. This is the only route available to portal-only users, because iTop issues personal tokens to back-end users only. Matching is controlled by an admin-configured source attribute (Nextcloud email, user ID or display name) and target (`Person.email`, `Person.employee_number` or `User.login`), and results are graded: `exact` (attribute and name agree) is bindable, `review` (names differ) needs explicit confirmation, `ambiguous` (several candidates) is never guessed, `none` is reported. The command is dry-run by default and requires `--apply` to write; accounts can be excluded from matching entirely
```

- [ ] **Step 2: Add the README release note**

In `README.md`, under `## 📋 What's New in v1.5.0`, add above the existing `### ⚠️ Breaking` block:

```markdown
### Added
- **Admin-side identity binding**: link Nextcloud accounts to iTop people with `occ itop:bind`, without users needing an iTop personal token — the only route available to portal-only users. Dry-run by default; matches are graded exact / review / ambiguous so near-misses are never bound silently
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md README.md
git commit -m "Document admin-side identity binding in 1.5.0"
```

---

## Out of scope for this plan

Each gets its own plan, built on the services above:

- **Admin associations UI** (spec phase 2) — `BindingController` plus the paginated table with filters, bulk actions, exclusions and origin badges.
- **Self-service discovery** (spec phase 4) — the two user endpoints, the personal-settings button, the `binding_discovery_enabled` toggle, the mutability warning, and the fix for the misleading portal-user token error.
- **Admin settings UI for the matching rule** — until then `binding_match_source` / `binding_match_target` are set with `occ config:app:set`.

## iTop fixtures still needed

The dev instance can exercise `exact` and `none` today. Before the admin UI plan, create in `itop-dev`:

- two contacts sharing one email address → `ambiguous`
- a contact whose name differs from the matching Nextcloud account's display name → `review`
