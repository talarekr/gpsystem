# Kurier Allegro: production read-only audit

After the normal deployment, sign in as Owner/Admin and open:

`/admin/tools/allegro/shipping-rate-backfill`

1. **Sprawdź cenniki Allegro** reads `/sale/shipping-rates` independently for each Allegro account. Other provider names are visible with a null local option.
2. **Globalny dry-run** scans every base `parts` row, including archived/sold parts, in ID order, five per HTTP request. Leave the tab open. It checks provider status for every linked Allegro listing, including locally ended listings, to avoid trusting stale local status.
3. Review summary, account/rate breakdown, and proposals. Download the full JSON, full CSV, and/or proposals-only CSV. Exports are enabled only after all batches complete. CSV cells are escaped against spreadsheet formulas.

No schema change, command, APPLY endpoint, queue, persisted progress, provider write, OAuth refresh, snapshot update, sync log, or product update is introduced. The tool uses query-builder SELECTs instead of Eloquent product/listing hydration. It uses the existing read-only session middleware, no cache-backed rate limiter, and a scoped pre-execution SQL mutation guard. Four explicit `*_writes=false` fields are included in every API result and the final JSON.

## Mapping and decisions

The provider catalog for the listing's explicit account is authoritative. Match `delivery.shippingRates.id` to this catalog, then match the resulting name **exactly** to a key of `AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS`. No historical UUID fallback, trimming/case folding of provider names, new local option, or account inference is allowed. Empty current values include NULL, empty strings, and whitespace-only strings. Any different nonblank current value is a conflict.

More than one remotely active listing, duplicate aliases, or an offer linked to multiple parts is blocked. JSON includes per-listing diagnostics under `offers`. Missing account/offer mapping, malformed responses, 401/403/404/429/5xx, and connection failures are blockers, never evidence that a product has no active offer. No OAuth retry is performed. Fix authorization outside this tool and rerun.

The client allows only the official production/sandbox Allegro API base URLs, disables redirects, performs GET only, and does not return credentials, raw response bodies or exception messages. Application integration credentials are read/decrypted in memory, never exported.

## Counts and scope

- `parts_with_one_active_offer` / `parts_without_active_offer` require a resolved read of every linked offer. `parts_with_multiple_active_offers` includes products with at least two confirmed active listings even if another listing could not be read.
- `provider_read_failed` counts products with unavailable provider/account reads. `provider_read_unavailable` is also supplied per product, including ambiguous products with additional unread listings.
- `missing_mapping` counts all `BLOCK_MISSING_MAPPING` outcomes; `unknown_shipping_rate` is a subset. Full reason codes remain in CSV/JSON.
- The breakdown counts each product with one unambiguous, fully read active offer and a catalog-resolved rate once, including current-value conflicts and provider names absent from local options. Ambiguous products are excluded. Unused catalog rates appear with zero products in the panel/JSON. JSON also supplies counts for all seven local options.
- With no linked Allegro listing, `NO_ACTIVE_OFFER` means **no known active linked offer**. The audit does not search Allegro by SKU, create missing mappings, or use legacy payload as evidence.
- The first batch bounds the scan by the current maximum part ID. Later inserts are excluded. Concurrent edits/deletes and provider changes remain possible; timestamps and catalog observations are included. This is a time-spanning audit, not a transactional snapshot or an apply authorization. Refresh before a future apply.
- Reports and progress exist only in browser memory. Closing/reloading the tab loses them. A failed/stopped run cannot be downloaded as a complete report; restart the audit. Failed provider reads are exported as blockers when the scan itself completes.
- Catalogs are reread per batch so credentials/catalogs are never sent back from the browser or cached in the database. For a large installation this trades additional GETs for zero persisted state.

Tests use SQLite in memory and mocked HTTP; they never query the production database or Allegro. Run:

```bash
php vendor/bin/phpunit tests/Feature/AllegroShippingRateBackfillTest.php
```

No deployment is performed as part of this change. Real production counts are obtained only after the user deploys and runs the panel.

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
