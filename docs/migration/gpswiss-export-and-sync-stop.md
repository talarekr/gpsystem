# GPSwiss → Wystawczesc: export contract and separately authorized synchronization stop

The tool creates real packages only when run on the real source instance. It has not
been run on production as part of this PR. It does not create synthetic products,
change source data or upload/import anything. There is no provider publication tool
in this change: existing offer identities and URLs are evidence, not permission to
publish or adopt ownership of an offer.

## Operator export

Open `/admin/tools/gpswiss-migration-export` as OwnerAdmin and click **Przygotuj
próbkę i pełny eksport**. The hash stores the export UUID. Resume the same UUID
following reload/network loss. Pausing the local loop does not reset the snapshot.
For large archives use the CLI, avoiding HTTP/proxy time limits:

```
php artisan gps:migration-export --owner-id=YOUR_LOCAL_OWNERADMIN_ID
php artisan gps:migration-export --owner-id=YOUR_LOCAL_OWNERADMIN_ID --run=EXISTING_EXPORT_UUID
```

No credentials are accepted in URLs or command arguments. The CLI's default owner
0 creates a CLI-only export; use the actual local operator ID for panel downloads.
The private directory is `storage/app/private/migration-exports/{UUID}` (0700,
files 0600), not public storage. Protect disk space, restrict backup access and
remove obsolete export directories through the normal approved retention procedure.
Do not expose the directory through a public symlink. No automatic deletion exists.
PHP ZipArchive/fileinfo and sufficient free space are required. Individual images
above 100 MiB are reported as blocked, not silently omitted. The normal source
files are only read; reports/working files/archives are new independent files.

Both `sample.zip` and `full.zip` come from the SAME source database snapshot. The
sample selects up to five distinct real IDs covering multiple photos, missing or
blocked originals, sold/archived status, saved Allegro parameters and part position,
then fills remaining slots deterministically. If fewer than five source products
exist, the actual smaller count is explicit. Native SKU variants are not invented.
The full package covers every Part, including archived/sold records. Dependencies
include only referenced cars/locations and referenced categories plus ancestors.
Missing references are listed in `dependency-issues.json`.

Each package contains products JSON and CSV, categories/cars/storage-locations JSON,
photos JSON and CSV, original image bytes, missing/blocked original report, schema,
manifest, and this procedure. Manifest hashes cover metadata files; photos carry
individual SHA-256. Archive SHA-256 is reported outside the ZIP in export status.
CSV uses UTF-8 BOM and semicolons; complex arrays are JSON cells. JSON is canonical
for import; CSV formula-like cells are apostrophe-escaped.

Product fields preserve source `id`/`sku`, main code `part_number`, additional codes,
OEM/manufacturer codes, category/name, quality `condition_notes`, position from the
same metadata used by the editor, warehouse/name, descriptions, separate local and
Ovoko donor-car IDs, dimensions, shop/Allegro/Ovoko/eBay prices and currency, Kurier
Allegro, saved manual Allegro parameters, locally saved Allegro offer parameters and
existing offer links/identities. Original filenames are retained in photos metadata.
ZIP image names use main code + source Part ID + image ID to prevent collisions;
source files are never renamed. `code_photo_path` is a separate `code_photo` role,
not automatically a trading-gallery photo. No thumbnail substitutes for an original.

Only allowlisted product/dependency fields are serialized. No users, orders,
customers, payment data, VIN, registration, seller details, purchase invoices,
integration account table, tokens, credentials or raw legacy/provider JSON are in
packages. E-mail and credential-like text is redacted. Offer links keep only an
approved provider host/path (no credentials/query/fragment). Arbitrary private data
embedded in otherwise public descriptions still requires operator review before
handing packages to a third party; no exporter can infer the meaning of all free
text. Do not use the packages to copy marketplace accounts. Native EAN/VAT are not
invented; they remain null with a warning. Provider timestamps/parameters are local
historical evidence, not a fresh API response.

## Snapshot and files: what is and is not proven

MySQL capture requires InnoDB for every captured table and uses one REPEATABLE READ,
READ ONLY transaction. SQLite uses one read transaction for tests/local snapshots.
No snapshots are assembled from multiple independent product reads across HTTP
requests. All subsequent ticks operate only on frozen private export artifacts.

The database transaction cannot make the source filesystem atomic. Original file
fingerprints are captured after the DB snapshot. Each copy checks source metadata,
allowed-root containment, mirror hashes, copy length, SHA-256 and image MIME.
Changed/missing/remote/unreadable/conflicting originals are reported, not downloaded,
repaired, deleted or replaced. No derived image generation is invoked. A confirmed
copy is an independent copy of the bytes read at that step; this is explicitly not
proof of a globally frozen filesystem at the DB transaction instant.

