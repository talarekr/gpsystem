# Allegro parameter supplement v1 — legacy local-only contract

Current panel flow first requires the explicit GET-only refresh described in [allegro-schema-refresh.md](allegro-schema-refresh.md). The original local-only behaviour below documents legacy packages that remain readable; it is not the current HTTP start contract. Fresh packages have contract_revision=2.

This is an independent, private package: `gpswiss-allegro-parameters-supplement-v1`.
It does not extend or restart the parent capture, source verification, relations,
photos or packaging. The existing migration panel exposes its independent actions.
There are no queues, CLI requirements, provider requests, credential refreshes,
business writes or imports.

The parent run and attempt must be supplied explicitly. Owner/admin authorization,
run ownership, the source fingerprint and the parent attempt are checked on every
request. The logical parent capture must already be sealed. Accepted parent drift
and skipped source verification remain applicable; this supplement cannot undo them.
The parent's files are opened read-only. Supplement files are stored outside the
parent run directory, under the private export root:
`.allegro-parameters-supplements/<parent-run>/<parent-attempt>/` (0700/0600).

## Data and consistency

The package contains UTF-8 JSONL and CSV for these datasets:

* `allegro_category_parameters_cache`: every stored, non-secret table column,
  full sanitized `raw_response`, `fetched_at`, content-derived `schema_snapshot_id`,
  `schema_status`, `source_observed_at`, and normalized `parameters` containing
  ID, name, type, dictionary, nullable required/requiredForProduct/describesProduct/
  multipleChoices and restrictions. The raw response retains other schema fields.
* `allegro_parameter_mappings`: every non-secret table column, including disabled
  rules, source fields, constants and dictionary mappings in metadata. Derived
  `source_type`, `constant_value` and `dictionary_value_mapping` make their stored
  meaning explicit without dropping the original fields. No rules
  are silently invented from the table name; the original fields are authoritative.
* `allegro_resolved_parameters`: separate provenance candidates for each
  Part/category/parameter and product-set slot. These are not executable publish
  instructions. This dataset also retains unknown/obsolete historical parameters.
* `conflicts`: conflicting candidates with references, sources and values.
* `completeness`: one assessment per Part/category, including missing required IDs.

Additional files: `schema.json`, `summary.json`, `manifest.json`, `checksums.json` and independent
ZIP volumes with `archive-manifest.json`. JSONL is the lossless source format.
CSV uses UTF-8 BOM, semicolon, JSON for arrays/objects and `\N` for null. Literal
leading backslashes are doubled and formula-leading text is prefixed with an
apostrophe. Import identifier columns as TEXT, retaining leading zeroes. Boolean
false and numeric/string zero are not null.

No Part, Car, order, listing or image dataset is recaptured from the live DB or
included a second time. Frozen parent shards are read with checksum and row-count
validation; referenced Cars are looked up in those shards. Temporary private
indexes contain only IDs, category names/mappings, manual selections and extracted
parameter candidates, not copies of product/listing/customer payloads. Recognized
parameter-bearing structures are traversed; unrelated payload sections are not.

Only the two missing local tables are read from SQL. Their max IDs are frozen at
supplement start. Each small page is frozen in a private journal before derivation;
a retry uses that page, even if the live cache/rules subsequently change. Capture
of these later local tables is **not** a transaction spanning HTTP requests, nor a
historical reconstruction at the parent cutoff. New IDs above the observed max
are excluded; rows changed before their page is read are observed in their later
version; deleted rows may be absent. Boundaries, availability, capture intervals
and this limitation are recorded in the manifest. Missing tables are reported as
unavailable, with empty datasets; cache misses never trigger a provider call.

`captured_at` is the stable supplement-start timestamp. `source_observed_at` uses
the stored row version timestamp where available, otherwise the local capture
observation where known, otherwise null. It is not a provider freshness guarantee.

## Resolved contract

All IDs are strings. Unknown scalar information is null, never inferred false.

```text
part_id, allegro_category_id, parameter_id, parameter_name_snapshot,
parameter_type, multiple_choices, parameter_scope, product_set_index,
values_ids[], values[], range_value, raw_value,
required_snapshot, required_for_product_snapshot, describes_product_snapshot,
schema_snapshot_id, source, source_field, source_listing_id,
marketplace_account_id, captured_at, source_observed_at,
completeness_status, reason_code, local_validation_status, resolution_role,
candidate_id, conflict_id, valid_for_republication
```

