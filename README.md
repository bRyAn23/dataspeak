# Data Speak

AI-assisted analytics and executive insight platform for structured business CSV data,
developed as the MSIT Capstone Project.

**Software calculates. AI interprets. Validation verifies. Raw customer CSV data is transient.**

## Architecture

```
React + TypeScript  ->  Laravel API  ->  PostgreSQL / Redis
                              |
                              v
                     Python / FastAPI Analytics (Polars, SciPy, statsmodels, scikit-learn)
                              |
                              v
                     Analytical Evidence -> AI Gateway (Qwen via Ollama) -> Claim Validation -> Executive Report
```

The browser talks only to Laravel. Laravel orchestrates FastAPI over an internal, token-authenticated
contract. PostgreSQL holds derived metadata, evidence, insights, claims and reports, never raw rows.

## Repository structure (Specification Section 14)

| Path | Contents |
| --- | --- |
| `frontend/` | React + TypeScript (Vite, Tailwind, React Router, TanStack Query, Recharts); the Figma prototype is kept in `frontend/prototype-reference/` |
| `backend/` | Laravel application API, including the database migrations |
| `analytics/` | FastAPI deterministic analytics service |
| `database/` | Schema design artifacts (ERD, dictionary notes); nothing executes from here |
| `docs/` | Specification documents, conventions, contracts, traceability |
| `tests/` | Cross-service contract, end-to-end and evaluation tests |
| `docker/` | Per-service Dockerfiles for the local stack |
| `.github/workflows/` | CI: lint and test each service independently |

## Documentation

- [Getting started](docs/GETTING_STARTED.md): run the project from a clean machine, Docker or native.
- [Developer manual](docs/DEVELOPER_MANUAL.md): program lists with function lists for the frontend, backend and analytics service, the integration APIs (Laravel to FastAPI, Laravel to Ollama) and the Ollama set-up.
- [AWS deployment, step by step](docs/AWS_DEPLOYMENT_STEPS.md): first deployment walkthrough for someone new to AWS.
- [AWS deployment reference](docs/DEPLOYMENT_AWS.md): topology, files, security groups, operations and variations.
- [Architecture and design notes](docs/ARCHITECTURE_NOTES.md): architecture, schema, lifecycle, AI boundary, contracts, troubleshooting and the dated change log.
- [Testing guide](docs/TESTING_GUIDE.md): automated suites, live smoke, manual API walkthrough, AI evaluation, privacy and failure drills.

## Local development

Prerequisites: Docker Desktop. For running services natively you also need Node 24, PHP 8.4 with
Composer, and Python 3.12 (3.9 works for the analytics service).

```bash
cp .env.example .env                       # compose settings, no secrets
cp backend/.env.example backend/.env       # then: php artisan key:generate (or let the container do it)
cp frontend/.env.example frontend/.env
cp analytics/.env.example analytics/.env
docker compose up --build
```

| Service | URL | Exposure |
| --- | --- | --- |
| Frontend (Vite) | http://localhost:5173 (log in, then Upload -> Profile -> Define -> Analyze -> Report) | localhost |
| Backend (Laravel) | http://localhost:8000, health at `/api/v1/health` | localhost |
| Backend queue worker | `dataspeak-backend-worker` container, runs `queue:work redis` for analysis jobs | none |
| Analytics (FastAPI) | http://127.0.0.1:8001/health | loopback only, internal service |
| PostgreSQL 17 | 127.0.0.1:5432 | loopback only |
| Redis 7 | 127.0.0.1:6379 | loopback only |
| Ollama (Qwen) | http://127.0.0.1:11434 | native on the host by default; `--profile ollama` starts a container |

The AI interpretation layer uses a locally served Qwen model through Ollama. Pull it once:

```bash
ollama pull qwen3:8b          # native Ollama (recommended on macOS)
```

In Docker the backend reaches native Ollama through `host.docker.internal`. To run Ollama as a
container instead: `docker compose --profile ollama up -d`, `docker compose exec ollama ollama pull qwen3:8b`,
and set `OLLAMA_BASE_URL=http://ollama:11434` in the root `.env`. Ollama is never exposed publicly.

## End-to-end smoke test

With the stack running, this exercises register, upload, validate, profile, define, run (queued job),
insights, visualizations, report and delete against the real services using the committed synthetic
golden dataset:

```bash
python3 tests/e2e/smoke_workflow.py            # defaults to http://localhost:8000/api/v1
python3 tests/e2e/smoke_workflow.py --keep     # keep the analysis for: php artisan dataspeak:ai-eval <id>
```

## Quality commands

| Service | Lint / format | Tests |
| --- | --- | --- |
| frontend | `npm run lint`, `npm run format:check`, `npm run typecheck` | `npm test` |
| backend | `vendor/bin/pint --test` | `php artisan test` |
| analytics | `ruff check .`, `ruff format --check .` | `pytest` (regenerate the frozen contract with `python -m scripts.export_openapi`) |

## Conventions

- Identifier scheme (`FEAT-*`, `MOD-*`, `*-API-*`, `PY-API-*`, `FE-*`, `NFR-*`): `docs/conventions/identifiers.md`
- Logging, privacy and transient storage rules: `docs/conventions/logging-and-privacy.md`
- Feature traceability audit: `docs/traceability/feature_matrix.md`
