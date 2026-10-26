# iTop Identity Binding — Phase 2b-2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an administrator find and apply iTop matches from the User associations screen, instead of only through `occ itop:bind`.

**Architecture:** Three new admin endpoints on the existing `BindingController`, driven by the existing `IdentityResolver` so tier rules cannot drift between the screen, the command and future self-service. The table gains a tier column that fills in only when the administrator asks. Before any of that, the carried-over findings in this code are fixed, because this phase is what makes them matter.

**Tech Stack:** PHP services and controller, vanilla JS in `src/admin-binding.js`, one existing Vue component. Nextcloud 32–35.

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. Do not use APIs newer than 32. The OCP stubs in `vendor/nextcloud/ocp/` are the authority.
- **No new npm dependencies.** Vue is permitted **only** in `src/views/BindingScopePicker.vue` and any new component this plan explicitly creates — nowhere else on the admin page.
- `declare(strict_types=1)` is **not** used anywhere in `lib/`. Do not introduce it.
- `Application::APP_ID` (`'integration_itop'`) — never hard-code the string in PHP.
- **Tests are local-only and never committed.** `tests/`, `phpunit.xml` and `docs/testing.md` are gitignored. Write and run them; commit only source.
- The `js/` directory is gitignored, but its built `.mjs` bundles **are tracked**. After `npm run build`: `find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f`.
- `no-unused-vars` is an eslint **error**. Expect 0 errors (14 pre-existing warnings are fine).
- Every user-facing string goes through `$l->t()` in PHP or `t('integration_itop', …)` in JS.
- The PHP suite is `./vendor/bin/phpunit`, currently **128 tests / 262 assertions green**. It must stay green.

### Security properties that must hold — these are the point of the phase

1. **`apply` never trusts a client-supplied `personId`.** It re-runs `IdentityResolver::resolve()` for each uid and binds only what *that* returns as `exact`. Accepting an id from the request would bypass the entire tier system.
2. **`apply-one` may take a `personId`,** because an administrator choosing among candidates is a deliberate, trusted decision — but it must still go through `IdentityBindingService::bind()`, which refuses a person already linked to another account.
3. **A bulk action never applies a `review`, `ambiguous` or `conflict` row.** "Apply exact only" is the literal behaviour, not just the label.
4. **Excluded accounts are never resolved or bound** by any of these endpoints.
5. Every new endpoint is admin-only: `BindingController` carries no `NoAdminRequired` and must not gain one.

### When matching runs

Resolving one account costs **one iTop API call**. Matching therefore runs only when the administrator asks: they select rows, or filter to Unbound, and press *Suggest matches*. Nothing resolves on page load, and paging stays free. Suggestions are **not cached** — a cached tier is a claim about iTop that goes stale the moment the rule or an iTop record changes.

There is consequently **no "Needs review" server-side filter**: a tier is computed, not stored, so filtering on it would mean resolving every account on the instance. The client groups a Suggest run's results by tier instead.

### How to verify UI behaviour — read this before testing

Synthetic DOM events do **not** reliably reach listeners registered by the page's own bundle in this project. Trust the network request actually issued and the state read back with `occ`; trust real interaction. Do not conclude a handler is broken because a scripted `.click()` produced nothing — if you cannot verify a click path, say so plainly rather than asserting either outcome.

### Dev environment notes

- The dev VM symlinks this worktree; `npm run build` output is live at `https://nextcloud-dev.orb.local`.
- Nextcloud's CSS and JS URLs do not change when only contents change, so the browser can run a **stale** bundle or stylesheet. Hard-reload (`Cmd+Shift+R`) before concluding anything is wrong. A stylesheet can need `fetch(href, {cache:'reload'})` to shift.
- PHP opcache serves stale copies of files that change size, which has produced several bogus "syntax error" 500s here. If `php -l` disagrees with the server, restart `php8.3-fpm`.
- **Current dev data and expected match results** (confirmed by `occ itop:bind` dry run):

```
exact      pablo   -> Pablo Picasso (person 2)
review     test1   -> Anna Gavalda (person 12)   names differ: "Test Account 1" vs "Anna Gavalda"
no match   test2   (no source value)
skipped    nextadmin  (already bound, person 16, method token)
```

This is the fixture every task verifies against. `pablo` is the only `exact`, so it is the only account a bulk apply may touch.

---

## Existing interfaces you build on

