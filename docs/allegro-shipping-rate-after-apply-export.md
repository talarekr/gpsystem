# Current-state CSV after shipping-rate APPLY

The existing shipping-rate panel adds **Sprawdź podsumowanie po APPLY** and three
CSV download links. No migration, provider call or APPLY is involved. Access is
limited to the authenticated OwnerAdmin who owns the run and plan. Normal read-only
session and SQL guards cover the endpoint.

Pinned evidence:

- Run `e2229ef9-4db3-4c85-ab01-e7931e705e7b`, completed 7318/7318.
- Plan `fa67d424-d251-4dc6-971e-b4f8ab26d5ba` with 4609 unique durable `applied_ids`.
  An absent/inconsistent ledger blocks export; nonempty fields never substitute
  for proof of APPLY. A repeated APPLY may change last-report `updated` to zero;
  the durable applied-ID list remains the authority.

`GET /admin/tools/allegro/shipping-rate-backfill/after-apply` returns summary and
`generated_at`. Add `format=csv&only=all|filled|not-filled` to download:

- `gpswiss-allegro-shipping-rate-after-apply.csv`
- `gpswiss-allegro-shipping-rate-filled.csv`
- `gpswiss-allegro-shipping-rate-not-filled.csv`

The 7318 saved audit result IDs define the scope. A LEFT JOIN reads current
`parts.sku`, `part_number`, `name` (CSV `title`) and
`allegro_shipping_rate_name`. Parts created later are excluded; a subsequently
deleted Part remains an auditable row with empty current data and `part_exists=0`.
Offer ID/status, provider shipping ID/name, original action and reason are explicitly
historical audit values. No current Allegro state is inferred or fetched.

Status rules:

| Evidence | backfill_status |
| --- | --- |
| ID in plan's applied IDs, current field nonblank | FILLED_BY_APPLY |
| NO_CHANGE / already_correct, current field nonblank | ALREADY_FILLED |
| NO_ACTIVE_OFFER | NOT_FILLED_NO_ACTIVE_OFFER |
| BLOCK_PROVIDER_READ with only failed 404 reads (including historical endpoint_error) | NOT_FILLED_PROVIDER_404 |
| BLOCK_CONFLICT | NOT_FILLED_CONFLICT |
| BLOCK_MISSING_MAPPING | NOT_FILLED_MISSING_MAPPING |
| Other/unconfirmed outcome, deleted Part or a previously filled field cleared later | NOT_FILLED_OTHER |

FILLED_BY_APPLY and ALREADY_FILLED describe confirmed fill provenance, not proof
that a later manually edited nonblank value still matches the audit. The extra
`applied_by_plan`, `part_exists`, `current_value_matches_audit` columns expose those
changes. Conflict/missing-mapping categories remain protected historical outcomes,
even if someone later manually fills their fields. Thus NOT_FILLED_* means not
successfully filled by this confirmed workflow; the live field column is always
shown, especially next to the Allegro name for conflicts.

Expected summary for the supplied production outcome (computed at request time,
not hardcoded): total=7318, filled_total=4896, filled_by_apply=4609,
already_filled=287, not_filled_total=2422, no_active_offer=2012, provider_404=159,
conflict=250, missing_mapping=1, other=0. Later live changes may change these counts.

Each summary/download materializes a fresh read-only transaction before sending
bytes. Summary and CSV are separate current snapshots and can differ if an operator
changes a product between requests. There is no write, technical logging, session
persistence, provider query or repeated APPLY. The two filtered files are disjoint
and together cover the full CSV for the same data state.

CSV uses UTF-8 BOM, semicolon delimiter, proper quote/newline escaping and an
apostrophe prefix for cells resembling spreadsheet formulas. All required columns
are included, with the three provenance columns above appended.

Tests: `AllegroShippingAfterApplyExportTest` covers all 7318 rows, category counts,
current fields versus deliberately stale frozen fields, later edits/deletions,
missing ledger, incomplete run, ownership/role, three CSV partitions, Unicode,
multiline titles, formula escaping, no SQL mutation, no HTTP and no jobs.
