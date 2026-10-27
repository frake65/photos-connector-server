# Metadata catalog feasibility spike

This opt-in, experimental server-side job catalogs metadata below one folder explicitly selected by the authenticated Nextcloud user. It stores only the visible file ID, MIME type, byte size, modification time and ETag. It does not open file contents, hash files, change Connector asset mappings, or alter files.

## Enumeration feasibility

Nextcloud 35's public `OCP\Files\Folder::search(OCP\Files\Search\ISearchQuery)` API accepts a query with a limit and offset. The spike uses a maximum query page of 100 cache rows and a stable ascending `fileid` order. Its match-all name comparison is scoped by calling `search()` on the selected user's `Folder`; it does not use direct SQL, the internal file cache, or server paths. This avoids materializing a whole flat directory in the app. Nextcloud applies the limit before mapping rows to visible Nodes and can filter the selected folder itself afterward. Thus a query for 50 rows can return 49 Nodes even when more results remain. The worker advances by the requested query limit and treats only an empty visible result as the end of enumeration. When the same backing item is exposed through multiple mounted aliases, one row can produce multiple Node objects. The worker deduplicates those by file ID. Memory is therefore bounded by the page limit times visible alias expansion, not by a strict 100-Node ceiling. Database cost and memory must still be measured on a real Nextcloud/PostgreSQL installation at increasing directory sizes.

The standalone 1,250-entry fixture verifies at most 100 requested rows per search and 13 populated pages plus one empty end probe (one mount, with no aliases). A regression fixture models the Nextcloud 35 filtered-row behavior with a 50-row query returning 49 visible Nodes and confirms all 1,251 files are catalogued before an empty query ends the scan. These are behavioral fixtures, not measurements of Nextcloud, PostgreSQL, or production memory use. A Nextcloud 35/PostgreSQL integration run with 1,251 synthetic files was recorded in PR #8; it was not repeated during this work because the user prohibited access to running Nextcloud servers. The local 1,251-file test remains a simulated public-API behavior fixture. Offset pagination may become slower at high offsets. The public API does not provide this job a snapshot cursor, so a folder ETag change restarts enumeration from offset zero and removes the superseded pass's entries for that scan. A file whose metadata changes during its page is omitted and marks the scan `incomplete`. Repeated folder changes beyond the retry bound also finish as `incomplete`. Only an empty, stable search result with no incomplete observations is `completed`; operational exceptions use `failed`. Folder ETags are used as change indicators, not claimed as transactional snapshot guarantees.

## Persistence and isolation

The selected user's readable folder ID is resolved again by each worker. Job status and rows are scoped by Nextcloud user ID. A unique active-scope key prevents two active jobs for the same user and folder. Every lease claim atomically increments `lease_generation`; a worker may commit page rows, cursor/counters, ETag resets, or terminal status only while its generation is still current and its lease is unexpired. Those writes and the fenced job-state update are in the same transaction, so a delayed worker's writes roll back if a newer generation took over. Recovery requeues pending work and expired leases. Catalog rows use update-first writes to avoid PostgreSQL's aborted-transaction behavior after expected unique conflicts. A process interruption before commit replays that page; the catalog's unique scope/file key and per-scan seen key make replay idempotent.

The scan stores the folder's stable file ID, not its path. It only includes an explicit MIME allowlist for common image and video types. Unknown and unsupported formats are skipped. Access or metadata failures mark the scan incomplete; a missing or changed observation is never represented as a successful metadata row.

## Experimental API

All routes require the existing connector's authenticated Basic authorization with an app password and a JSON content type for the start request.

| Method | Path | Body / result |
| --- | --- | --- |
| `POST` | `/apps/apple_photos_connector/api/v1/catalog/scans` | `{"folderId": 123}`; returns an existing active scan or a newly queued scan. |
| `GET` | `/apps/apple_photos_connector/api/v1/catalog/scans/{scanId}` | Owner-scoped state, cursor and counters. |

The status response uses `queued` and `running` for resumable work, `completed` only for a fully successful enumeration, `incomplete` for a terminal scan with skipped/changed observations or exhausted ETag restarts, and `failed` for operational errors. Recovery resumes only queued jobs and running jobs whose lease expired. Starting again after a terminal state creates a new scan ID.

The worker uses 50 Nodes per page by default, capped at 100 in the service; the API does not expose a batch-size parameter. Counter `nodesVisited` includes files and folders returned by Nextcloud search. `filesCatalogued` is the number of unique supported file IDs seen in this scan. `lastBatchMilliseconds` and `maxBatchMilliseconds` measure from immediately before the lease claim through folder resolution, ETag checks, search, per-Node metadata checks, catalog/seen-row writes and the cursor/status update. The elapsed value is sampled in the same transaction immediately before the final counter write and commit; that final metric write, commit latency and queue scheduling are excluded. Values are rounded to milliseconds. `processPeakMemoryBytes` is PHP's process-wide high-water mark, not a per-page peak.

## Test evidence and limits

`php tests/run.php` exercises nested folders, a synthetic flat directory with 1,250 entries, the 49-visible-of-50 regression, repeated-scan updates, page bounds, process-object restart/resume, duplicate starts, cross-user isolation, stale-worker fencing after takeover, `completed`/`incomplete`/`failed` states, the documented batch timer boundary, ETag restart, and metadata-only writes. Its in-memory fake models bounded `Folder::search()` behavior and uses SQLite; it does not exercise Nextcloud's query planner or PostgreSQL. The PostgreSQL migration and concurrent-worker integration must be run in an isolated local PostgreSQL environment before this branch is release-ready. No live Nextcloud server was accessed in this work. The actual mount-alias behavior remains open until tested in a separately isolated Nextcloud environment.

No stale-entry marking or automatic deletion is implemented. Catalog rows may therefore accumulate as files are removed or become inaccessible. Retention and cleanup policy is an open product decision.

This catalog is an optional metadata directory only. It does not yet reliably identify duplicates from SHA-256 or EXIF metadata and does not prevent uploads.

The spike is not production-ready. Before considering deployment, run the integration harness against Nextcloud 35 with PostgreSQL, measure latency and memory at increasing folder sizes (including a very large flat folder), verify public API behavior on the supported minimum Nextcloud release, and test job interruption/recovery under the real queue backend. This spike does not automatically delete rows or start scans without an explicit API request.