```php
MatchResult                       // readonly value object
  ::TIER_EXACT|TIER_REVIEW|TIER_AMBIGUOUS|TIER_CONFLICT|TIER_NONE
  public readonly string  $tier, $itopName, $ncName, $sourceValue, $conflictUid
  public readonly ?string $personId, $userId
  public readonly array   $candidates      // [{person_id, user_id, name}, …]
  public function isBindable(): bool       // TIER_EXACT only

IdentityResolver
  public function resolve(IUser $user): MatchResult
  public function sourceValueFor(IUser $user): string

IdentityBindingService
  public function bind(string $uid, string $personId, ?string $userId, string $method, string $sourceAttribute = ''): void
  public function findConflictingUid(string $personId, string $exceptUid = ''): ?string
  public const METHOD_ADMIN   // the method value admin-side binds record

BindingConfig
  public function getExcludedUsers(): array
  public function isExcluded(string $uid): bool
  public function setExcluded(string $uid, bool $excluded): void
  public function resolveTarget(string $target): array
```

Row shape currently returned by `BindingListService::describe()`:

```json
{ "uid": "pablo", "displayName": "Pablo Picasso", "email": "pablo.picasso@demo.com",
  "bound": false, "personId": null, "userId": null, "method": null,
  "boundAt": null, "excluded": false }
```

---

## Task 1: Carried-over fixes in the code this phase touches

These were recorded as deferred during phase 2b-1 with the note that they belong here. They land first so later tasks build on the corrected shapes.

**Files:**
- Modify: `lib/Service/Binding/BindingListService.php`
- Modify: `lib/Service/Binding/BindingConfig.php`
- Modify: `lib/Service/Binding/IdentityBindingService.php`
- Modify: `lib/Controller/BindingController.php`
- Test: `tests/Unit/Service/Binding/BindingListServiceTest.php`, `tests/Unit/Service/Binding/BindingConfigTest.php` *(local only)*

**Interfaces:**
- Produces: `BindingConfig::setExcludedMany(array $uids, bool $excluded): array`; `sourceValue` on every row; `describe()` and `getBinding()` returning the same key shape.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Service/Binding/BindingConfigTest.php`:

```php
	public function testSetExcludedManyWritesOnce(): void {
		$config = $this->makeConfig(['binding_excluded_users' => '[]']);

		$changed = $config->setExcludedMany(['a', 'b', 'c'], true);

		$this->assertSame(['a', 'b', 'c'], $changed);
		$this->assertSame(['a', 'b', 'c'], $config->getExcludedUsers());
		// One read-modify-write for the batch, not one per uid: two concurrent
		// administrators excluding different accounts must not lose an edit.
		$this->assertSame(1, $this->writeCount);
	}

	public function testSetExcludedManyIsIdempotent(): void {
		$config = $this->makeConfig(['binding_excluded_users' => '["a"]']);

		$changed = $config->setExcludedMany(['a'], true);

		$this->assertSame([], $changed, 'an already-excluded uid is not reported as changed');
		$this->assertSame(['a'], $config->getExcludedUsers());
	}
```

`makeConfig()` and `$writeCount` are helpers you add: a fake `IAppConfig` recording how many `setValueString` calls it receives. Follow the existing test file's construction style.

Add to `tests/Unit/Service/Binding/BindingListServiceTest.php`:

```php
	public function testRowCarriesTheSourceValueBeingMatched(): void {
		$rows = $this->listUsers([
			['uid' => 'pablo', 'displayName' => 'Pablo Picasso', 'email' => 'pablo.picasso@demo.com'],
		]);

		// Without this the tier column cannot show what is actually being
		// compared when the rule matches on uid or display name.
		$this->assertSame('pablo.picasso@demo.com', $rows[0]['sourceValue']);
	}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/`
Expected: FAIL — `setExcludedMany` undefined, and `sourceValue` missing from the row.

- [ ] **Step 3: Batch the exclusion write**

In `lib/Service/Binding/BindingConfig.php`, add beside `setExcluded()`:

```php
	/**
	 * Exclude or restore several accounts in one read-modify-write.
	 *
	 * setExcluded() per uid is 2N config operations for a bulk action, and two
	 * administrators acting at once lose one another's edit. The bulk bar on
	 * the associations screen makes that reachable.
	 *
	 * @param array $uids accounts to change
	 * @param bool $excluded true to exclude, false to restore
	 * @return array the uids actually changed
	 */
	public function setExcludedMany(array $uids, bool $excluded): array {
		$current = $this->getExcludedUsers();
		$set = array_fill_keys($current, true);
		$changed = [];

		foreach ($uids as $uid) {
			$uid = (string)$uid;
			if ($uid === '') {
				continue;
			}
			if ($excluded && !isset($set[$uid])) {
				$set[$uid] = true;
				$changed[] = $uid;
			} elseif (!$excluded && isset($set[$uid])) {
				unset($set[$uid]);
				$changed[] = $uid;
			}
		}

		if ($changed !== []) {
			$this->writeExcludedUsers(array_keys($set));
		}

		return $changed;
	}