Therefore every package has `cutover_ready=false` and `automatic_import_allowed=false`.
Before a final cutover, the operator must coordinate an actual application write
freeze or DB+filesystem/storage snapshot, then export from that coherent source.
A manifest with failed/missing dependencies or originals requires a decision, not
an import that silently skips data. Exporting once from a live source is useful for
review and a five-product trial, but does not establish a final synchronized cutover.

## Import/publication boundary

There is no importer or sync/publication action in this exporter. The destination
importer must independently require the chosen tenant UUID, ID/SKU/category collision
plan, explicit confirmation and a deny-by-default publication/synchronization policy.
Preserve original ID/SKU as provenance and a durable mapping; do not overwrite a target
record with the same numeric ID/SKU. Source status/visibility is exported as evidence;
it must NOT automatically enable destination visibility or marketplace publishing.
Create destination records with no publish/auto-sync, no credentials and no active
marketplace jobs. Resolve current taxonomy/shipping-account mappings separately.
Never reuse `Part::save`/`PartImage::save` blindly: source model hooks can derive prices,
queue translation and generate image files. Re-enabling sales or changing offers needs
a separate operator-approved step. GPSwiss keeps its products and photos independently.

## Proposed synchronization-stop procedure — NOT executed by this tool

1. Record the actual deployment inventory: active source DB/instance, cron/systemd
   timers, Supervisor/Horizon/queue workers, queue backends/names, external scheduler,
   HTTP runners, application processes and all three channel accounts. Inspect
   provider notification registrations with the existing authorized operations tools;
   do not export tokens or customer/event payloads into this package.
2. Declare one stock/order authority during cutover and a maintenance window. If
   source offers continue selling while local sync is stopped, new orders can still
   arrive and quantity can diverge. Either maintain a controlled authority/delta feed
   or separately authorize provider-side sales pause; this exporter does neither.
3. Stop new outbound producers before stopping consumers: block manual bulk publish,
   price/stock updates, browser runners and deployment automation. Source code has
   `marketplace:auto-sync-orders` every five minutes, gated by
   `GPS_MARKETPLACE_ORDER_SYNC_ENABLED`; it also schedules
   `allegro:refresh-pending-listings --limit=50 --older-than-minutes=2` every five
   minutes WITHOUT that gate. Turning off the single flag is insufficient. Disable
   actual cron/schedule:work triggers under the operator's change procedure, including
   any external jobs absent from this repo. Keep unrelated infrastructure running.
4. Inventory source jobs using metadata, not full payload dumps. Known jobs include
   `RefreshAllegroListingStatusAfterPublish`, `ProcessOvokoStockSyncRunBatch` and
   `SyncOvokoCarModelsBatchJob`; source queue config supports database, Redis, sync
   and a dedicated storefront-fr-translations queue. Confirm actual production values.
   Gracefully drain authorized in-flight work and stop relevant workers. Do not
   delete/flush queues, retry failed jobs, run pending jobs blindly or rely solely on
   `queue:restart` (Supervisor may start them again). Synchronous jobs and HTTP actions
   remain possible even if background workers are stopped.
5. Webhooks require their own boundary. The reviewed source routes include payment
   callbacks but no dedicated Allegro/eBay/Ovoko webhook route was identified. This
   does NOT prove absence of provider subscriptions, proxy handlers or another app.
   Inventory them at ingress/provider configuration. During freeze retain required
   inbound events in an access-controlled durable backlog, deduplicate by event ID
   and suppress handlers that mutate catalog/stock. Do not acknowledge-and-drop events
   or block payment/order channels indiscriminately. Preserve customer data outside
   the migration package. Route/registration changes need separate approval.
6. Verify quiescence: no active relevant worker/job/request, no new scheduled dispatch,
   no retry timer, no browser runner and no event handler writing stock/catalog. A
   feature flag or empty queue alone is not proof. Take aligned DB/file snapshots;
   run this export from them. Record counts/hashes and reconcile final stock deltas
   without transferring customer/order payloads in the product package.
7. Import the five-product sample into the chosen tenant in isolated draft/no-sync
   mode. Verify codes, all prices/currency, car IDs, categories/warehouse, descriptions,
   parameters, links, photo names/order/primary/SHA. Then separately approve the full
   import. Keep source data and files intact. The destination must not adopt active
   mappings or publish offers merely because URL/offer IDs were included.
8. Resume under a separately approved plan: designate one marketplace writer per
   offer/account, reconcile events/order-derived stock deltas in their original
   systems, resume selected queues/timers and observe. Never run both source and
   destination automatic writers against the same offers simultaneously. A rollback
   restores the chosen authority and event processing; it must not erase source data
   or replay customer/order events into the product-only import.

The tool does not execute any of these stop/resume actions or modify environment,
queues, cron, accounts, notifications, products, listings or original files.
