# Logging and Privacy Baseline

Applies to all three services. Implements `NFR-OBS-001`, `NFR-PRI-001`, `FEAT-SEC-002` and
`FEAT-UPL-003`.

## The rule

**Raw CSV cell values are never logged, never persisted, and never sent to an AI service.**

Loggable: analysis IDs, job IDs, stage and status names, method names, durations, row and column
counts, column *names* (headers), error codes, HTTP status codes, safe diagnostics.

Not loggable: any cell value, any row, any slice of file content, request bodies on upload routes,
exception context that embeds file content, user-entered free text that may contain data.

## Structured logs

| Service | Mechanism | Format |
| --- | --- | --- |
| Laravel | `json` Monolog channel to stderr with `PrivacyProcessor` stripping `file`, `csv`, `rows`, `cells`, `content` keys | JSON lines |
| FastAPI | stdlib `logging` with `JsonFormatter` and `RedactFilter` | JSON lines |
| React | `src/lib/logger.ts`; accepts only primitive fields, refuses `File`/`Blob` arguments | console JSON in dev, no-op in prod |

Every log line carries `service`, `level`, `message`, `timestamp` and, when available, `analysis_id`
and `request_id`.

## Transient storage

| Location | Purpose | Lifetime | Docker volume? |
| --- | --- | --- | --- |
| `backend/storage/app/tmp/` | Uploaded CSV between workflow steps | Deleted on run completion, non-transient failure, analysis delete, or TTL expiry (default 60 min) | No. Gitignored project path, bind-mounted into the web and worker containers so both see it |
| `analytics/tmp/` (container `/tmp/dataspeak`) | Per-request scratch while profiling/analyzing | Deleted in `finally` on every request | tmpfs in compose |
| Redis | Queue job metadata and progress | Short TTL | No volume |
| PostgreSQL | Derived metadata only | Persistent | Named volume (`postgres_data`) |

The only named Docker volume in the repository is the PostgreSQL data volume. Nothing that can
hold raw CSV content is mounted to a volume.

## Internal service authentication

Laravel calls FastAPI with the header `X-Internal-Token`. Both services read the shared value from
the environment variable `ANALYTICS_INTERNAL_TOKEN`. `GET /health` on FastAPI is unauthenticated;
every other FastAPI route rejects a missing or wrong token with HTTP 401 and the code
`ANALYTICS_UNAUTHORIZED`. FastAPI is published only on `127.0.0.1` in local compose and must not be
exposed publicly in any deployment (`NFR-SEC-001b`).

## AI Gateway placeholder

No LLM container or client exists yet. When added, the gateway receives *evidence records*, never
dataset rows, and the compose placeholder in `docker-compose.yml` documents the expected env names.
