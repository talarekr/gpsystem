# DATA ONLY from an existing browser snapshot

Use **Przygotuj pakiet danych bez zdjęć** in the existing migration-export panel.
The action is independent of the full export runner and becomes available after
the browser capture is sealed. It can run while photo inventory/copy is active.
No copied image, original image, photo manifest or finished full ZIP is required.
Deployment alone starts no packaging. No production run is executed by this PR.

The package is bound to the selected existing run and snapshot attempt. It reads
only `logical-snapshot/<attempt>/datasets/...` and existing run metadata. It never
starts another capture, attempt, generation, source verification, reconciliation
or relation audit, and never queries the live business database or providers.
Authentication/session/role reads retain the existing read-only route guards.

## Contents

Every dataset recorded in the frozen index is retained, including JSON and CSV
shards for parts, cars, relations, categories, storage locations, category mappings,
listings, Allegro selections, orders/items, shipments, stored payments/refunds and
all other captured datasets. Empty/unavailable optional datasets keep their index
descriptors; missing source tables are not queried or reconstructed.

`part_images`/`car_images` **data rows remain included**: they relate product/car IDs
to filenames and ordering for the separately transferred originals. No `images/`
files, `photo-manifest*`, `missing-originals*`, sample ZIP or Allegro supplement is
included. The Allegro parameter supplement stays a separate package.

Additional logical files:

* `fields.generated.json`
* `statuses.json`
* `sold-part-ids.csv`
* `parts-to-list-ids.csv`
* `relation-issues.jsonl`, if an existing report is available
* `migration-manifest.json`, the data-only projection of the stored metadata/index
* `data-only-package-manifest.json`
* `data-only-checksums.jsonl`

Matching existing field/status/manifest metadata is preserved when present.
Missing field/status metadata is derived from the frozen index and exporter
constants. Group CSVs are always derived from frozen Parts, because the parent
photo inventory may have written only a partial group list. Sold means
`status === sold`; to-list means `needs_listing`, not zero stock or missing offers.
The existing relation report is copied in bounded chunks as an observed prefix;
no further audit occurs. Its state at package start is retained.

Original dataset bytes, source IDs, JSON types and CSV escaping stay unchanged.
CSV is UTF-8 BOM, semicolon, nested JSON, `\N` null, escaped literal leading
backslashes and apostrophe-prefixed formula text. Import identifiers/codes as TEXT.

The manifests explicitly record:

```text
photos_included=false
photos_migrated_separately=true
photo_files_required=false
allegro_parameters_supplement_required=true
cutover_ready=false
business_database_reads=false
database_writes=false
provider_calls=false
marketplace_writes=false
automatic_import_allowed=false
automatic_publish=false
```

Operator-accepted drift, all recorded IDs, declared unknown count, consistency
contract, operator finalization and skipped source verification are inherited
from the checkpoint. They are not hardcoded from a screenshot. A checkpoint with
two declared changes and only one recorded ID retains one unknown; if both IDs
are actually recorded, neither is discarded. No stronger consistency guarantee
is introduced by re-packaging stored bytes.

## Private archives and recovery

Work is isolated in the private parent run's `data-only/<attempt>/` directory,
with its own lock, checkpoint, catalog, checksums and archive offsets. It never
changes parent state, image files, source shards or photo checkpoints. Dataset
sources are streamed directly from their existing paths, not copied to another
snapshot. Index construction and group derivation are bounded; ZIP ticks retain
the existing time/payload budgets, with up to 512 entries to avoid thousands of
tiny data-only volumes. The original full/sample packers retain their defaults.

Downloads use these names:

```text
data-only-package-manifest.json
data-only-archive-manifest.json
gpswiss-wystawczesc-data-only-<run_id>-part-XXXX.zip
```

Each volume has size, SHA-256 and a logical file/fragment list. Download **both
manifests and all volumes** privately. Small files use their logical paths; large
files may be fragmented. Follow each ZIP's `__gpswiss_volume_manifest.json` or
the archive manifest: concatenate fragment bytes by artifact/offset into the
logical file, then verify logical SHA-256. Do not concatenate the ZIP files.

Repeating the button resumes its existing checkpoint. A finished package enters
a bounded volume SHA check: valid ZIPs are reused; only missing/corrupt ZIPs are
rebuilt from verified descriptors and original artifact ranges. Fixed ZIP entry
timestamps allow exact reconstruction of the original checksum. Invalid source
bytes, changed parent boundaries or damaged descriptors block explicitly rather
than silently accepting different data or restarting the snapshot. Private
downloads also verify the requested artifact checksum.

The panel recovers transient requests with bounded backoff/status checks; reload
restores finished download links. The DATA ONLY loop never starts, pauses, stops
or resumes the full/photo loop. Closing the tab stops only future local requests;
clicking the button later continues the same data-only checkpoint.

Backend endpoint: `/admin/tools/gpswiss-migration-export/<run>/data-only` with
explicit `parent_attempt_id`. POST `action=start|tick`; GET status or `download=`
one returned artifact key. Owner-admin authorization, run ownership, CSRF and
private/no-store downloads apply. The package contains customer/order data;
never publish it or give it a public URL. No import/cutover is performed.
