# Batch production implementation plan

> For agentic workers: use superpowers:subagent-driven-development task by task. The user approved the architecture and order in docs/2026-11-06-optimization-review.md, then requested Ponytail's smallest complete change. Continue without another approval gate.

**Goal:** Remove per-building global reconciliation and repeated Python startup while retaining integrity, cancellation, isolated errors and incremental progress.

**Architecture:** PHP remains the sole task/registration owner. One lazily started Python process per worker batch accepts one bounded NDJSON request at a time over stdin and immediately returns one result over stdout. Existing flock, frozen inputs, immutable assets, source/recipe identities and single-file CLI remain compatible. Procedural inputs do not create db/inputs files; photographic inputs retain their existing flow. Work in .worktrees/optimization and integrate only verified changes.

**Tech stack:** Existing PHP/PDO SQLite, Python stdlib, NumPy/Pillow/Shapely; no new service or dependency.

## Task 1: Python streaming interface

- [ ] Add tests/test_procedural_batch.py: start `python -u -m pipeline.procedural --batch`, send two valid requests and one invalid height, read one JSON result after each line without closing stdin, verify valid outputs equal legacy generate bytes and invalid input does not stop the process. Check oversized/malformed input and output path errors.
- [ ] Run `.venv-procedural/bin/python -m pytest tests/test_procedural_batch.py -q`; confirm the current CLI rejects --batch.
- [ ] In pipeline/procedural.py split existing file parsing from `generate_request(request, output_path)`; the legacy `generate(input_path, output_path)` calls the same implementation. Batch lines are `{"request": {...frozen input...}, "output_path": "/private/path.glb"}`; reply `{"ok":true}` after model and manifest are written, or `{"ok":false,"error":"bounded diagnostic"}`. Bound each input line to MAX_INPUT_BYTES, flush each reply, catch per-item generation errors and keep serving subsequent valid requests. Fatal framing/oversize errors end the process cleanly. Never use request-derived names as executable commands.
- [ ] Run the new tests plus tests/test_procedural.py and tests/test_glb_export.py; review compatibility and error isolation.

## Task 2: PHP persistent runner and scoped job summaries

- [ ] Add tests/batch-worker.php with a disposable source/state and a Python launcher that counts starts. Assert multiple procedural items use one process, write no input JSON, update progress per completed item and isolate invalid height. Existing photo preparation, overlapping jobs, force, missing files and cancellation still work. Add a bounded hung-child/protocol-error case and cancel while a child is running.
- [ ] Run `php tests/batch-worker.php`; confirm the old worker launches multiple processes/writes procedural input files.
- [ ] Extend src/ProceduralRunner.php with lazy startup, `generateRequest(array $request, string $output): void`, bounded nonblocking pipe I/O, 20-second per-item timeout, bounded stderr, validation of response/expected files and reliable close/destructor cleanup. Preserve static generate() compatibility. Protocol/process failures close the process so the next item can start a replacement; a valid per-item error can reuse it.
- [ ] In src/App.php runBatch create one runner per batch and close it in finally. Generate procedural input from frozen feature_json in memory; preserve prepareInput for photos. Start the time budget before initial reconciliation. Stop starting new items on cancellation or time exhaustion; check cancellation again before registration.
- [ ] Replace registerResult's jobs() call and runBatch's per-item full job() scan with SQL status/count refresh for affected job IDs only. Preserve the existing full reconciliation once at batch start and on explicit detailed reads, and never overwrite cancelled status. Reuse the same status derivation as job().
- [ ] Run PHP app/auth/geometry/procedural/batch-worker suites and Python HTTP integration tests; review state transitions and process lifetime.

## Task 3: End-to-end performance and deployment

- [ ] Benchmark old and new worker on identical private state snapshots and immutable hard-linked model files. Source stays read-only; no benchmark writes reach the production artifact directory. Compare the same batch size and count valid completed artifacts, wall time and process starts.
- [ ] Review the combined diff for spec compliance, then correctness/security. Address material findings and rerun affected checks.
- [ ] Acquire existing worker flock before integrating the verified commit into the deployed branch; release without removing the lock file. Confirm normal cron output, authenticated HTTP behavior, incremental progress and syntax/modes (new PHP 0755).
- [ ] Update docs with measured results and complete this plan. Continue the approved roadmap with a separate plan for geometry/material batching; keep its implementation independent of this queue change.
