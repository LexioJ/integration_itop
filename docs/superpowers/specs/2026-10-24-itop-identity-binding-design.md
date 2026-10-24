# iTop identity binding — design

Date: 2026-10-24
Status: approved for planning

## Problem

Connecting a Nextcloud user to their iTop identity currently requires exactly one thing: the user pastes a personal token from iTop once. The app sends it to iTop with `SELECT Person WHERE id = :current_contact_id`, iTop resolves who that is, and the app stores `person_id` and `user_id`. The token is never persisted.

This has two problems.

1. **Portal users cannot do it at all.** iTop only issues personal tokens to backend (console) users. A portal-only contact has no way to obtain one, so they cannot bind unless the administrator installs the separate [itop-portal-personal-tokens](https://github.com/LexioJ/itop-portal-personal-tokens) extension. For portal-heavy deployments this is not inconvenient, it is impossible.
2. **It does not scale.** Binding 200 users means 200 people each finding a token in iTop.

Today a portal user who tries anyway sees:

> "Personal token validation failed: Portal users cannot use REST API directly. This is expected - the application token will handle all queries."

This returns `success => false` — binding did not happen — but the wording reads as reassurance and offers no path forward.

## Goals

- Let an administrator bind users centrally, without those users touching iTop.
- Keep the existing proof-based token flow working for users who can use it.
- Offer an optional self-service path that does not require a token.
- Make every binding auditable: who was bound to whom, by which method.

## Non-goals

- Replacing the personal-token flow. It remains the only method that *proves* identity and stays the default for backend users.
- Syncing anything other than identity. No ticket, profile, or organisation sync.
- Automatic binding without human confirmation. Every binding is confirmed by a person or explicitly forced via `--apply`.

## Binding methods

| Method | Who initiates | Availability | Proves identity? |
|---|---|---|---|
| Admin-side binding | Administrator | **Always available**, not configurable | n/a — administrator is already trusted |
| Self-service, personal token | User | Admin toggle, default **on** | Yes — iTop resolves `:current_contact_id` |
| Self-service, discovery | User | Admin toggle, default **off** | No — attribute assertion |

Admin-side binding is unconditional because it is the only method guaranteed to work for every user, including portal-only contacts. There is therefore always at least one working path, so no configuration can leave an instance with no way to bind.

### Why admin-side binding is not a security risk

The administrator already holds the application token, which can read every ticket in iTop. An administrator asserting "Nextcloud user `alice` is iTop person 42" is an administrative decision of the same kind as granting group membership. No privilege is gained that the administrator did not already have.

Discovery is different: there, an *untrusted* user's attribute decides which identity they receive. That is the entire risk surface of this feature and the reason discovery is opt-in and defaults to off.

## Configuration

### App config (admin)

| Key | Values | Default | Purpose |
|---|---|---|---|
| `binding_token_enabled` | `'0'` / `'1'` | `'1'` | Self-service personal-token binding. Default preserves current behaviour. |
| `binding_discovery_enabled` | `'0'` / `'1'` | `'0'` | Self-service discovery. Opt-in because it carries impersonation risk. |
| `binding_match_source` | `email` \| `uid` \| `displayname` | `email` | Nextcloud-side attribute. |
| `binding_match_target` | `person.email` \| `person.employee_number` \| `user.login` | `person.email` | iTop-side attribute. |
| `binding_excluded_users` | JSON array of uids | `[]` | Accounts hidden from the table and skipped by matching. |

The matching rule is always configured, because admin suggestions and the occ command need it regardless of whether discovery is enabled.

All values are read and written with `getValueString` / `setValueString`, consistent with the 1.5.0 migration away from `IConfig`.

### Per-user config

Existing: `person_id` (required), `user_id` (optional — see below).

New:

| Key | Values | Purpose |
|---|---|---|
| `binding_method` | `admin` \| `token` \| `discovery` | Origin badge in the admin table; audit. |
| `binding_at` | unix timestamp | When the binding was made. |

No new database tables. Binding state already lives in per-user config, and `IUserConfig::getValuesByUsers()` fetches it for a page of users in one query.

**`person_id` is required; `user_id` should be written whenever it exists.** In practice nearly every Person has a corresponding User. This matters because iTop profiles are attached to the **User**, not the Person, and the profile list is what determines whether someone is portal-only. So `user_id` is the key to profile resolution, not an optional extra — the resolver writes it in the normal case, and the `user.login` target yields it for free.

A `Person` with no `User` is the edge case. The existing code tolerates it (`user_id` nullable, "Not critical — we can continue without user_id"), and `ProfileService` is self-healing: when `user_id` is absent it derives it from `person_id` via `getUserIdByPersonId()`, caches the result, and if no `User` exists at all it falls back to the portal profile. So binding never has to special-case it, but it should not rely on that fallback either.

## Components

```
IdentityResolver        resolve(IUser): MatchResult        — matching, tiers, OQL safety
IdentityBindingService  bind/unbind/exclude/restore        — writes user config, audit log
BindingController       admin endpoints + 2 user endpoints
BindUsers               occ itop:bind
```

One resolver, three callers (admin suggestions, discovery, occ). A single implementation means tier rules and OQL construction cannot drift between paths.

**A new controller, not `ConfigController`.** That file is already ~2,400 lines and is where this would naturally land; adding ~10 endpoints would make an existing problem worse. Binding gets its own controller.

## IdentityResolver

```
resolve(IUser $user): MatchResult
MatchResult { tier, candidates[], personId, userId, itopName, sourceValue }
```

1. Read the source attribute **from the Nextcloud account, server-side**. The value is never accepted from a request.
2. Resolve the target to an allowlisted `(class, field)` pair.
3. Build OQL: the field name comes from the allowlist; the value goes through the existing `escapeOQLString()`.
4. Query via the application token.
5. If the target is a `Person` field: match Person, then `SELECT User WHERE contactid = <id>`. If the target is `user.login`: one query yields both `User.id` and `contactid`.
6. Compare names and assign a tier.

### Why the target must be allowlisted, not escaped

The target field name is interpolated into OQL as an *identifier*, not a literal. String escaping protects literals only, so an admin-supplied field name would be an injection vector. A fixed allowlist is the only correct control. The value, being a literal, is escaped.

### Match tiers

| Tier | Condition | Bulk-applicable | Self-service |
|---|---|---|---|
| `exact` | Attribute matched **and** names agree after normalisation | Yes | Yes |
| `review` | Attribute matched, **names differ** | No | No |
| `ambiguous` | More than one candidate | No | No |
| `none` | No match | No | No |

**Name normalisation:** lowercase, strip diacritics, collapse whitespace, split into tokens, sort, compare. Nextcloud `displayName` against iTop `first_name` + `name`.

Comparison is **strict**: anything not normalised-equal drops to `review`. "Alex Askin" vs "Alexander Askin" therefore requires confirmation. This produces more admin clicks and no silent mis-bindings, which is the intended trade.

A `review` row shows both names side by side so the administrator can make the judgement the app deliberately refuses to make.

## Security properties

These are requirements, not implementation details.

1. **The source value is derived server-side.** The discovery endpoint accepts no parameters. This removes both forgery and the ability to enumerate iTop contacts by probing addresses.
2. **Confirmation re-resolves.** The confirm step must not accept a `personId` from the client. It re-runs `resolve()` and binds to that result. Otherwise the entire tier system is bypassed by posting an arbitrary id.
3. **Self-service discovery binds only on `exact`.** `review`, `ambiguous` and `none` tell the user to contact their administrator. A user confirming a name mismatch *about themselves* is not a meaningful check; an administrator comparing two names is.
4. **Discovery will not re-bind an already-bound account.** Otherwise a user could bind, change their email, and re-bind. Rebinding requires an administrator to unlink first.
5. **Discovery is rate-limited** with `#[UserRateLimit]`.
6. **Every bind writes an audit log line**: Nextcloud user, person id, method, and the source attribute used.
7. **Excluded accounts are never matched or bound** by any method, including occ and discovery.

### The mutability warning

When `binding_match_source` is user-mutable, the admin settings show, next to the rule:

> ⚠ **Users can change their own email on this instance.** A user could set their address to a colleague's and bind to that person, gaining read access to their tickets. Set `allow_user_to_change_email` to `false`, or match on User ID, which cannot be changed.

Mutability is determined by `OCP\IUser::canChangeEmail()` for email sources and `canChangeDisplayName()` for display name. `uid` is always immutable — Nextcloud exposes no `setUID` or rename API.

The warning is informational. It does not block, by explicit decision: administrators may have compensating controls, and discovery is already opt-in and default-off. The warning names the specific attack and gives concrete remedies rather than being generic. Admin-side binding is unaffected and the warning says so.

## Endpoints — BindingController

Admin (admin-only):

| Method | Route | Purpose |
|---|---|---|
| GET | `/binding/users` | Paginated list; `filter=unbound\|bound\|review\|excluded\|all`, `search`, `page` |
| POST | `/binding/suggest` | `{userIds:[]}` → match result per user |
| POST | `/binding/apply` | `{userIds:[]}` → re-resolves and binds `exact` rows |
| POST | `/binding/apply-one` | `{userId, personId}` → explicit confirm for `review` / `ambiguous` |
| POST | `/binding/exclude`, `/binding/restore` | `{userIds:[]}` |
| DELETE | `/binding/{userId}` | Unlink |
| GET | `/binding/search-person` | `q` → candidates for the manual picker |

User (`#[NoAdminRequired]`):

| Method | Route | Purpose |
|---|---|---|
| POST | `/binding/discover` | No parameters. Returns the proposed identity for confirmation. |
| POST | `/binding/discover/confirm` | No identity parameters. Re-resolves and binds. |

`apply-one` takes a `personId` because the administrator is choosing deliberately among candidates; that is an admin decision and admins are trusted. The user-facing confirm endpoint takes no identity parameter, per security property 2.

## Admin UI

A new "User associations" section in admin settings: a paginated, searchable table with multi-select and a contextual bulk action bar.

- Filters: Unbound / Bound / Needs review / Excluded / All
- Bulk actions: Suggest matches · Apply exact only · Exclude from matching · Unlink
- Per-row actions by tier: `exact` → Apply · `review` → Confirm… · `ambiguous` → Choose… · `none` → Exclude
- Bound rows show an origin badge: `admin` / `token` / `discovery`
- Excluded rows stay visible under their own filter with a **Restore** action, so nothing silently disappears

"Apply exact only" is the deliberate wording: a bulk action can never apply a `review` row.

## Personal settings UI

Shows whichever self-service methods are enabled. Because portal-vs-backend status is derived from `user_id`, which only exists *after* binding, the app cannot know in advance whether a given unbound user can use a token — so both enabled methods are offered and the failure is made actionable.

Replacing the current misleading portal message:

> **iTop does not issue personal tokens to portal users.**
> — if discovery is enabled: "Use *Discover me in <instance name>* instead."
> — otherwise: "Ask your administrator to link your account."
> — always: "Alternatively, an administrator can install the iTop Portal Personal Tokens extension."

## occ command

```
occ itop:bind [--apply] [--include-review] [--user <uid>] [--limit <n>]
```

- Dry-run by default; `--apply` is required to write.
- Binds `exact` only; `--include-review` is required for `review` rows and is refused without `--apply`.
- Never binds `ambiguous` or `none`.
- Skips excluded accounts and already-bound accounts.
- Prints a per-user table and a tier summary.

## Error handling

| Condition | Behaviour |
|---|---|
| Application token missing/invalid | Clear admin-facing error; no partial writes |
| iTop unreachable | Surfaced per request; the bulk apply is per-row, so one failure does not abort the batch |
| Ambiguous match | Never auto-resolved |
| Person without User | Bind `person_id`, leave `user_id` empty, treat as portal-only |
| Discovery on an already-bound account | Refused with "already linked — ask your administrator to unlink first" |
| Discovery disabled or user excluded | Endpoint returns 403 regardless of UI state |

## Testing

The repo has no `tests/` directory, though `composer.json` already declares phpunit ^9.5 and an `OCA\Itop\Tests\` autoload mapping. This adds the first real unit tests, covering the pure, branch-heavy, dependency-free logic:

- Name normalisation: case, diacritics, whitespace, token order, hyphenated and compound names
- Tier decision: each of the four tiers, including the "attribute matched but names differ" case
- OQL construction: value escaping (including apostrophes, per the existing `escapeOQLString` fix) and target allowlist rejection
- Exclusion filtering

Integration behaviour is verified manually against the `nextcloud-dev` / `itop-dev` VMs, as with the 1.5.0 work: admin table paging and filters, a bulk apply, a `review` confirmation, a discovery round trip, and `occ itop:bind --apply`.

## Implementation phases

Each phase is independently verifiable.

1. **Resolver + binding service + config** — `IdentityResolver`, `IdentityBindingService`, app/user config keys, unit tests. No UI.
2. **Admin table** — `BindingController` admin endpoints and the associations UI. Makes the feature usable for portal users, which is the primary motivation.
3. **occ command** — `itop:bind`, reusing phase 1.
4. **Discovery** — the two user endpoints, the personal-settings button, the toggle, the mutability warning, and the portal error-message fix.

Phase 2 delivers the main value; phases 3 and 4 are additive.

## Deferred

- Excluding accounts by *group* rather than individually. Individual exclusion was chosen as explicit and visible; group rules stay correct as accounts come and go and can be added later without migration.
- Fuzzy/near-match name comparison. Strict was chosen deliberately.
- Scheduled background re-binding. The occ command covers scripted use; a cron job can call it.