```

Extract whatever `setExcluded()` currently does to persist the list into a private `writeExcludedUsers(array $uids): void`, and have **both** methods call it, so the lazy flag and JSON encoding exist in exactly one place. Then make `setExcluded()` delegate: `$this->setExcludedMany([$uid], $excluded);`.

- [ ] **Step 4: Make the list cheap and add `sourceValue`**

In `lib/Service/Binding/BindingListService.php`:

Inject `IdentityResolver` and use `sourceValueFor()` to populate a new `sourceValue` key on each row.

Replace the four per-user `getValueString` calls in `describe()` with four `IUserConfig::getValuesByUsers(Application::APP_ID, $key)` calls made **once** in `list()`, passed into `describe()`. `getValuesByUsers(string $app, string $key, ?ValueType $typedAs = null, ?array $userIds = null): array` is confirmed present in the NC 32 stub at `vendor/nextcloud/ocp/OCP/Config/IUserConfig.php:224`.

The keys are `person_id`, `user_id`, `binding_method`, `binding_at`. On a 20k-account instance this turns ~80k queries into 4.

Keep the row's public shape otherwise identical — the frontend and the existing tests depend on it.

- [ ] **Step 5: Collapse the two binding shapes**

`IdentityBindingService::getBinding()` returns `person_id` / `user_id` / `method` / `bound_at`, while `describe()` returns `personId` / `userId` / `method` / `boundAt`. Two shapes for one concept is a trap for the next consumer.

`getBinding()` currently has **no callers**. Either delete it, or change it to return the `describe()` shape and use it — your judgement, but say which you chose and why in your report. Do not leave both shapes in the tree.

- [ ] **Step 6: Use the batch writer in the controller**

In `lib/Controller/BindingController.php`, change `exclude()` and `restore()` to call `setExcludedMany()` once instead of looping `setExcluded()`. Keep their response shape (`{"changed": [...], "excluded_users": [...]}`) exactly as it is — `src/admin-binding.js` reads it.

- [ ] **Step 7: Run the tests**

```bash
./vendor/bin/phpunit
```

Expected: green, with the new tests passing and no existing test broken. Report the count.

- [ ] **Step 8: Commit**

```bash
git add lib/Service/Binding/BindingConfig.php lib/Service/Binding/BindingListService.php lib/Service/Binding/IdentityBindingService.php lib/Controller/BindingController.php
git commit -m "Batch the exclusion write and the binding-state reads

A bulk exclude was one read-modify-write per account, so N accounts cost
2N config operations and two administrators acting at once lost one
another's edit. The bulk bar made that reachable.

Listing accounts read four user-config keys per account; on a large
instance one page view was tens of thousands of queries to then throw
all but a page away. getValuesByUsers does it in four.

Rows now carry the source value being matched, which the tier column
needs to show what is actually being compared, and the two accessors
that returned the same binding under different key names are now one."
```

---

## Task 2: The suggest endpoint

**Files:**
- Create: `lib/Service/Binding/SuggestionService.php`
- Modify: `lib/Controller/BindingController.php`
- Modify: `appinfo/routes.php`
- Test: `tests/Unit/Service/Binding/SuggestionServiceTest.php` *(local only)*

**Interfaces:**
- Produces: `POST /binding/suggest` and `SuggestionService::suggest(array $uids): array`.

**Why a service:** the controller must not grow matching logic, and Task 3's apply path needs the same "resolve a batch, skipping what must not be resolved" behaviour.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Binding/SuggestionServiceTest.php` covering, at minimum:

- an excluded uid is **not** resolved and comes back with `status: 'excluded'` — assert the resolver was never called for it
- an unknown uid comes back `status: 'unknown'`, resolver not called
- a resolvable uid returns its tier and the match fields
- a resolver exception for one uid does not abort the batch: that uid comes back `status: 'failed'` and the others still return

