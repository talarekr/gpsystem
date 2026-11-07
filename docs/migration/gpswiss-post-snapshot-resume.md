# Resume after record capture

The reported run `8de6e27c-ad7c-470b-830b-b9ecee9446ba`, attempt
`9cb33c92-3f1e-4db0-b08d-052715aa5f4c`, has completed capture of 79957 records.
Its runnable work is `snapshot_completed / relation_audit / snapshot_tick`,
with committed relation progress 45983, shard 2239, row 0, probes 67253,
dataset index 35. These are relation checkpoints, not snapshot export cursors.

## Defects and scope of evidence

The old browser runner's two continuation checks required `snapshot_running`,
`photos` or `archive`. An idle `snapshot_completed` relation/photo-preparation
phase therefore took an unnecessary POST `resume` instead of immediately
scheduling the server's `snapshot_tick`. That resume also requested a full
snapshot status/index rather than bounded operational progress.

The same incomplete state classification appeared in session restoration and
fallback KICK eligibility. The server KICK independently rejected
`snapshot_completed` as `state_not_runnable`, so a failed status GET had no usable
fallback. An exact local fixture on the unchanged main reproduces that refusal;
the fixed fixture advances 45983 → 45988 and probes 67253 → 67258.

The supplied production UI trace stops before `status_loaded`. It alone cannot
prove whether the pending production request was a slow/failed status GET or a
later lifecycle request. The classification defects are confirmed in code; new
diagnostics distinguish those request/decision boundaries without assuming an
unrecorded transport exception.

## One runnable work contract

`BrowserMigrationWork` defines the server's bounded action for capture,
recovery/operator finalization, relations, photo inventory, photo copying/retry,
sample generation, ZIP volumes and the final checksum/package catalog. Status,
operational publication and KICK share it. Invalid state/phase pairs remain
blocked; paused, failed, completed, attempt and source-identity guards remain.

The browser uses one decision function driven by server `next_action`, busy,
resume permission and blockers. It does not require `snapshot_running` to run a
browser tick. The same decision applies to manual continuation, remembered state
and KICK eligibility. Explicit server Resume is still used for pause/recovery;
the older worker export retains its lifecycle behavior. Browser Resume returns
bounded compact progress, without scanning the complete snapshot index.

Resume diagnostics include `status_loading`, `status_loaded`, the current state,
phase, next action, busy/resume flags, blocker, runner decision and tick scheduling.
This also makes a pending status load distinguishable from a scheduler blocker.

## Continuing the existing run

After deployment, use **Wznów istniejący eksport**. Load the latest committed
checkpoint and execute its bounded tick. Neither the browser nor the request
submits a cursor to reset. If a reply is lost, status may reveal a newer committed
cursor; the existing progress comparison resets retry budget and continues.
Otherwise retry the same bounded step. KICK applies the same server work contract
under the existing lock when status GETs are unavailable.

Do not restart capture, source verification, parts reconciliation or relation
auditing. The same run, attempt, generations, captured artifacts and operator
ACCEPT remain. The canonical capture checkpoint stays byte-identical in the
tests. Photo copying remains enabled, followed by the existing sample/full
package pipeline. No external-photo delivery option is part of this change.

## Validation

- Exact historical relation counters with a real five-row resumed listing shard;
  prior counters/descriptors are fixture metadata, not a claim of production data.
- Manual Resume leaves the relation checkpoint unchanged; the next actual tick
  advances it, without source SQL, API calls or capture checkpoint changes.
- KICK and lost response preserve the latest cursor instead of replaying rows.
- A real small frozen dataset pauses/resumes every post-capture phase, copies
  originals, builds sample/full packages and reaches completion with zero SQL/API.
- Chromium covers direct and reloaded Resume, transient errors, exhaustion,
  double clicks, busy/session/blockers, fallback KICK, manual continuation at each
  stage, and fresh starts at every post-capture phase.

All tests use local fixtures/intercepted HTTP. No production run is executed.