`parameter_scope` is `offer`, `product` or null; product-set indexes remain distinct.
Values from manual choices are grouped without losing multi-value selections.
Sources include `manual_selection`, `computed_from_part`, `computed_from_car`,
`parameter_mapping`, `legacy_offer_payload`, `marketplace_listing_payload`,
`review_metadata`, and `unknown`. Category-less historical data stays category-less;
the current Part mapping does not prove an old listing's category. ID-only publish
summaries are retained with unavailable values. A Part without identifiable values
gets a null-parameter coverage row, so it does not silently disappear.

The existing builder is evaluated with explicitly supplied frozen selections,
rules and preloaded Car/category relations. Its export-only entry point suppresses
integration logging and never queries live models or category APIs. Normal builder
callers keep their existing behavior. Effective builder results are retained next
to historical candidates, rather than replacing them. `resolution_role` distinguishes
stored evidence from builder results (or a shared row when their values agree).
When the builder accepts only a subset of saved IDs, both versions are retained
and their disagreement is reported. All manual values survive,
including IDs rejected by the locally stored dictionary.

Conflict comparison is by Part/category/parameter/product-set slot, conservatively
including disagreements between offer/product scopes. Nonempty different value
sets produce a conflict; multi-value ordering does not. Different product-set
slots are not conflated. No source wins by overwrite. `candidate_id` identifies
the preserved candidate; `conflict_id` links the complete group.

Completeness is local, with these row statuses:

* `complete`: a non-historical candidate has a locally available definition and
  a value; dictionary IDs are allowed by that snapshot and single-choice limits
  are respected. This is not a full Allegro restrictions/readiness validation.
* `partial`: a local value is missing/invalid, or only a coverage marker exists.
* `historical_only`: historical evidence or a parameter absent from the local schema.
* `schema_unknown`: category/schema is unavailable locally; reason is
  `schema_unavailable_locally`. Invalid cache bodies are preserved but not used.
* `conflict`: the candidate belongs to a disagreeing group; original source and
  local validation evidence remain available.

`valid_for_republication` is always null: no publication authorization or current
provider validation is implied, even for complete rows. `required_snapshot` and
`required_for_product_snapshot` stay separate. Missing-required counts cover known
required/requiredForProduct parameters lacking a valid non-historical local value.
A conflict is counted separately, not as a missing value if a valid local value
exists. Optional missing values do not enter required counts.

Summary counts include distinct parameter IDs/categories, Parts with manual
selections, candidate rows, conflicts, historical-only parameter groups, categories
without usable schema, missing required values, orphan-listing candidate rows,
and candidate-row completeness counts. Candidate rows include null-parameter
coverage markers; completeness report rows are Part/category assessments. These
are different units. No counters are guessed from a previous production run.

## Panel-hosted operation without frontend changes

Endpoint (existing authenticated panel session):

```text
/admin/tools/gpswiss-migration-export/<run>/allegro-parameters-supplement
```

* POST `{parent_attempt_id, action:"start"}` initializes or returns the same package.
* POST `{parent_attempt_id, action:"tick"}` executes one bounded unit.
* GET `?parent_attempt_id=<attempt>` returns compact progress and summary.
* GET `?parent_attempt_id=<attempt>&download=manifest|summary|checksums|archive-manifest`
  downloads private metadata after completion.
* GET with `download=volume-NNNNNN` downloads a volume listed in the archive manifest.

POSTs require CSRF and owner/admin access. These endpoints do not invoke any
parent start/resume/retry/tick action. The operator must supply the existing run
and attempt, not request another main export. Deployment alone starts nothing.

For a hosting account without shell, the following optional operator snippet can
be run in the console of the already authenticated GPSYSTEM panel **after deploy**.
It is documentation only; no production execution is performed by this PR.

