# Data Speak Identifier Conventions

Every requirement, module, endpoint, program and non-functional requirement carries a stable
identifier. Code, tests, commits and documents reference these IDs so that documentation and
implementation stay traceable (Specification Section 17, acceptance criterion 7).

| Prefix | Meaning | Defined in | Example |
| --- | --- | --- | --- |
| `FEAT-<AREA>-nnn` | Product feature | Feature List, Section 3 | `FEAT-UPL-002` CSV Validation |
| `MOD-nn` | Program module / layer responsibility | Specification, Section 7 | `MOD-06` Analytics Engine |
| `<AREA>-API-nnn` | Laravel application endpoint under `/api/v1` | Laravel API Documentation, Section 4 | `RUN-API-001` `POST /analyses/{id}/run` |
| `PY-API-nnn` | FastAPI internal analytics endpoint | FastAPI API Documentation, Section 3 | `PY-API-001` `GET /health` |
| `FE-<KIND>-nnn` | Frontend page, component, service, hook or type | Frontend Program List, Sections 3-10 | `FE-PG-004` NewAnalysisPage |
| `NFR-<AREA>-nnn` | Non-functional requirement | Specification, Section 12 | `NFR-PRI-001` No permanent raw-row storage |

## Area codes

| Code | Area |
| --- | --- |
| AUTH | Authentication and session |
| ANL | Analysis management |
| UPL / DATA | CSV upload and validation (feature prefix UPL, endpoint prefix DATA) |
| PRO / PROF | Profiling (feature prefix PRO, endpoint prefix PROF) |
| DEF | Field definitions |
| ANA / RUN | Deterministic analysis (feature prefix ANA, endpoint prefix RUN) |
| INS | Insights |
| VIS | Visualizations |
| AI | AI interpretation |
| VAL | Claim validation |
| RPT | Executive reporting |
| TRC | Traceability |
| OPS | Processing status and retry |
| SEC | Security and privacy |
| I18N | Internationalization |

Frontend kinds: `PG` page, `CMP` layout component, `UPL`/`PRO`/`DEF`/`ANL`/`RPT` feature components,
`SVC` service, `HOOK` hook, `TYPE` type module.

## Usage rules

- Put the ID in a top-of-file comment for every program the Frontend Program List names, and in the
  docblock of every Laravel controller action and FastAPI route.
- Reference IDs in test names where practical, for example `test_py_api_001_health_contract`.
- Mention the primary ID in commit subjects when a change delivers it: `feat(RUN-API-001): queue analysis`.
- Never renumber. Retire an ID by marking it withdrawn in the source document.

## Known identifier conflict

`NFR-SEC-001` is defined in the Specification as *authenticated/authorized access to analysis
resources*. The foundation prompt reused the same ID for *FastAPI not publicly exposed*. This
repository implements both requirements; the second is tracked here as `NFR-SEC-001b` until the
Specification assigns it a number.
