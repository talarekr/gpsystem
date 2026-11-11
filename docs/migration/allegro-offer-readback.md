# Allegro existing-offer parameter readback and migration runner

This extends the category schema refresh from #1532. It does not execute any production operation. The current parent run/attempt, frozen capture, existing schema refresh (including completed 334-category plans), photos, DATA ONLY and other packages are not reset.

## Read contract audit

GPSYSTEM already reads full offers with `AllegroApiClient::getProductOffer()` and `productOffer()`, using **GET /sale/product-offers/{offerId}**, `Accept: application/vnd.allegro.public.v1+json`. `AllegroJarekImportService` tries that endpoint before the older `/sale/offers/{id}`. The migration selects the existing modern contract with no implicit fallback: one planned GET per unique approved offer ID. It never uses the ordinary API client's credential refresh, logger, or publishing methods.

Allegro's official [public product-offers announcement #5329](https://github.com/allegro/allegro-api/issues/5329) explicitly documents `getProductOffer`, the `productSet` array and the distinction between top-level offer `parameters` and `productSet[].product.parameters`. [Announcement #12557](https://github.com/allegro/allegro-api/issues/12557) also identifies that GET resource. These sources and the repository contract were checked; no production GET or full current live Swagger verification was performed.

There are four schema types to validate: dictionary, string, integer, float. Wire values have three forms: `valuesIds`, `values`, `rangeValue`; arrays/multiple choices, zero/range endpoints, units and unknown fields must survive. Offer vs product is taken from the actual response location, not guessed from an ID. Every product-set index and product ID is retained, including empty product parameter groups.

## A / B / C workflow

A. Existing explicit schema plan/confirmation/GET refresh remains unchanged. Merely opening the panel reads status; it does not repeat a completed refresh.

B. **Pobierz parametry istniejących ofert Allegro** prepares a local plan from checksummed frozen `marketplace_account_identities`, `parts`, `marketplace_listings`. It shows distinct linked Parts, unique offer IDs/expected GETs, invalid IDs, duplicate IDs, Parts with multiple offers, cross-Part offer associations, orphan exclusions and unsupported accounts. No source SQL scan or provider call is used to discover IDs.

Only `allegro` / `allegro_main` listings linked to an exported Part are eligible. A numeric external_offer_id or external_listing_id is used as stored. Two different valid ID fields are a conflict and excluded; URL/SKU/payload guessing is forbidden. An explicit frozen account identity must be `allegro_main`; other or unknown accounts are excluded. For old rows without an account ID, the existing single-account Allegro channel is explicitly recorded as `single_account_frozen_channel_default`. No other account credentials are tried.

The same offer linked twice is GET once. All frozen listing/Part associations are retained. Multiple offers for a Part are all preserved, not arbitrarily reduced to the active/latest one. Identical values coalesce for import, with all candidates retained. Different values cause CONFLICT. One offer linked to multiple Parts is retained as an explicit association conflict and is not considered an automatically usable value.

Confirming the exact plan digest starts one GET per bounded tick (2s connect / 6s total timeout, 4 MiB offer-response budget). Private data is written under `.allegro-offer-readback/<run>/<attempt>/plans/<plan-id>/`. It contains immutable plan/index, checkpoint and hash-protected receipts. One confirmed receipt is adopted after lost response/checkpoint without another GET. An in-flight GET not durably captured can be retried; transport exactly-once delivery is not claimed. An explicit account ID is also checked against the current allegro_main identity before GET. Plan/status/tick routes retain OwnerAdmin/run-owner, read-only SQL/session middleware, CSRF and private/no-store responses.

429, 5xx, connection failures and malformed responses have four bounded attempts, persisted backoff/Retry-After and the existing shared Allegro account cooldown. No server sleeps. 404 is terminal and reported. Expired/missing credentials and 401/403 block without any OAuth POST or credential save; reconnect outside the migration and Resume the same plan. Stop stops local scheduling; an in-flight request can still finish. Reload reads status and offers Resume rather than another Start. Explicit new plans preserve old files.