Follow the construction style of `tests/Unit/Service/Binding/BindingScopeServiceTest.php`. Mock `IdentityResolver`, `IUserManager` and `BindingConfig`.

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/SuggestionServiceTest.php`
Expected: FAIL with `Class "OCA\Itop\Service\Binding\SuggestionService" does not exist`.

- [ ] **Step 3: Write the service**

Create `lib/Service/Binding/SuggestionService.php`:

```php
<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a batch of accounts against iTop for the administration screen.
 *
 * Each resolve costs one iTop API call, so this only ever runs when an
 * administrator asks for it, and never on page load. The batch is capped for
 * the same reason.
 *
 * One failing account is reported and skipped rather than aborting the batch,
 * matching how occ itop:bind behaves: an administrator working through a list
 * should not lose the whole run to one bad record.
 */
class SuggestionService {

	/**
	 * One iTop request per account, so a single click is bounded. The screen
	 * suggests for a selection or a page, both well under this.
	 */
	public const MAX_BATCH = 50;

	public function __construct(
		private IdentityResolver $resolver,
		private BindingConfig $bindingConfig,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array $uids accounts to resolve
	 * @return array uid => result, each with a status and, when resolved, tier fields
	 */
	public function suggest(array $uids): array {
		$excluded = array_fill_keys($this->bindingConfig->getExcludedUsers(), true);
		$results = [];

		foreach (array_slice(array_values(array_unique($uids)), 0, self::MAX_BATCH) as $uid) {
			$uid = (string)$uid;

			// Excluded accounts are deliberately out of scope for matching, so
			// resolving them would both waste an iTop call and offer an action
			// that must never be taken.
			if (isset($excluded[$uid])) {
				$results[$uid] = ['status' => 'excluded'];
				continue;
			}

			$user = $this->userManager->get($uid);
			if ($user === null) {
				$results[$uid] = ['status' => 'unknown'];
				continue;
			}

			try {
				$match = $this->resolver->resolve($user);
				$results[$uid] = [
					'status' => 'resolved',
					'tier' => $match->tier,
					'personId' => $match->personId,
					'userId' => $match->userId,
					'itopName' => $match->itopName,
					'ncName' => $match->ncName,
					'sourceValue' => $match->sourceValue,
					'candidates' => $match->candidates,
					'conflictUid' => $match->conflictUid,
				];
			} catch (Throwable $e) {
				$this->logger->warning('iTop: could not resolve {uid}', [
					'app' => \OCA\Itop\AppInfo\Application::APP_ID,
					'uid' => $uid,
					'exception' => $e,
				]);
				$results[$uid] = ['status' => 'failed', 'message' => $e->getMessage()];
			}
		}

		return $results;
	}
}
```

Import `Application` properly at the top rather than using the inline FQN above — the inline form is shown only to make the constant unambiguous here.

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/SuggestionServiceTest.php`
Expected: PASS, output pristine.

- [ ] **Step 5: Expose it**

In `lib/Controller/BindingController.php`, inject `SuggestionService` and add:

```php
	public function suggest(array $uids = []): DataResponse {
		return new DataResponse([
			'results' => $this->suggestionService->suggest($uids),
			'maxBatch' => SuggestionService::MAX_BATCH,
		]);
	}
```

In `appinfo/routes.php`, after the `binding#searchScopes` line:

```php
		['name' => 'binding#suggest', 'url' => '/binding/suggest', 'verb' => 'POST'],
```

- [ ] **Step 6: Verify against ground truth**

```bash
./vendor/bin/phpunit
php -l lib/Service/Binding/SuggestionService.php
php -l lib/Controller/BindingController.php
```

Then, from the browser devtools console on the admin settings page (the session is already authenticated):

```js
await (await fetch(OC.generateUrl('/apps/integration_itop/binding/suggest'), {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
  body: JSON.stringify({ uids: ['pablo', 'test1', 'test2', 'nextadmin', 'no.such.user'] })
})).json()
```

Expected, against the dev fixture: `pablo` → `tier: "exact"`, person 2, `itopName: "Pablo Picasso"`; `test1` → `tier: "review"` with `ncName: "Test Account 1"` and `itopName: "Anna Gavalda"`; `test2` → `tier: "none"`; `nextadmin` → resolved (it is bound, not excluded — binding does not stop resolution, only exclusion does); `no.such.user` → `status: "unknown"`.

Then exclude one account and confirm it is **not** resolved:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:set integration_itop binding_excluded_users --value='["pablo"]'
```

Re-run the same call: `pablo` must come back `status: "excluded"` with no tier. Then restore:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:delete integration_itop binding_excluded_users
```

Report the actual JSON for each call.

- [ ] **Step 7: Commit**

```bash
git add lib/Service/Binding/SuggestionService.php lib/Controller/BindingController.php appinfo/routes.php
git commit -m "Add the match suggestion endpoint

Resolving one account costs one iTop request, so suggestions run only
when an administrator asks and the batch is capped. Nothing resolves on
page load.

Excluded accounts are never resolved: it would waste a request and offer
an action that must never be taken. One failing account is reported and
skipped rather than aborting the batch, as occ itop:bind already does."
```

---

## Task 3: Apply, apply-one, and the person search

**Files:**
- Modify: `lib/Service/Binding/SuggestionService.php`
- Modify: `lib/Controller/BindingController.php`
- Modify: `lib/Service/Binding/IdentityResolver.php`
- Modify: `appinfo/routes.php`
- Test: `tests/Unit/Service/Binding/SuggestionServiceTest.php`, `tests/Unit/Controller/BindingControllerTest.php` *(local only)*

**Interfaces:**
- Produces: `POST /binding/apply`, `POST /binding/apply-one`, `GET /binding/search-person`.

- [ ] **Step 1: Write the failing tests**

The security properties are what these tests exist to pin down. Cover at least:

```php
	public function testApplyBindsOnlyExactRows(): void {
		// review, ambiguous, conflict and none must all come back unapplied,
		// and bind() must never have been called for them.
	}

	public function testApplyReResolvesAndIgnoresAnyClientPersonId(): void {
		// Post a personId alongside the uid; assert the bind used the id the
		// resolver returned, not the one supplied. This is the property that
		// stops the tier system being bypassed by posting an arbitrary id.
	}

	public function testApplySkipsExcludedAccounts(): void {
	}

	public function testApplyOneBindsTheAdministratorsChosenPerson(): void {
		// apply-one MAY take a personId: an admin choosing among candidates is
		// a trusted decision.
	}

	public function testApplyOneStillRefusesAPersonBoundElsewhere(): void {
		// bind() throws; the endpoint must surface that rather than swallow it.
	}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/Unit/`
Expected: FAIL — the methods do not exist yet.

- [ ] **Step 3: Add `apply()` to the service**

In `SuggestionService`, add:

```php
	/**
	 * Re-resolve each account and bind the ones that come back exact.
	 *
	 * Re-resolving rather than trusting what the client last saw is the whole
	 * safety property: a tier the browser is holding may be minutes old, and a
	 * personId in a request body is simply an assertion. Nothing here reads an
	 * identity from the caller.
	 *
	 * @param array $uids accounts to apply
	 * @return array uid => outcome
	 */
	public function apply(array $uids): array {
		$results = [];

		foreach ($this->suggest($uids) as $uid => $result) {
			if (($result['status'] ?? '') !== 'resolved') {
				$results[$uid] = $result;
				continue;
			}

			if ($result['tier'] !== MatchResult::TIER_EXACT) {
				// Deliberate: a bulk action never applies a row a human has not
				// looked at. "Apply exact only" is the behaviour, not the label.
				$results[$uid] = ['status' => 'skipped', 'tier' => $result['tier']];
				continue;
			}

			try {
				$this->bindingService->bind(
					$uid,
					$result['personId'],
					$result['userId'],
					IdentityBindingService::METHOD_ADMIN,
					$this->bindingConfig->getMatchSource(),
				);
				$results[$uid] = ['status' => 'bound', 'personId' => $result['personId']];
			} catch (Throwable $e) {
				$results[$uid] = ['status' => 'failed', 'message' => $e->getMessage()];
			}
		}

		return $results;
	}
```

Add `IdentityBindingService $bindingService` to the constructor. Check `IdentityBindingService::METHOD_ADMIN` is the actual constant name before using it; if the constant differs, use the real one and note it in your report.

- [ ] **Step 4: Add the controller endpoints**

```php
	public function apply(array $uids = []): DataResponse {
		return new DataResponse(['results' => $this->suggestionService->apply($uids)]);
	}

	public function applyOne(string $uid = '', string $personId = ''): DataResponse {
		if ($uid === '' || $personId === '') {
			return new DataResponse(['message' => 'uid and personId are required'], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->userManager->userExists($uid)) {
			return new DataResponse(['message' => 'No such user'], Http::STATUS_NOT_FOUND);
		}
		if ($this->bindingConfig->isExcluded($uid)) {
			return new DataResponse(['message' => 'This account is excluded from matching'], Http::STATUS_BAD_REQUEST);
		}

		// An administrator choosing among candidates is a trusted decision, so
		// unlike apply() this accepts a personId. bind() still refuses a person
		// already linked to another account.
		try {
			$this->bindingService->bind(
				$uid, $personId, null,
				IdentityBindingService::METHOD_ADMIN,
				$this->bindingConfig->getMatchSource(),
			);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new DataResponse(['uid' => $uid, 'personId' => $personId, 'bound' => true]);
	}

	public function searchPerson(string $q = ''): DataResponse {
		return new DataResponse(['candidates' => $this->resolver->searchPersonsByName($q)]);
	}
```

Passing `null` for `userId` in `applyOne` is deliberate: `ProfileService` derives the iTop User from `person_id` on demand, and guessing it here would risk storing a User belonging to a different person. Note that reasoning in a comment.

- [ ] **Step 5: Add the person search**

In `lib/Service/Binding/IdentityResolver.php`, add a public `searchPersonsByName(string $term, int $limit = 20): array` returning the same `{person_id, user_id, name}` candidate shape the resolver already produces.

**The term is a user-supplied OQL literal.** It must go through the existing `escapeOQLString()`, and the class and field names must come from the existing allowlist — never from the request. Re-read how `resolve()` builds its query and follow it exactly; do not write a second, differently-escaped query builder.

Return `[]` for a blank term rather than every person on the instance.

- [ ] **Step 6: Routes**

```php
		['name' => 'binding#apply', 'url' => '/binding/apply', 'verb' => 'POST'],
		['name' => 'binding#applyOne', 'url' => '/binding/apply-one', 'verb' => 'POST'],
		['name' => 'binding#searchPerson', 'url' => '/binding/search-person', 'verb' => 'GET'],
```

- [ ] **Step 7: Verify against ground truth**

```bash
./vendor/bin/phpunit
```

Then in the devtools console:

```js
const post = async (u, b) => (await fetch(OC.generateUrl('/apps/integration_itop' + u), { method: 'POST', headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken }, body: JSON.stringify(b) })).json()

// 1. apply must bind pablo (exact) and skip test1 (review) and test2 (none)
await post('/binding/apply', { uids: ['pablo', 'test1', 'test2'] })

// 2. the security property: a forged personId must be ignored
await post('/binding/apply', { uids: ['test1'], personId: '2' })
```

Expected for (1): `pablo` → `status: "bound"`, personId `2`; `test1` → `status: "skipped", tier: "review"`; `test2` → `status: "skipped", tier: "none"`.
Expected for (2): `test1` still `skipped` — the extra field is ignored entirely.

Confirm with the authoritative source, not the response:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting pablo integration_itop person_id
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting pablo integration_itop binding_method
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop person_id
```

Expected: `2`, `admin`, and "The setting does not exist" for `test1`.

Then test `apply-one` on the review row, and the conflict refusal:

```js
await post('/binding/apply-one', { uid: 'test1', personId: '12' })   // expect bound
await post('/binding/apply-one', { uid: 'test2', personId: '2' })    // expect 409: person 2 is pablo's
```

Confirm `test1` now holds person 12 via `occ`, then **restore the dev fixture** — both accounts must end unbound:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting pablo integration_itop person_id --delete
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting pablo integration_itop binding_method --delete
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting pablo integration_itop binding_at --delete
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop person_id --delete
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop binding_method --delete
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test1 integration_itop binding_at --delete
```

Also delete `user_id` for each if it was written. Verify `nextadmin` is still bound to person 16 and untouched. Report the real output at every stage.

- [ ] **Step 8: Commit**

```bash
git add lib/Service/Binding/SuggestionService.php lib/Service/Binding/IdentityResolver.php lib/Controller/BindingController.php appinfo/routes.php
git commit -m "Add apply, apply-one and person search

apply re-resolves every account and binds only what comes back exact; it
never reads an identity from the request. A tier the browser is holding
may be minutes old, and a personId in a body is only an assertion.

apply-one does take a personId, because an administrator choosing among
candidates is a deliberate decision — but it still goes through bind(),
which refuses a person already linked to another account.

The person search escapes its term as an OQL literal and takes its class
and field names from the allowlist, like every other query here."
```

---

## Task 4: The tier column and the apply actions

**Files:**
- Modify: `src/admin-binding.js`
- Modify: `css/admin-binding.css`
- Modify: `templates/adminSettings.php`

**Interfaces:**
- Consumes: `/binding/suggest`, `/binding/apply` from Tasks 2 and 3; the existing `state`, `load()`, `render()`, `apiCall()`.
- Produces: `state.suggestions` — a plain object keyed by uid holding the last suggest result for rows currently on screen.

- [ ] **Step 1: Hold suggestions in state**

Add `suggestions: {}` to `state`. Clear it in `load()` for uids no longer on screen, exactly as `state.selected` is already pruned, and for the same reason — an action must never reach a row the administrator can no longer see.

Because suggestions are never cached server-side, `state.suggestions` is also discarded whenever the matching rule changes. The rule save handler lives in `src/admin-settings.js`; have it dispatch a `CustomEvent('itop:binding-rule-changed')` on `document`, and have `admin-binding.js` listen and clear. A stale tier shown against a new rule would be a lie.

- [ ] **Step 2: Render the tier cell**

Add a tier cell to each row, between the state cell and the actions cell. With no suggestion for that uid, render an em dash. With one, render:

| Tier | Cell | Row action |
|---|---|---|
| `exact` | iTop name + person id, success styling | **Apply** |
| `review` | both names side by side — `ncName` vs `itopName` | **Confirm…** |
| `ambiguous` | candidate count | **Choose…** (Task 5) |
| `conflict` | the holding uid, named | none |
| `none` | "no match", muted | none |
| status `excluded` | "excluded" | none |
| status `failed` | the message, error styling | none |

`conflict` and `none` get no action deliberately: `bind()` refuses a conflict on every path, so an Apply button would promise something the server rejects.

Every interpolated value is user-controlled — run all of them through the existing `esc()`.

Update `.itop-binding-row`'s `grid-template-columns` in `css/admin-binding.css` for the extra cell, and add tier badge styling consistent with the existing `.itop-binding-badge` rules.

- [ ] **Step 3: Wire the buttons**

Add to the delegated click handler in `attachHandlers()`, beside the existing row actions:

- `#itop-binding-suggest` — bulk: suggest for `state.selected`, or for every row on the current page if nothing is selected. Show a progress indication while it runs; one iTop call per account means this is not instant.
- `#itop-binding-apply-exact` — bulk: POST `/binding/apply` with the uids whose suggestion is `exact`. If none are, say so rather than issuing an empty request.
- `button[data-apply]` — per-row apply for an `exact` row.
- `button[data-confirm]` — per-row confirm for a `review` row: `window.confirm` naming both the Nextcloud and iTop names, then POST `/binding/apply-one` with that row's `personId`.

After any apply, reload the table so what is shown is what the server holds, and report counts with `showSuccess`/`showError` as the existing actions do.

- [ ] **Step 4: Add the bulk buttons to the bulk bar**

In `renderBulkBar()`, add **Suggest matches** and **Apply exact only** before the existing exclude/restore/clear buttons. Add a standalone **Suggest matches** button to the toolbar in `templates/adminSettings.php` as well, so the action is reachable with nothing selected.

"Apply exact only" is the literal label — it must never apply anything else.

- [ ] **Step 5: Build**

```bash
npm run lint
npm run build
```

Expected: 0 eslint errors, build succeeds.

- [ ] **Step 6: Verify against ground truth**

Hard-reload the admin page. Then:

1. Click **Suggest matches** with nothing selected. Confirm one `POST /binding/suggest` carrying all four uids, and that the tier cells fill in: `pablo` exact, `test1` review showing both names, `test2` no match, `nextadmin` resolved.
2. Click **Apply exact only**. Confirm `POST /binding/apply` and that **only `pablo`** binds. Verify with `occ user:setting pablo integration_itop person_id` → `2`, and `occ user:setting test1 integration_itop person_id` → does not exist.
3. Unlink `pablo` again from the row action, confirm with `occ`.
4. Use the per-row **Confirm…** on `test1`, accept, and verify person 12 via `occ`.
5. Change the matching rule and confirm the tier cells clear rather than showing stale tiers.

**Restore the fixture when done** — `pablo` and `test1` unbound, `nextadmin` still person 16. Report the real `occ` output at each stage.

Note `window.confirm` opens a blocking modal that stops browser automation. If you are driving the page programmatically, stub `window.confirm` in the page's own context and say in your report that you did.

- [ ] **Step 7: Commit**

```bash
git add src/admin-binding.js css/admin-binding.css templates/adminSettings.php
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Show match tiers and apply them from the table

Suggestions are requested, never automatic: one iTop call per account
means a page that resolved on load would not survive a real instance.

Only exact rows get an Apply action. A conflict row shows the account
already holding that person and gets no action at all, because bind()
refuses that write on every path and a button would promise otherwise.

Tiers are dropped when the matching rule changes, since a tier computed
under the old rule is a claim that is no longer true."
```

---

## Task 5: The ambiguous-row picker

**Files:**
- Create: `src/views/PersonPicker.vue`
- Modify: `src/admin-binding.js`

**Interfaces:**
- Consumes: `GET /binding/search-person` from Task 3; `state.suggestions`.

- [ ] **Step 1: Build the picker**

Create `src/views/PersonPicker.vue`, modelled closely on the existing `src/views/BindingScopePicker.vue` — same `NcSelect` usage, same debounce, same request-sequence guard, same error handling. It searches `/binding/search-person?q=` and emits the chosen `{person_id, name}`.

Reuse that component's structure rather than inventing a second pattern; if the two end up sharing more than half their logic, say so in your report and propose the extraction rather than doing it unasked.

- [ ] **Step 2: Open it from an ambiguous row**

A **Choose…** action on an `ambiguous` row opens the picker, seeded with that row's `candidates` so the obvious choices need no search. Choosing one POSTs `/binding/apply-one` with that `personId`, then reloads.

Mount it on demand with the same dynamic `import()` the scope picker uses, so the Vue runtime is not parsed on pages without the table.

- [ ] **Step 3: Build and verify**

```bash
npm run lint
npm run build
```

The dev fixture has **no ambiguous account** — `pablo` is exact, `test1` review, `test2` none. Create one rather than claiming the path is untested: add a second iTop Person sharing `test2`'s email, or point `test2`'s email at a value two iTop Persons share, so it resolves ambiguous. Then confirm the picker lists both candidates and that choosing one binds it.

**Restore both the iTop fixture and the Nextcloud fixture afterwards.** Report what you created, what you saw, and what you removed.

If you cannot create an ambiguous case in iTop, say so plainly and report the path as unverified rather than asserting it works.

- [ ] **Step 4: Commit**

```bash
git add src/views/PersonPicker.vue src/admin-binding.js
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Add the picker for ambiguous rows

An ambiguous match is several iTop people with equal claim to one
account, which the app refuses to guess between. The picker seeds with
those candidates and searches the rest, and the choice goes through
apply-one, where an administrator's deliberate pick is trusted."
```

---

## Task 6: Documentation

**Files:**
- Modify: `CHANGELOG.md`, `README.md`

- [ ] **Step 1: Changelog**

In `CHANGELOG.md`, inside `## [1.5.0]` → `### Added`, add beneath the scope picker bullet:

```markdown
- **Match suggestions on the user associations screen**: *Suggest matches* grades the selected accounts — or the current page — as exact, review, ambiguous or conflict, and *Apply exact only* binds the confident ones. Review rows show the Nextcloud and iTop names side by side for a one-click confirmation, ambiguous rows open a picker, and conflict rows name the account already holding that person and offer no action. Matching runs only when asked, because each account costs one iTop request
```

- [ ] **Step 2: README**

In the identity-binding bullet, after the sentence describing the User associations screen, note that matches can now be suggested and applied from the screen, and that `occ itop:bind` remains for scripted setups.

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md README.md
git commit -m "Document match suggestions on the associations screen"
```

---

## Out of scope — phase 4

Self-service discovery: `POST /binding/discover` and `/binding/discover/confirm`, the personal-settings UI, and the admin toggles that gate them. Note that the two unread toggles (`binding_token_enabled`, `binding_discovery_enabled`) were **removed** during the 1.5.0 review because nothing honoured them — phase 4 reintroduces them wired up, rather than finding them already present.
