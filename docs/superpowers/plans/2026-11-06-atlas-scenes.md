# Shared atlas scenes implementation plan

> For agentic workers: use superpowers:subagent-driven-development task by task, with bounded independent Python and frontend ownership alongside parent PHP integration. User approved this feature and the prior roadmap; do not ask for redundant permission.

**Goal:** Ship shared-atlas chunk previews and background whole-region GLB ZIP export in the existing site.

**Architecture:** Derived immutable scenes from existing validated assets; SQLite scene queue processed under the existing worker flock. Existing per-building preview/export remains compatible. Exact API and Python contracts are in ../specs/2026-11-06-atlas-scenes-design.md.

**Tech stack:** Existing PHP/PDO SQLite, Python NumPy/Pillow, E8 dgModel and browser modules; no new dependencies.

## 1. Python atlas and scene compiler

Files: create pipeline/scene.py and tests/test_scene.py. Read pipeline/glb_export.py and pipeline/procedural.py; reuse _Glb/_group without changing single-building output.

- [x] Add failing fixture tests that generate two source buildings, call scene.generate(request, output), inspect grouped buffers/images/manifest and sample mapped UV colors across negative/repeated UVs. Assert SHA corruption, bad paths/external resources/ranges and output limits reject.
- [x] Implement the bounded request/parser, atlas deduplication and wrapped gutters, repeat-boundary triangle clipping, common-origin positions/normals and compatible material groups. Emit exact manifest/progress contract. Bound triangles, bytes, atlas edge and decoded image memory; flush progress while processing.
- [x] Run `.venv-procedural/bin/python -m pytest tests/test_scene.py tests/test_procedural.py tests/test_procedural_groups.py tests/test_procedural_batch.py tests/test_glb_export.py -q` and commit only Python-owned files.

## 2. Scene jobs, snapshots, API and worker

Files: create src/SceneJobs.php and tests/scenes.php; modify src/App.php, src/ProceduralRunner.php, public/api.php. New PHP files mode0755. Keep existing config/bootstrap/schema behavior.

- [x] Add failing PHP integration: two ready models plus one missing; preview queue->worker->completed; same inputs reuse id, new version invalidates; corruption rejected; export frozen selection includes originals plus whole_block GLB/manifest; missing stays missing; cancellation and interrupted running jobs preserve state.
- [x] Add SceneJobs schema/status/fingerprint and private atomic files. SceneJobs uses App::modelFile for source validation and a narrow App GLB validation method; no path from HTTP is accepted. Freeze export selection/manifest before background work and package it from those exact versions.
- [x] Add App previewScenes(bbox), createSceneExport(polygon), sceneStatus(id), cancelSceneExport(id), sceneFile(id,format), plus bounded worker processing under existing lock before normal queued buildings. Preserve old job-scoped runBatch and export API behavior. Scope temporary directories to worker-only writes.
- [x] Reuse ProceduralRunner's one-shot pipe/time-limit lifecycle for fixed pipeline.scene module and structured progress callback; retain old procedural single/batch behavior. Check cancellation during progress and before publication; reap children and clean staging on failure.
- [x] Route new API actions, CSRF for writes, typed bbox/polygon/64hex IDs, authenticated private ETag downloads, valid ZIP/GLB MIME types. Keep existing response shape and legacy actions intact.
- [x] Run PHP app/auth/geometry/procedural/batch-worker/scenes suites plus HTTP tests, inspect diff and modes, commit PHP-owned files.

## 3. Frontend preview and export

Files: public/assets/map.mjs, public/assets/app.mjs, public/index.php, public/assets/app.css; tests/map-preview.mjs and tests/frontend.mjs. Follow existing api wrapper and E8 public model lifecycle.

- [x] Add failing behavior tests for ready chunk replacing only its members, pending/failed fallback, stale viewport/style responses, 2D/disposal/concurrency, and export queue/poll/cancel/download.
- [x] Add setScenes and bounded chunk candidates to MapWorkspace; do not duplicate covered single models or remove white buildings before chunk readiness. Keep click footprints and existing single-model inspection. Return building and chunk counts clearly.
- [x] Call preview_scenes after a settled building view, refresh pending chunks without overlapping requests, clear on recipe changes. Add an accessible optional chunk preview toggle and clear preparation status.
- [x] Add a merged export button with progress/cancel/download from create_scene_export and scene_status; keep original ZIP option. Freeze recipe/polygon when starting; avoid cross-tab/style race updates and stop polling when terminal/hidden.
- [x] Run `node tests/frontend.mjs && node tests/map-preview.mjs && node tests/model-quality.mjs`, commit frontend-owned files.

## 4. End-to-end validation and deployment

- [x] Read combined diff, spec and correctness review; resolve material findings only. Verify auth/CSRF/paths/hash/cancel and original API compatibility.
- [x] Build a real temporary block using existing immutable assets, measure old versus shared-atlas primitives, GPU textures/pixels, model bytes and fixed-scene browser timings. Open ZIP whole_block.glb independently and compare map origins, holes, heights, IDs and missing list.
- [x] Use local authenticated fixture with Playwright to exercise real E8 chunk load/fallback/style switching and export polling/download. Keep production credentials untouched.
- [ ] Document behavior, bounds and measurements; deploy under existing worker flock, check modes/HTTP/cron and a bounded production scene request. Commit verified changes and report concrete usage plus remaining limitations.

## Approved station and editable export refinement

The user requests one region GLB retaining independent nodes/meshes and build_id per building. Export shares atlas/material resources but must not flatten all buildings into a single mesh. Preview alone batches chunks. Copy the reviewed seven road-clearance masks from 3wa_ride with OSM attribution; use source-scoped taichung-8 IDs, 6m estimated clearance only inside masks and original roof height/ground outside. Keep mask geometry, base_m and width_scale in data/station-openings.geojson. Correction content participates in source_hash and GLB provenance so prior solid versions cannot be reused. Add the existing read-only Taichung source and city selector without changing New Taipei default or source data. Verify all seven actual station GLBs and combined export.

Implementation and measured tradeoffs are recorded in docs/2026-11-06-atlas-scenes-results.md. Preview optionally accepts validated camera center; worker processes up to eight scenes within a shared start-work budget. Export snapshots use compact metadata, preserving detailed provenance in original GLBs.
