# GPSwiss migration: explicit Allegro schema GET refresh

The existing panel has two independent steps:

1. **A. Odśwież definicje parametrów z Allegro**: prepare a local plan; review category IDs, source evidence, existing SQL cache presence/fetched_at, refresh_required and expected GET count; check the confirmation and click Run. Prepare never contacts Allegro.
2. **B. Przygotuj pakiet parametrów Allegro**: create the supplemental package using the completed private refresh cache. HTTP start now requires step A. Read/download of existing legacy packages is retained and labelled `legacy_local_only`.

No production refresh, packaging or deploy is performed by the PR. Browser ticks require no hosting shell, queue or scheduler. Browser POST actions are requests to GPSYSTEM; the only outgoing provider method in the new client is **GET**.

## Audit of category selection

Live GPSYSTEM `AllegroCategoryResolver` first accepts an explicit form selection (not part of a frozen migration), then `review_metadata.marketplace_category_overrides.allegro.external_category_id`, then a non-empty mapping for the Part's local `category_id`. Channels are `allegro_main` first, then `allegro`, with the lowest local mapping ID. A local Part `category_id` is never used as an Allegro category ID. `MarketplaceListingReadinessService` uses the same override/resolver; the publisher subsequently checks category consistency.

The old supplement separately preserves categories attached to frozen manual selections and historical parameter-bearing structures in Part legacy/review metadata and Allegro listing raw payloads. Its canonical mapping fallback is independent of history, so a Part may have one current category and several historical parameter contexts. That distinction remains visible; no arbitrary category overwrites another.

The plan reads only checksummed frozen parent shards: `parts`, `marketplace_category_mappings`, `allegro_parameter_selections`, `marketplace_listings`. It indexes exported Part IDs and used local categories, picks the canonical Allegro mapping only for Parts without an override, and includes explicit Allegro category IDs and recognised historical parameter contexts. It includes an Allegro listing's stored `category.id` even if its parameter list is empty. Only Allegro/allegro_main listings attached to an exported Part enter the GET plan; orphans are counted/excluded and remain historical in the supplement. Manual selections must also belong to exported Parts. Unknown/invalid IDs are counted, not used to construct a URL. There is no category-tree endpoint or live Part/Listing/Car SQL scan.

Standalone SQL cache entries and unused category mappings never expand the GET scope. The preview queries only local cache metadata by selected category, not the whole Allegro tree. All planned categories require a fresh GET regardless of the old SQL fetched_at. Preview dates are local observations, not a claim of an as-of historical snapshot.

Synthetic fixture: 3 exported Parts, including one without a category. Used categories are **100, 200, 300, 400** = **4 categories / 8 initial GETs**. Mapping-only 500 (hidden by override), fallback 299, unused mapping 999, cache-only 777, orphan listing 888 and eBay category 666 are not fetched. Cache has 100/777; 100 shows its old timestamp in preview. Production cardinalities cannot be established from repository code; the actual plan displays them after scanning the existing frozen run.

## Why two endpoints

The current `AllegroCategoryParametersService` GETs only `/sale/categories/{categoryId}/parameters`. `AllegroOfferParametersBuilder` separates offer parameters from `productSet[0].product.parameters` using `options.describesProduct`; it reads `requiredForProduct` from the options/top-level field. The publisher uses both payload locations in `/sale/product-offers`.

For the migration we capture both **GET /sale/categories/{categoryId}/parameters** and **GET /sale/categories/{categoryId}/product-parameters**. The second is a separately identified product definition/requirement source, not assumed identical to the first. Both resources are confirmed by Allegro's official API material, e.g. [Allegro API announcement #3928](https://github.com/allegro/allegro-api/issues/3928), linking their documented operations (`getFlatParametersUsingGET_2`, `getFlatProductParametersUsingGET`). The full live Swagger download was unavailable under this execution environment's network policy; this is not a claim that production responses were verified. Endpoint failure, including 404, is reported, never silently replaced by SQL cache.

The prior SQL cache and supplement already preserve the full sanitized **raw_response**. They do not lose unit/options/dependencies from that raw field. The old derived `parameters` projection selected ID/name/type/dictionary, required/requiredForProduct/describesProduct/multipleChoices/restrictions, omitting `unit`, full `options`, dependencies and unknown fields from that *projection*. The old package had no fresh GET capture, no separate product schema, no endpoint/fetch freshness proof and no expanded product-required counts. New caches retain the entire decoded response **and byte-exact raw_response_text**, with category_id, endpoint/source, fetched_at and SHA-256. The derived parameters view also keeps full definition objects. Error bodies, headers and credentials are never exported.

## Checkpoints, retries and generations

Refresh data is private under `.allegro-schema-refresh/<run>/<attempt>/plans/<plan-id>/`; current.json selects the active plan. A hash of immutable plan-definition.json binds the preview, parent identity, exact categories and two endpoints. Run, parent attempt and frozen parent SHA remain unchanged. Plan discovery handles at most 100 rows/25 frozen shards in a 1.5-second loop; local cache preview uses five categories per tick. Public status contains IDs/counters/safe reasons, not raw API response bodies.

