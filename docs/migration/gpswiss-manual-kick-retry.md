# Manual recovery when KICK also loses its response

The additional operator diagnostics change the diagnosis: the runner **did**
reach `fallback_kick`. Its POST failed with a browser `TypeError`, no HTTP status
and zero response bytes. A subsequent sequence of failed compact GETs replaced
that failure with `status_fetch_retry_exhausted`. The historical capture tick was
`tick_completed`, so absence of that receipt was not the cause of this case.

This evidence identifies transport failure and a recovery-control defect, not a
proven PHP exception, HTTP 500, authentication failure or particular network
device. It also cannot prove whether the failed POST reached the server or
committed work. A still-pending historical request diagnostic is not proof that
the latest KICK executed. Persistent network/hosting unavailability requires
separate investigation; the runner cannot guarantee progress without responses.

## Recovery contract

Use one `guardedKick` function for manual continuation and for post-capture
lost-operation recovery when compact GET retries are exhausted. It performs at most three
serial attempts with bounded backoff for transient transport/HTTP/JSON failures.
Retrying the POST does not require a successful GET between attempts. The
pre-capture fallback retains its previous one-POST behavior.

Every attempt sends only the existing attempt identifier to the existing run
endpoint. Backend ownership, CSRF/session, run/attempt/source identity, phase,
blocker and lock guards remain authoritative. The backend reads the latest
committed checkpoint under the existing lock and performs one bounded unit.
If the previous request committed despite losing its reply, a retry continues
from the newer checkpoint; the browser never submits its cursor as truth.

Known blockers, attempt mismatches and session/authentication errors stop retries.
A valid busy response retains the existing wait behavior. Three transient POST
failures stop with `kick_retry_exhausted`, retaining the actual KICK failures and
allowing a later manual Resume. No snapshot, relation cursor, generation or
operator acceptance is reset. The default original-photo copy pipeline remains.

## Diagnostics

The private panel shows `status_fetch_retry_exhausted`, `kick_fallback_started`,
each KICK result/retry and the final runner decision. A bounded attempt list
retains safe reason, HTTP status, fetch-timeout flag and request duration.
Checkpoint diagnostics distinguish the old browser display from the server's
before/after checkpoints. Backend checkpoint fields are read under the run lock
from technical pipeline metadata and contain no records, exception bodies,
customer data, credentials or filesystem secrets.

No new files beyond existing exporter checkpoint/diagnostic artifacts are
required. No business writes, source verification, provider calls, integration
changes or production execution are part of the fix.

## Tests

New public browser fixtures use synthetic UUIDs and counters; the supplied
checkpoint was also exercised locally before anonymization. The existing shared
server acceptance fixture remains as already published on main.

An intercepted Chromium fixture reproduces the reported sequence with a healthy
historical capture tick. It fails on #1524 and succeeds after this fix. Cases
cover a POST that never commits, a committed POST with a lost reply, unavailable
GET throughout recovery, finite exhaustion and later manual Resume, reload,
transient HTTP/malformed JSON, explicit blockers, attempt mismatch and session
expiry. The same fallback is exercised at every photo/package stage, including
the final catalog. A further case loses every ordinary operation reply while
GET remains unavailable, verifying continuation through the shared helper.

Backend fixtures preserve the captured snapshot and acceptance, read a newer
relation checkpoint on retry, and keep an integrity blocker from executing any
work. Historical totals/descriptors in these fixtures are test metadata, not a
new production export. Existing pipeline tests retain real local photo copying
and sample/full archive generation.