```javascript
const parentRun = prompt('Existing parent run UUID');
const parentAttempt = prompt('Existing parent attempt UUID');
if (!parentRun || !parentAttempt) throw new Error('Existing identity required');
const endpoint = `/admin/tools/gpswiss-migration-export/${encodeURIComponent(parentRun)}/allegro-parameters-supplement`;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
if (!csrf) throw new Error('Panel CSRF token unavailable');
const call = async action => {
  const response = await fetch(action ? endpoint : `${endpoint}?parent_attempt_id=${encodeURIComponent(parentAttempt)}`, {
    method: action ? 'POST' : 'GET', credentials: 'same-origin',
    headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
    ...(action ? {body: JSON.stringify({parent_attempt_id: parentAttempt, action})} : {}),
    signal: AbortSignal.timeout(30000)
  });
  const result = await response.json();
  if (!response.ok) throw Object.assign(new Error(result.reason_code || `HTTP_${response.status}`), {status: response.status});
  return result;
};
let state = await call('start');
let failures = 0;
while (!state.done) {
  try {
    state = await call('tick');
    failures = 0;
    console.log(state.phase, state.shard, state.row, state.summary);
    await new Promise(resolve => setTimeout(resolve, 100));
  } catch (error) {
    if ((error.status && error.status < 500 && error.message !== 'supplement_busy') || ++failures > 4) throw error;
    await new Promise(resolve => setTimeout(resolve, 1000 * 2 ** failures));
    try { state = await call(); } catch { /* Retry tick from its committed checkpoint. */ }
  }
}
console.log('Supplement ready:', `${endpoint}?parent_attempt_id=${encodeURIComponent(parentAttempt)}&download=archive-manifest`);
```

Reload/stop only ends the local request loop. Repeating start with the same parent
identity resumes the same supplement; completed packages are immutable and do not
refresh local tables. Concurrent ticks are fenced by a supplement-only flock.
Append files roll back to committed byte offsets after interrupted ticks; frozen
input pages and deterministic candidate IDs prevent duplication. Archive volumes
use their own resumable checkpoint. A parent boundary/attempt/source change,
invalid source shard or corrupted private index blocks instead of restarting.

Download **all** volumes plus the archive manifest. The volume manifest describes
reassembly of fragmented logical files; verify fragment, volume and final dataset
SHA-256 checksums before any future import. Work journals and parent indexes are
not included in the downloadable package. Customer/order data and credentials are
not selected. WystawCzesc import and re-publication remain separate future work.


## Existing panel actions

In `/admin/tools/gpswiss-migration-export`, the section **Parametry Allegro — pakiet uzupełniający** uses the currently selected parent run and `parent_attempt_id`. It is available after record capture is sealed, even while photos or DATA ONLY are unfinished. It never starts a new parent snapshot.

- **Przygotuj pakiet parametrów Allegro** calls the existing `start` once, then bounded `tick` requests. Existing `start` is idempotent.
- **Wznów supplement** reads server status and continues `tick` from the supplement checkpoint. It does not call the parent Resume or restart the supplement.
- **Stop lokalnej pętli supplementu** cancels only its own scheduling/backoff. An in-flight bounded request can commit before the loop stops. The parent/photo/DATA ONLY/CSV loops are unaffected.
- **Odśwież status supplementu** performs GET only. Reload also performs GET only and restores completed private downloads. Pending supplements require an explicit Prepare/Resume click.

The UI shows the real backend phase/shard/row and summary counters: resolved parameters, complete/partial/historical_only/schema_unknown/conflict, conflict report rows and missing required values. Completeness counters are parameter candidate counts as defined by the backend, not inferred product readiness. Cache/schema and mapping **row counts** are read from the completed manifest because in-progress status does not expose these counts; `—` means unavailable, never zero. These are local-cache records, not a provider freshness check.

On completion the panel fetches the existing authenticated `manifest` and `archive-manifest` JSON endpoints. It lists manifest, checksums, summary, archive manifest and **every volume returned by the archive manifest**. The five dataset CSV/JSONL pairs (cache, mappings, resolved, conflicts, completeness) are inside the ZIP volumes; the current backend does not offer individual dataset download keys. No new backend endpoints or download permissions are introduced. Volume links use only validated `volume-NNNNNN` keys and the private endpoint, never a storage path or external URL. All parent IDs are checked before applying a response; the backend remains responsible for OwnerAdmin/run owner/private-no-store enforcement.

Transient failures use at most four local retries with short backoff and a status poll. A committed step whose response was lost is read from the checkpoint; it is not recaptured. Manual Resume starts a fresh local retry budget. Missing/expired authorization, identity mismatch and malformed archive metadata are shown as safe reason codes. Failure to read a completed package's file list requires GET refresh, never another packaging tick.

Validation uses real Chromium with all requests intercepted: start/resume/stop, double click, reload, successful lost-response recovery, 503, auth failures, parent identity mismatch, private links including a 74-volume fixture, rejected invalid volume keys, real-contract summary/manifest counters and an independently running photo loop. These fixture counters are not production counts. No production generation or deploy is part of this change.
