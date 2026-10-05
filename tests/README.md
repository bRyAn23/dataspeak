# tests/

Cross-service tests. Each service keeps its own unit and feature tests inside its folder; this
directory holds tests that span services.

| Folder | Purpose |
| --- | --- |
| `contract/` | Laravel to FastAPI schema/contract tests against `docs/contracts/fastapi-openapi.json` |
| `e2e/` | Upload -> profile -> define -> analyze -> report -> traceability flows against the compose stack |
| `evaluation/` | AI output validation, evidence grounding, unsupported-claim rejection (future) |

Golden datasets used here are small, synthetic and committed. They never contain real customer data.
