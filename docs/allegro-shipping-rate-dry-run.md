# Kurier Allegro: resumable production dry-run

After the normal deployment **and migration**, sign in as Owner/Admin and open:

`/admin/tools/allegro/shipping-rate-backfill`

1. Use **Synchronizuj cenniki Allegro** to explicitly GET and persist the current `allegro_main` shipping catalog, then check the connection/catalog. Existing Maxhendler diagnostics remain available. Do not start a global scan when the catalog cannot be read.
2. **Start nowego dry-runu** creates a technical run and starts scanning. The maximum Part ID and initial count bound this run. One product is processed per HTTP batch to limit request duration.
3. **Pauza**, **Wznów**, and **Stop** preserve the run and all saved rows. Resume loads the server checkpoint first. On reload or reopening the base URL, the latest run owned by the operator is loaded; a `#run=UUID` link selects a specific retained run. No background queue/worker is started. A tab must remain open for progress; after reopening, click Resume.
4. After a completed scan, **Ponów tylko błędy odczytu** runs one pass over read-failed results only. Successful/resolved products are not fetched again. Repeated failures are attempted once per explicit retry pass and remain available for another pass.
5. Pause or finish before downloading server-streamed JSON, full CSV, proposals CSV, or mapping-blockers CSV. Partial/stopped reports can also be exported with their run state. No need to download thousands of rows merely to resume.

## Technical persistence and business safety

Two existing technical tables contain only the diagnostic run and report:

- `allegro_shipping_audit_runs`: owner, state, snapshot max ID, cursor, processed/resolved counts, summary, breakdown, provider catalog, retry generation/history, last success/error, current Part ID, and a lease token/expiry.
- `allegro_shipping_audit_results`: unique `(run_id, part_id)`, sanitized result, provider-failure flag, retry generation and next allowed retry time. There are no foreign keys or triggers into business tables.

Checkpoint and result updates are atomic. Provider requests happen outside the transaction. A three-minute lease prevents concurrent tabs from claiming the same batch, and a fencing token rejects stale worker commits. After a lost response **following commit**, resume moves to the next product. After a crash **before commit**, at most the uncommitted product is read again after lease expiry; completed results do not repeat. An expired lease is recoverable without operator SQL.

Owner/Admin authorization, run ownership, normal CSRF protection on POST actions, the read-only session and no cache-backed limiter are retained. GET status/exports never advance a run. The scoped SQL guard allows INSERT/UPDATE/DELETE only against the two run tables while run persistence is active; a separate catalog scope permits INSERT/UPDATE only against `allegro_shipping_rates` and `allegro_shipping_catalog_syncs`; Part, MarketplaceListing, account and sync-log writes remain blocked. No Eloquent business model hydration, APPLY, OAuth refresh, publish/update, or marketplace write is introduced.

The new persistence endpoints report `database_writes=true` / `technical_report_writes=true` for technical actions, and always `business_writes=false`, `product_writes=false`, `marketplace_writes=false`, `allegro_writes=false`, `oauth_refresh=false`. GET status and pure diagnostics report no DB writes. This intentionally replaces the earlier blanket zero-DB-write promise with **zero business writes**; technical writes were explicitly authorized.

Reports persist until separately removed by an operator/database maintenance policy; there is no automatic pruning or cleanup of business data. Old browser-only runs cannot be recovered retroactively from summary counters: the 6165-product partial summary does not contain the per-product report. Start one new durable run after deployment; subsequent interruptions resume it.

## Retry policy

Only GET `/sale/product-offers/{id}` has automatic retries: at most three attempts total, exponential 250/500 ms backoff, for network/timeouts, 429, and HTTP 500/502/503/504. No automatic retry of 401, 403, 404, malformed responses or other statuses. Requests have an 8-second timeout / 3-second connect timeout, with a shared 25-second read budget per persisted product batch (including catalog and multiple offer links); no OAuth retry or token refresh is performed.

429 `Retry-After` supports integer seconds and HTTP-date. Short delays up to two seconds are waited before the next attempt. Longer deadlines are returned safely, persisted on the failed row and run, and respected before another batch; the browser polls saved state in short intervals instead of sleeping in a long HTTP request. An exhausted retry still produces a read-failed row with the safe per-offer reason, HTTP status, attempt count and retry deadline. Catalog reads remain separate GETs; a catalog 429 deadline is also persisted when it blocks the product.

