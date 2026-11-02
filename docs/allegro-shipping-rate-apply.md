# Confirmed empty-field APPLY — MaxHendler

This operation is separate from dry-run and accepts only completed scan
`e2229ef9-4db3-4c85-ab01-e7931e705e7b`, owned by the authenticated OwnerAdmin.
The expected frozen proposal count is **4609**, out of **7318** processed results.
It never calls a provider or refreshes OAuth.

## Deployment and operator sequence

1. Deploy the PR and run the normal migrations, including
   `2026_11_02_230000_create_allegro_shipping_apply_plans.php`. This creates only the
   technical plan/ledger table; it does not update products or start APPLY.
2. Open `/admin/tools/allegro/shipping-rate-backfill` and find **Uzupełnij puste pola Kurier Allegro**.
3. Click **Przygotuj plan APPLY**. Review `run_id`, `eligible_count`,
   `expected_count=4609`, `count_difference`, `plan_digest`, exclusions, 287 already
   correct and 250 conflicts from the frozen summary. The expected count is a
   guard, not a hardcoded assertion that production has been inspected.
4. If eligible differs from 4609, APPLY stays blocked. Inspect the exclusions;
   do not change the expected count or force a write.
5. Enter exactly `APPLY-ALLEGRO-SHIPPING-RATE-BACKFILL`, then consciously click
   **Uzupełnij puste pola**. Preparing a plan or typing the token does not execute it.
6. Review the report. No production action is performed by creating/deploying this PR.

## Evidence and digest

Only `SET_EMPTY_FIELD` / `exact_mapping` rows qualify. Each must contain complete
provider evidence for exactly one active offer from account ID 2 / `allegro_main`,
HTTP 200/read success, a nonempty provider shipping ID/name and a matching active
entry in the synchronized local catalog. Provider-failed rows are excluded even
if their other fields resemble a proposal. All 7318 result rows must be present.

The persisted plan contains sorted Part IDs, provider rate IDs and proposed names.
An HMAC-SHA256 digest binds plan ID, owner, pinned run, expected count, frozen entries
and source digest. The source digest covers the checkpoint, every saved result and
local catalog/sync state. APPLY verifies these again before updating anything.
Changed source/catalog returns `source_changed`, actual eligible count and the
difference. A changed source does not silently replace the old plan or ledger.
The live Part field is deliberately excluded from this digest: changes are handled
by compare-and-set as `skipped_concurrent_change`.

## Write boundary, concurrency and replay

`database_writes=true`, `product_field_writes=true`, `provider_calls=false`,
`allegro_writes=false`, `marketplace_writes=false` for successful APPLY. Plan
preparation writes only the technical plan table (`product_field_writes=false`).
Dry-run routes retain their existing write restrictions.

A single local transaction locks the run, catalog sync state, plan and each Part
in ascending ID order. A Query Builder UPDATE sets **only**
`parts.allegro_shipping_rate_name` when the locked value is still NULL/blank.
Whitespace-only values are blank. Its WHERE compares the exact observed blank
value (or IS NULL), so a concurrent change cannot be overwritten. There is no
`Part::save()`, observer, updated_at write, price/stock change, listing update or
marketplace job. Existing identical values without an APPLY ledger entry are
also skipped as concurrent changes.

The same transaction persists applied IDs and the last report in the plan table.
Repeat requests serialize on the same plan/run and return `already_applied` for
those IDs without another UPDATE. This also protects later manual changes and
manual clearing. Preparing again returns the same plan and last saved report.
A lost browser response can therefore be recovered without a new plan or run.
There is no force-overwrite, automatic failed-only retry or catalog synchronization.

The report contains `planned`, `updated`, `already_applied`,
`skipped_concurrent_change`, `blocked`, `failed` and at most 20 example IDs per
category. `blocked` is all results outside the plan, including already-correct,
conflict, provider failure, missing mapping and no-active-offer rows. It is not
added to `planned`; the other outcome counts sum to `planned`. On a database
failure the entire transaction rolls back (including the ledger), updated=0 and
a safe `apply_transaction_failed` report is returned without SQL/exception text.
The scan report, cursor, summary and historical 159 offer_404 failures are untouched.

## Verification

- `tests/Feature/AllegroShippingRateApplyTest.php`: 7318-result fixture, guarded
  4609 proposals, real dry-run payload compatibility, blank-only updates, excluded
  actions, concurrent changes, durable replay, token/digest/source rejection,
  inactive catalog, rollback, query scope, owner-only endpoint and no provider/jobs.
- Existing runner, resume, catalog and read-only audit tests remain applicable.
- `python3 tests/Browser/allegro-shipping-rate-apply-browser.py`: real Chromium,
  intercepted local HTTP only; token gate, digest submission, duplicate-click
  protection, count mismatch and changed-source error display. Requires Python
  Playwright and Chromium (`CHROMIUM_PATH`, default `/usr/bin/chromium`).
