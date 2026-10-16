# Historical Evidence Integrity (Round 3)

Data Speak, Unit 7 Test Round 3. Baseline taken **2026-10-16 20:30:58 (+07)** before any Round 3 test wrote evidence into this folder's final location. The closing check (section 3) is appended at the end of the round.

## 1. Baseline

| Item | Value |
| --- | --- |
| Scope | every file under `docs/testing/unit7/` except `round3/` (this round) and `final-verification/` (the in-progress Round 3 working folder, created 2026-10-16 19:5x and merged into `round3/` below; it contains no historical material) |
| Files in manifest | 166 (17,326,543 bytes): `evidence/` 67 (Round 1, 2026-10-15), `retesting/` 87 (Round 2, 2026-10-16), `scripts/` 4, 8 top-level documents |
| Git tracking | all 166 files are tracked; `git diff HEAD -- docs/testing/unit7` (excluding the two new folders) is empty, so the baseline equals commit `e4ae051` |
| Manifest | `historical-evidence-manifest.csv` (relative path, size, SHA-256, tracking) |
| Git state at baseline | branch `main`, HEAD `e4ae051dd334721a58ab3e45968ece299e1b91a0`, 10 commits ahead of `origin/main` (`c4a1951`), not pushed |
| Uncommitted files at baseline (left untouched unless listed as Round 3 work) | modified: `.DS_Store`, `docs/testing/unit7.zip` (an archive outside the manifest scope, changed by the project owner, not by this round); modified for DEF-09 (Round 3 work, committed at the end of the round): `backend/.env.example`, `backend/app/Jobs/GenerateReportJob.php`, `backend/app/Jobs/TranslateReportJob.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/app/Services/Ai/LlmUnavailableException.php`, `backend/config/dataspeak.php`; untracked DEF-09 files: `backend/app/Services/Ai/ModelAccessCoordinator.php`, `backend/app/Services/Ai/Clients/CoordinatedLlmClient.php`, `backend/tests/Unit/ModelAccessCoordinatorTest.php`; untracked Unit 6 material from an earlier session: `docs/evidence/unit6/usability/`, `docs/evidence/unit6/usability.zip`, `docs/evidence/unit6/scripts/analyze_usability.py` |

## 2. Protection measures applied during the round

- Test runners were pointed at Round 3 destinations: the system harness via `U7_EVIDENCE_DIR`, the performance and retest scripts as Round 3 copies under `round3/scripts/` with their output paths changed (the historical copies in `scripts/` and `retesting/scripts/` were not edited). Historical input datasets were read, never written.
- No `git reset --hard`, `git clean`, `git checkout --` or `rm` was run against any historical path.
- Corrections to earlier reports, if any, are recorded in this round's documents (see `final-test-summary.md`, section "Corrections to earlier records"), not in the originals.

## 3. Closing check

Executed 2026-10-16 23:30:57 (+07) after every Round 3 and recovery execution had finished (`recovery/closing-integrity-check.log`, produced by recomputing SHA-256 for every path in `historical-evidence-manifest.csv`).

| Check | Result |
| --- | --- |
| Historical files checked | 166 |
| Unchanged (checksum and size equal to baseline) | **166** |
| Modified | 0 |
| Missing | 0 |
| Unexpected new files under `docs/testing/unit7/` outside `round3/` | 0 |
| `git diff HEAD` on historical paths | none (the only differences in `docs/testing/unit7/` outside `round3/` are the deletions of the temporary `final-verification/` working folder, whose files were moved unchanged into `round3/`, as listed in the log) |
| Round 3 pre-recovery snapshot (59 files, `recovery/round3-pre-recovery-manifest.csv`) | 36 unchanged in place, 16 moved unchanged into `round3/`, 2 edited in place (`regression-results.md` updated with the re-execution; `round3_chain2.log` appended by the running chain), 5 regenerated or appended after the snapshot (the DEF-09 run records that gained the W5b rows, the CSV rebuilt from them, the two DEF-09 documents edited after the move, the W5b log written by the chain); **0 artefacts of the failed first attempt (`evidence/attempt1-worker-down/`) altered** |

Files outside the testing folders changed during the round by this work: `backend/` (DEF-09 commit `57b7bce`), `docker/backend/Dockerfile*` (DEF-10 commit `c68be35`), and the stray `backend/.coverage` produced by a mis-targeted pytest invocation at 20:09, which was removed. `.DS_Store` and `docs/testing/unit7.zip` were modified by the project owner, not by this round.

**Overall integrity result: HISTORICAL EVIDENCE PRESERVED.** Every historical file matches its baseline checksum and all 166 are present.