Failed-only retry replaces the original result, subtracts its old counters/breakdown and adds the new ones. A successful retry decreases both `provider_read_failed` and unresolved reads without increasing total processed products. Retry history preserves failed-before/failed-after counts. Per-product mapping failures retain exact `part_id`, `allegro_offer_id`, `shipping_rate_id`, `shipping_rate_name`; no additions to `SHIPPING_RATE_OPTIONS` are made; dynamic names come from the synchronized catalog.

## Counter and mapping semantics

- `processed_count` = distinct saved products; `completed_count` / Resolved = saved outcomes without unavailable provider reads. Resolved includes conflicts and mapping blockers; it is not an apply authorization.
- `empty_kurier_allegro` = blank **local** `parts.allegro_shipping_rate_name`, labelled "Puste lokalne pole Kurier Allegro". It does not indicate a missing provider shipping rate.
- `parts_without_active_offer` excludes unresolved reads. A product without any linked offer means no **known linked** active offer; no SKU search or legacy-activity inference is added.
- Only one fully resolved active offer can propose a value. Use the account's current provider rate ID/name and an active local catalog entry with the same account/ID and exact name. Every synchronized name is supported; there is no seven-name limit. Unknown IDs/names, ambiguous/shared offers and nonblank conflicts remain blockers.
- The breakdown counts one product per resolved catalog rate, including current-value conflicts and unsupported local names; ambiguous products are excluded. All active synchronized local options and unused provider catalog rates remain visible with zero counts where applicable.
- The max-ID boundary is not a transactional snapshot of business data. Concurrent edits/deletes remain possible; a completed scan whose saved count differs from its initial total is marked `coverage_changed`. Refresh the audit before any future apply decision.
- All result timestamps, safe reasons and observed values are retained. Credentials, tokens, raw provider error bodies and exception messages are never persisted/exported. CSV formula cells are neutralized.

Validation uses SQLite in memory and fake HTTP in a container without network:

```bash
php vendor/bin/phpunit tests/Feature/AllegroShippingAuditResumeTest.php tests/Feature/AllegroShippingRateBackfillTest.php tests/Feature/AllegroShippingReadDiagnosticsTest.php
node tests/Browser/allegro-shipping-audit-resume.cjs
```

No deployment or production scan is performed by this PR.


## Synchronized shipping catalog (integrated with persistent runs)

**Synchronizuj cenniki Allegro** is a separate Owner/Admin POST action with normal CSRF protection on the existing panel. It only GETs `/sale/shipping-rates` for Maxhendler / `allegro_main` and atomically persists technical catalog rows. It never changes Parts, listings, accounts, sync logs, prices, stock or Allegro offers, and never refreshes OAuth. Catalog actions explicitly report `database_writes=true`, `catalog_writes=true`, all business/provider write flags false. Run persistence and catalog persistence have separate SQL guard scopes.

`allegro_shipping_rates` stores `marketplace_account_id`, `shipping_rate_id`, exact `shipping_rate_name`, `active`, `last_seen_at`, `synced_at`, with a unique account/rate ID. `allegro_shipping_catalog_syncs` records successful snapshot metadata, including a valid empty list, and serializes commits so an older response cannot replace a newer snapshot. Invalid/malformed/duplicate-ID responses leave the last good snapshot untouched. Missing rates become inactive without deletion; their last_seen_at is retained.

The Part Select reads all active local catalog names, not a hardcoded list of 7 or 18. Part still stores its name in `parts.allegro_shipping_rate_name`. Before the first successful sync the seven legacy options remain available. An existing historical/inactive Part value remains visible and is not erased during editing, but does not become an active catalog choice. `MANUAL_SHIPPING_RATE_IDS` remains a historical pre-sync fallback for allegro_main; it cannot override an inactive/stale synchronized catalog. Existing publishing readiness accepts dynamic names and verifies live/local shipping ID agreement; duplicate active names block ambiguous name-based resolution.

The dry-run reads and validates the current provider catalog and requires a matching active local account/ID/name entry. Unsynchronized, missing or stale local entries block as `missing_mapping`, with `mapping_detail=local_catalog_not_synchronized` or `local_catalog_missing_or_stale`. Names absent from the historical seven are fully supported after sync. Part writes/APPLY remain absent.

**Existing checkpoints, leases, failed-only retry, streamed CSV/JSON and seven-name legacy records are retained.** Catalog sync does not rewrite saved results or counters of previous runs. After deployment/migration, synchronize the catalog and start a **new** full dry-run to evaluate every product under the new catalog contract. Old reports remain readable/exportable; resuming an older run does not retroactively reclassify its saved results. A partial run's new batches use current provider/local catalog data, as before this is a time-spanning audit.

