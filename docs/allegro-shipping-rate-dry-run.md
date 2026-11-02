# Kurier Allegro: resumable production dry-run

After the normal deployment **and migration**, sign in as Owner/Admin and open:

`/admin/tools/allegro/shipping-rate-backfill`

1. Use **Synchronizuj cenniki Allegro** to explicitly GET and persist the current `allegro_main` shipping catalog, then check the connection/catalog. Existing Maxhendler diagnostics remain available. Do not start a global scan when the catalog cannot be read.
2. **Start nowego dry-runu** creates a technical run and starts scanning. The maximum Part ID and initial count bound this run. Each HTTP tick advances at most one product with at most two actual provider GETs and a 3.5-second provider budget. A product with more work continues from its persisted sanitized read cache on the next tick.
3. **Pauza**, **Wznów**, and **Stop** preserve the run and all saved rows. Resume loads the server checkpoint first. On reload or reopening the base URL, the latest run owned by the operator is loaded; a `#run=UUID` link selects a specific retained run. No background queue/worker is started. A tab must remain open for progress; after reopening, click Resume.
4. After a completed scan, **Ponów tylko błędy odczytu** runs one pass over read-failed results only. Successful/resolved products are not fetched again. Repeated failures are attempted once per explicit retry pass and remain available for another pass.
5. Pause or finish before downloading server-streamed JSON, full CSV, proposals CSV, or mapping-blockers CSV. Partial/stopped reports can also be exported with their run state. No need to download thousands of rows merely to resume.

## Technical persistence and business safety

Two existing technical tables contain only the diagnostic run and report:

- `allegro_shipping_audit_runs`: owner, state, snapshot max ID, cursor, processed/resolved counts, summary, breakdown, provider catalog, retry generation/history, last success/error, current Part ID, and a lease token/expiry.
- `allegro_shipping_audit_results`: unique `(run_id, part_id)`, sanitized result, provider-failure flag, retry generation and next allowed retry time. There are no foreign keys or triggers into business tables.

Checkpoint and result updates are atomic. Provider requests happen outside the transaction. New ticks use a 15-second lease (older leases retain their existing expiry), preventing concurrent tabs from claiming the same batch, and a fencing token rejects stale worker commits. After a lost response **following commit**, resume moves to the next product. After a crash **before commit**, at most the uncommitted product is read again after lease expiry; completed results do not repeat. An expired lease is recoverable without operator SQL.

Owner/Admin authorization, run ownership, normal CSRF protection on POST actions, the read-only session and no cache-backed limiter are retained. GET status/exports never advance a run. The scoped SQL guard allows INSERT/UPDATE/DELETE only against the two run tables while run persistence is active; a separate catalog scope permits INSERT/UPDATE only against `allegro_shipping_rates` and `allegro_shipping_catalog_syncs`; Part, MarketplaceListing, account and sync-log writes remain blocked. No Eloquent business model hydration, APPLY, OAuth refresh, publish/update, or marketplace write is introduced.

The new persistence endpoints report `database_writes=true` / `technical_report_writes=true` for technical actions, and always `business_writes=false`, `product_writes=false`, `marketplace_writes=false`, `allegro_writes=false`, `oauth_refresh=false`. GET status and pure diagnostics report no DB writes. This intentionally replaces the earlier blanket zero-DB-write promise with **zero business writes**; technical writes were explicitly authorized.

Reports persist until separately removed by an operator/database maintenance policy; there is no automatic pruning or cleanup of business data. Old browser-only runs cannot be recovered retroactively from summary counters: the 6165-product partial summary does not contain the per-product report. Start one new durable run after deployment; subsequent interruptions resume it.

## Tick budget, retry and browser recovery

Persisted run ticks do at most **two real provider GETs**, each with at most **3 s** timeout (also capped by the remaining tick budget), inside a **3.5 s provider-work budget**. Database/auth/proxy time is measured separately by the browser; this is not a guarantee about an unhealthy database or external webserver configuration. No long retry sleep occurs inside a run tick. The existing ad-hoc diagnostics remain separate from the persisted runner.

Sanitized pending reads are stored in the same technical checkpoint, without moving cursor or increasing processed/failed counters until the product is resolved. Successful GETs replay from that pending cache rather than being fetched again. A successful provider shipping catalog is reused for at most five minutes **inside the run checkpoint** to avoid a catalog GET per product. This does not change the separately synchronized local catalog. Provider offer bodies are reduced to id, publication status, archived flag and shipping rate ID before persistence. No descriptions, buyer data or raw error bodies are stored.

