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