Additional validation:

```bash
php vendor/bin/phpunit tests/Feature/AllegroShippingCatalogTest.php tests/Feature/AllegroShippingAuditResumeTest.php tests/Feature/AllegroShippingRateBackfillTest.php tests/Feature/AllegroShippingReadDiagnosticsTest.php
node tests/Browser/allegro-shipping-audit-resume.cjs
```

This change is based on current main **a68f5a8** (includes #1501), replacing the closed outdated #1502 proposal. No deploy, production catalog synchronization, product backfill or provider write was performed.

## Diagnosing Maxhendler / account 2 without refresh

The original `provider_read_failed` is not proof of an expired token. The original client collapsed unsuccessful HTTP responses, invalid JSON, decrypt/JSON errors and transport exceptions into one reason. The catalog service also omitted the client's HTTP status. Production cause cannot be reconstructed from that result alone.

Use **Diagnozuj połączenie Maxhendler** on the same panel. It calls `?mode=diagnose`, verifies that ID 2 and code `allegro_main` identify the same Allegro record, then attempts only GET `/me` and GET `/sale/shipping-rates`. It does not scan Part or select another account. `/me.login` is compared to `Maxhendler`; a local account label alone is not identity proof. A failed identity read remains unconfirmed even if shipping rates succeed.

The normal **Sprawdź cenniki Allegro** now also displays HTTP status and sanitized credential/expiry diagnostics. Only booleans, normalized expiry, safe codes, and (for the targeted `/me` check) provider login are projected. Credential values, raw error bodies and exception messages are excluded. Missing/unreadable credentials are distinguished, and unavailable token-presence data is null rather than false.

Reasons include `account_missing`, `account_mismatch`, `account_disabled`, `credentials_missing`, `credentials_unreadable`, `access_token_missing`, `oauth_refresh_required`, `unauthorized_401`, `forbidden_403`, `provider_429`, `provider_5xx`, `network_error`, `invalid_response`, `endpoint_error`, and `unknown`.

If local expiry is known and in the past, no HTTP request is made: `reason=oauth_refresh_required`, `cause=access_token_expired`, `requires_refresh=true`, and `refresh_blocked_reason=oauth_refresh_blocked_by_zero_write`. HTTP status is null; it is not an inferred 401. Unknown/invalid expiry does not establish expiration: a GET may still be attempted. A received 401 is explicitly `unauthorized_401`; it can also indicate revocation or another auth problem, so it does not prove expiration. No refresh or automatic retry is attempted.

### Existing OAuth flow: alternatives A/B/C

- **A — recommended existing recovery, only after diagnosis:** `/admin/allegro-settings` → **Połącz z Allegro**. `AllegroOAuthController::callback()` exchanges an authorization code and saves tokens, expiry and connection status. Its code does not publish/update offers or synchronize products. The operator must knowingly perform this normal connection operation outside this zero-write panel. This change neither starts nor invokes it.
- `AllegroApiClient::accessToken()` calls `OAuthTokenManager::ensureValidToken()`. `getWithAuthRetry()` uses it and refreshes/retries on 401. `requestSample()` and selected catalog/compatibility GETs use that path. Not every Allegro GET refreshes: `productOffer()` and `resolveSalesSettingsByNames()` read the stored token directly. There is no basis for claiming the production token is automatically fresh merely because an account is connected.
- `OAuthTokenManager` refresh uses OAuth POST and persists encrypted credentials plus connection status and `oauth_token_refresh` sync logs on success **and failure**. It refreshes when expiry is missing or within 300 seconds. Therefore importing it into this diagnostic would violate zero-write.
- **B — not selected:** memory-only refresh still performs OAuth POST; the normal response handler supports a returned replacement refresh token. Discarding that response without persisting it can leave stored credentials stale, and this architecture does not provide a verified safe memory-only refresh path. It is not a read-only GET solution.
- **C — possible separate future operator action:** an explicitly labelled connection refresh could use the normal token manager with `database_writes=true` and remain separate from dry-run. No such action is added here.

After a diagnosed expiry and a successful normal reconnection, rerun **Diagnozuj połączenie Maxhendler** / **Sprawdź cenniki Allegro**. Do not start the global dry-run until the catalog GET succeeds. No recovery, production request, deployment, global run or backfill is performed by this PR.