429, network/timeout and 500/502/503/504 retry across ticks, maximum three attempts per resource per pass, with exponential 1/2-second cooldown. Retry-After seconds/HTTP-date takes precedence. Deferred transient errors do not immediately become provider_read_failed; after exhaustion they become explicit failed outcomes. 401/403/404 and invalid responses are not automatically retried. GET offer 404 is `provider_offer_not_found`, terminal for that offer, never evidence that it was specifically ended or archived. A product with only resolved inactive/missing offers is not a technical provider failure.

The browser has a 10-second AbortController timeout, a single driver/resume lock, and bounded automatic recovery (up to four consecutive failed requests, backoff). After a lost response it reads server state before another tick, honors live leases/cooldowns, and never creates a new run or resets cursor during recovery. It does not automatically pause the backend after a fetch failure. Successful committed work is not replayed; an unfinished/expired lease may replay only uncommitted reads. 401/419 require login and then Resume of the same run; 403/404/422/protocol errors are shown explicitly. Repeated failure ends with “Runner zatrzymany — kliknij Wznów”. Multiple Resume clicks cannot start multiple local loops.

Failed-only retry replaces old results, subtracts previous counters/breakdown and adds new ones. Before this explicit retry, legacy results whose only unresolved evidence is offer 404 are reclassified in the **technical report**, without GET or business updates, and excluded from further read-failed retries. Resume itself preserves all existing results/counters and only adds diagnostic metadata. Mixed failures keep real technical errors and reuse terminal offer-404 evidence. Mapping blockers retain exact IDs/names; no product APPLY is added.

## Production diagnostics and evidence limits

No production database/session/server logs are available in this task. “Failed to fetch” alone cannot identify PHP timeout, reverse proxy timeout, browser/network reset or redirect. The audited old JavaScript had no explicit browser timeout and stopped after any fetch failure; it also allowed concurrent Resume initialization. Those are confirmed code weaknesses, not a proven production network root cause. Existing code allowed resume while state=running, so a paused-only backend restriction was not demonstrated. Production nginx/PHP limits cannot be inferred from repository defaults.

Checkpoint/status now supplies:

- `last_tick`: id, tick_started_at, tick_finished_at, duration_ms, requested_items, completed_items, provider_requests, HTTP status breakdown, retry count, completion flag and safe reason.
- `tick_totals`, live/stale lease state, lease token presence/fingerprint, lease_expires_in, resume_reason, blocked_reason, checkpoint heartbeat source and last abandoned tick. An unfinished tick has `metrics_complete=false`; actual request counts from a killed process cannot be reconstructed.
- Safe backend exception class/reason without message/stack/bindings, and whitelisted `last_client_event` (fetch/timeout/JSON/HTTP/session reason, stage, HTTP status, duration, response bytes, online flag and attempt). Response headers include exact uncompressed JSON byte size for status/actions.
- `provider_read_failed_by_reason`: one primary cause per failed product, including distinct HTTP status, timeout vs connection error, invalid_response and other. Legacy generic network failures cannot be split retroactively without original evidence. Existing legacy 404 failures remain counted until explicit failed-only retry reclassifies them; `provider_offer_not_found_count` also shows the terminal/historical 404 group independently. New resolved 404s never inflate technical failures.

Status responses cap failed-ID previews at 200, offers per displayed row at five, and retry-history preview at ten. Full persisted report rows are unchanged and available through streamed exports. Pending transport cache and shared catalog cache are hidden from status responses. These bounds and response-byte telemetry distinguish payload size from lost transport responses without persisting raw response content.

No migration/reset/restart is required by this patch. After deployment, open the existing run URL and click **Wznów**. For the reported run use:

`/admin/tools/allegro/shipping-rate-backfill#run=e2229ef9-4db3-4c85-ab01-e7931e705e7b`

It continues from its stored cursor (707 or any newer committed value), preserves run ID, results and local catalog, waits for any still-live old lease, and safely reclaims an expired one. Do not click Start to recover that run.

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
php vendor/bin/phpunit tests/Feature/AllegroRunnerStabilityTest.php tests/Feature/AllegroShippingCatalogTest.php tests/Feature/AllegroShippingAuditResumeTest.php tests/Feature/AllegroShippingRateBackfillTest.php tests/Feature/AllegroShippingReadDiagnosticsTest.php
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
