# Operator finalization: completed checkpoint handoff

## Production evidence and diagnosis

The reported run is `8de6e27c-ad7c-470b-830b-b9ecee9446ba`, attempt
`9cb33c92-3f1e-4db0-b08d-052715aa5f4c`. The supplied status proves that
the canonical snapshot checkpoint already reached `completed`, with
`artifact_integrity_complete=true`, `dataset_index=39`, `shard=0`, and
`artifacts_checked=15629`. Record capture is 79957/79957; source verification
remains 2155. The root run still says `snapshot_operator_finalize`.

This places the failure **after durable artifact completion, before the root
handoff to `relation_audit`**. The healthy historical `last_tick` does not describe
the failed HTTP response. The supplied status contains no fatal exception class,
so it cannot establish the exact production PHP fatal or hosting memory limit.

The old `BrowserMigrationSnapshot::tick()` decoded the checkpoint, then called
`index()` while that decoded graph was still alive. This happened both after a
successful tick and on retry of an already completed checkpoint. Completion also
materialized a large legacy `snapshot.json` mirror before publishing the root
handoff, outside the tick's recorded success boundary. The supplied checkpoint
was 12076656 bytes. A local fixture with 39 datasets and 15629 shard descriptors,
bootstrapped through Laravel, reproduced memory exhaustion at a 128 MiB limit in
the redundant decode. The fixed full handoff completed at about 103 MiB peak in
the same local probe. This reproduction identifies a concrete failure path;
it does not substitute for an unavailable production fatal log.

## Resume behavior

For this accepted operator run, the engine now returns a bounded projection of
the checkpoint it already has in memory. A completed checkpoint is not rewritten
or reverified. The browser pipeline uses that canonical checkpoint and its shards;
it does not need a new legacy `snapshot.json` mirror. An existing mirror is left
untouched. Strict export behavior outside the accepted operator context remains
unchanged.

After deployment, **Wznów istniejący eksport** continues from
`39 / 0 / 15629`: publish the completed capture into the root state, run the
existing stored-artifact relation audit, prepare photo tasks, then enter the
existing photo pipeline. It does not revisit `marketplace_listings`, read live
business records, reconcile parts, or generate another snapshot/attempt/boundary.
The operator ACCEPT, including recorded IDs `1937` and `2262`, remains unchanged.
The frozen capture counts remain visible even when relation/photo progress uses
a different denominator. Restarting the entire snapshot is explicitly blocked
for this accepted run.

## Diagnostics and failure boundaries

Compact status and the panel expose `operator_finalize_stage` plus the artifact
or relation cursor, including the dataset-to-handoff transition. A small private
`operator-finalize-last-request.json` records the action, request lifecycle,
duration, safe exception class/category, stages before/after, artifact offsets,
relation offsets, and package stage. It remains pending through JSON response
construction. It contains no exception messages, stacks, record bodies, customer
data, or credentials. Polling reads only bounded operational metadata.

Controlled integrity, identity, malformed stored JSON, and relation failures
return explicit HTTP 409 blockers. Unexpected infrastructure errors return a
safe HTTP 500 diagnostic and preserve the last committed checkpoint. The fatal
handler also records failures occurring after `last_tick=tick_completed` rather
than mistaking that earlier success for the outcome of the request. Fatal
diagnostics are best effort: process termination or a fatal handler that cannot
run can still prevent them from being written.

The existing runner polls status after a transient error. A committed stage or
cursor advance resets its retry budget; otherwise it retries the same bounded
step. Manual Resume preserves the same attempt and checkpoint. No production
recovery is performed by this change.

## Validation

- Real local artifact fixture: last-dataset transition, stored relations, and
  photo preparation, with zero SQL/provider calls during finalization.
- Exact completed/stale-root fixture with frozen 79957/2155 counts, unchanged
  checkpoint bytes, preserved ACCEPT and attempt, and bounded response size.
- 12 MB / 15629-descriptor completed checkpoint in a separate Laravel process
  limited to 128 MiB; no shard rescan or legacy mirror generation.
- Controlled artifact/relation/JSON corruption, infrastructure failure after
  commit, and fatal diagnostics after a healthy historical tick.
- Chromium: lost response after commit, HTTP 500, network error, malformed
  response, late dataset transition, exhausted retries/manual Resume, reload,
  double click, and an explicit controlled blocker.

All fixtures and HTTP interception are local. No production actions, business
writes, provider calls, or integration changes are required.