C. **Przygotuj pakiet parametrów Allegro** first previews both completed input plan IDs and parent fingerprint. It requires its own confirmation/digest, then uses only stored schemas/readbacks plus frozen Parts/Cars and SELECT-only local rules. No provider calls are made by C. Both plan IDs are pinned under selection locks; a concurrent change is rejected. A new private package directory `fresh-<schema-plan>/offers-<readback-plan>` prevents mixing with old packages. Parent run/attempt do not change. Legacy packages remain readable and explicitly lack live-offer readback.

## Export and resolution

The package includes CSV/JSONL for:

- allegro_category_parameters_cache;
- allegro_product_parameters_cache;
- allegro_existing_offer_parameters;
- allegro_existing_offer_readbacks;
- allegro_offer_readback_freshness;
- allegro_parameter_mappings;
- allegro_parameter_selections;
- allegro_resolved_parameters;
- allegro_parameter_completeness;
- allegro_parameter_conflicts;
- allegro_schema_freshness;
- allegro_parametry_czesci.

Existing `completeness`, `conflicts`, `schema_freshness` names remain as compatibility aliases. The manifest has contract_revision=3, both input plan identities, fetch-time consistency and source priority. No imported offer is approved for publication.

Offer receipts retain full sanitized decoded response, sanitized JSON text and its SHA-256, the original wire SHA-256 (hash only), endpoint, account, category and fetched_at. Unsanitized bytes, credentials, HTTP headers and error bodies are never saved/exported. All known and unknown safe offer/parameter fields remain in raw records. This is observation-at-fetch-time, not the historical state at parent cutoff; a category changed since A is reported as unknown if no matching fresh schema exists.

Normalized existing-offer rows retain Part/offer/category IDs, scope, product-set index/product ID, values, value IDs, range, unit, source/fetched_at and frozen association. Parameter names come from the appropriate fresh schema when available. Unknown parameter IDs are retained as `historical_schema_mismatch` rather than dropped.

Resolution priority is existing_allegro_offer, manual_selection, local_mapping, derived_from_part, derived_from_car, unresolved. Frozen legacy payloads remain historical candidates. Priority only chooses the preferred identical/nonconflicting candidate; it never overwrites disagreements. Conflict reports preserve every value/source/offer. Offer and product scopes are distinct. Product requirements are checked for each captured product slot; missing values in another bundle slot cannot be hidden by slot zero.

Per-Part/category completeness retains canonical vs historical context and adds product_required_resolved_count, existing_offer_parameter_count, resolved_from_existing_offer_count, conflict_count and missing_product_slots. Required counts/statuses use fresh definitions; unknown categories/failed readbacks or unevaluated conditional rules cannot become COMPLETE. Missing values are not invented. Builder business defaults remain suppressed in migration-only derivation.

`allegro_parametry_czesci.csv` is a directly downloadable file as well as a ZIP entry. One row corresponds to a Part/category/scope/parameter/product slot. For conflict, the import value columns are NULL, source=unresolved and status=CONFLICT, with every alternative and provenance in `candidates` JSON. Nonconflicting values retain arrays/ranges and IDs as text. `is_preferred` is a local merge choice, never publishing authorization. UTF-8 BOM, semicolon, JSON nested cells, NULL=\N, literal leading backslash doubled and spreadsheet formula escaping match the existing export contract.

## Runner presentation

The same panel now has a responsive runner header and eight task cards: capture, DATA ONLY, consolidated CSV, category definitions, existing-offer parameters, supplement, photos, finalization. Start/Stop/Resume are consistent; the header acts on the selected task. Independent local loops remain independent. Status restore uses backend GETs, never a new snapshot, boundary or provider Start. A backend checkpoint without an active local loop is labelled PAUSED with Resume; completed schema refresh stays COMPLETED.

The main view shows counts/progress, short errors, next action and private downloads. Category details are opt-in with ID search/filter and 25 rows/page. ZIP volume lists are closed by default and page at 25 items. CSV shows name/row count/bytes/download; SHA is inside file details. Full status/plans/network evidence are held in memory and available in a closed diagnostics panel or local JSON download. Visible diagnostic previews are capped; no entire category/receipt/manifest array is automatically rendered. The visible event list is capped at 20; durable checkpoints, receipts and reports are not removed. No public download endpoint is added.