Run requires the matching digest and explicit operator confirmation. Every provider tick performs at most **one GET**, with a 2-second connection/6-second total timeout, accepted production/sandbox hosts only, no redirects and a fixed endpoint whitelist. Existing `allegro_main` access credentials are read in memory. Expired/missing tokens or 401/403 block the plan. No token refresh, OAuth POST, application-token fallback, integration logger writes or account save occurs. Reconnect the normal account separately, then Resume this plan.

A result envelope is durably saved before advancing the checkpoint. A successful/terminal saved result is adopted after lost response/checkpoint without another GET. An HTTP request whose result was never durably captured can be repeated; GET is safe but transport-level exactly-once delivery is not claimed. 429/5xx/network/malformed schema use up to four attempts per endpoint, persisted backoff and Retry-After. No backend sleeps. A shared private cooldown prevents subsequent categories/plans bypassing observed Retry-After. Permanent errors (e.g. 404) are confirmed as missing. Browser Stop stops only local scheduling; in-flight work may commit. Resume preserves confirmed endpoints. Invalid receipt/plan hashes are blockers.

After a completed refresh, **Przygotuj nowy plan odświeżenia** explicitly creates another private plan, followed by another preview/confirmation. It preserves every previous generation and its receipt files. Merely reloading or resuming does not create a plan or repeat completed GETs. Progress and successes count categories (a success needs both endpoints); not_found_404 counts endpoint outcomes, provider_429 counts rate-limit attempts, and missing_schemas counts categories with any missing endpoint. Actual performed_gets is separate from expected_gets and includes retries; authentication checks without an outgoing request are not counted as GETs.

## Fresh supplement and completeness

The new private generation is `.allegro-parameters-supplements/<run>/<attempt>/fresh-<plan-id>/`. Old parent files, legacy supplemental packages and other fresh generations remain intact. No global SQL cache is updated: only the migration's private cache is refreshed. That cache is the explicit input to step B, preventing unrelated GPSYSTEM publishing/sync side effects.

CSV/JSONL datasets now include:

- allegro_category_parameters_cache (full `/parameters` response);
- allegro_product_parameters_cache (full `/product-parameters` response);
- allegro_parameter_mappings (existing local rules, SELECT-only capture);
- allegro_parameter_selections (full frozen selections);
- allegro_resolved_parameters;
- conflicts, completeness and schema_freshness.

Every planned category/endpoint has a schema_freshness row including success/failure, HTTP status, fetched_at, attempts, schema SHA and refresh plan ID. Missing schemas have explicit rows but no fabricated schema/cache response. Manifest contract_revision=2 identifies the expanded fresh contract and records per-endpoint observation times/parent consistency exceptions. Existing v1 format remains discoverable and the supplied schema.json defines the expanded record fields. Freshness is at fetch time, not a permanent guarantee or transactional snapshot across categories.

Resolved rows retain the existing source/source_field/listing/account provenance, plus **provenance**: manual_selection, existing_listing, local_mapping, derived_from_part, derived_from_car, category_schema, unresolved. Raw legacy/review provenance remains in source; existing_listing in the normalised field is not permission to publish. schema_fetched_at and schema_source_endpoint identify the relevant refreshed scope. The fresh projection suppresses builder fixed-business defaults and auto-injected category defaults (e.g. assuming condition/quality/version without saved input); these become unresolved markers. Normal GPSYSTEM publisher behaviour is unchanged. Category-schema coverage markers carry empty values; unresolved values are never guessed.

Completeness is assessed per Part/category, with canonical_allegro_category_id and category_context=canonical/historical/unresolved so historical payload categories cannot overwrite the Part's current migration category. A Part without a canonical category also gets an explicit unresolved report. The same full fields are present in CSV and JSONL, with required_parameter_count, required_resolved_count, missing_required_parameter_ids/names, product_required_count, product_required_missing and missing_product_required_parameter_ids/names, plus uppercase **COMPLETE / PARTIAL / SCHEMA_UNKNOWN / CONFLICT**. Offer-required comes from `/parameters.required`; product-required combines explicit requiredForProduct flags and `/product-parameters` required flags. Product values must resolve in product scope; dictionary IDs, multiplicity and basic scalar/range restrictions are checked. Historic-only values do not silently become selected publish values. Conflicting candidates remain conflicts. Missing category/endpoint means unknown counts (NULL), not zero. Conditional required/dependency rules that are not evaluated prevent declaring COMPLETE. These are local migration checks, not full provider publication validation; automatic publish/import and valid_for_republication approval remain disabled/unknown.

The refresh endpoint and supplement retain OwnerAdmin/run owner checks and private/no-store responses. No writes to Parts, listings, orders, stock, mappings, account credentials, global caches or provider resources occur. Normal publication, prepare-listing services, stock sync and the main photo/DATA ONLY pipelines are never invoked. The pure buildFromSnapshot parameter builder is used only for derivation without SQL/log writes.

The panel additionally shows per-Part/category uppercase completeness counters from the generated reports; the original lowercase summary remains a parameter-candidate counter. Status dataset_counts exposes actual captured cache/mapping row counts before the final manifest is ready.

HTTP operations and downloads can carry fresh_plan_id; a private selection lock and the expected plan ID prevent a concurrent new plan from switching the supplement generation in mid-operation. Parent run/attempt remain unchanged.
