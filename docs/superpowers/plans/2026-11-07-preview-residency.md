# Stable viewport previews implementation plan

> For agentic workers: use subagent-driven-development for the bounded PHP contract task alongside parent frontend work, then spec and correctness review. The user approved directions 1 + 2; continue implementation and deployment without another approval gate.

**Goal:** Reduce repeated model loading during nearby panning and zooming, while keeping chunk transitions free of duplicate buildings and stale versions.

**Architecture:** Retain existing loaded dgModel instances within a viewport margin, under explicit count and estimated-memory budgets. Extend existing scene responses with immutable member identities; compare known current versions instead of requiring every member in the viewport. Reuse current PHP/SQLite/E8 lifecycle, worker, authentication and immutable GLBs.

**Tech stack:** Existing browser modules, PHP 8.1, Node assert tests, disposable PHP browser fixture. No dependencies, new schema, compression, LOD generation or SDK edits.

## Contract and invariants

- Scene status adds `recipe_key` and `member_versions`, each containing `source_key,build_id,source_hash,recipe_key,asset_id,longitude,latitude`, from the frozen request. Existing `members` and other fields stay unchanged; no private paths.
- Compact single models add `resident_bytes`, conservatively based on GLB bytes and unique decoded texture pixels (RGBA with mip levels), plus 64 KiB overhead. Scenes use `triangles*96 + texture_pixels*4 + 65536`. Estimates bound extra retention, not the browser's total heap or actual GPU allocation.
- Viewport candidates keep existing limits: desktop 64 singles/8 chunks/4 transfers; mobile 24/4/2. Extra retained ready models: desktop 32 singles/4 chunks/64 MiB; mobile 12/2/24 MiB. Margin is the visible bbox expanded by 25% per side, at least one 256m Mercator cell but no more than 1024m per side. Only already loaded instances are retained; no speculative downloads.
- Current feature identity is `source_key + build_id`. A known conflicting source_hash/asset_id or missing model invalidates the affected whole chunk. Unknown offscreen members do not invalidate an otherwise current scene snapshot. Version knowledge is bounded by current features and resident descriptors; no unbounded city cache.
- Choose a nonoverlapping set of ready chunks. Pending/failed replacements keep valid old ready chunks. Once a replacement is ready, remove every overlapping old chunk and covered single before displaying its replacement; uncovered features fall back to existing singles/white geometry. No overlapping ready chunk meshes.
- Retain live instances, not detached objects: E8 disposes assets on removal. Covered singles are removed, because dgModel has no public visibility method. Do not modify SDK internals.
- Clear retention on source/style changes, 2D, zoom below 13 and explicit empty reset. Existing generation guards and shared in-flight transfer slots still apply. Late completions cannot restore cleared models.

## Tasks

- [ ] Backend: add the response fields in `src/App.php` and `src/SceneJobs.php`; add PHP/HTTP assertions proving compact memory estimates and full frozen member metadata without paths. Run `php tests/app.php`, `php tests/scenes.php`, `.venv-procedural/bin/python -m pytest tests/test_http_scenes.py -q`.
- [ ] Frontend regression first: extend `tests/map-preview.mjs` with controlled SDK completion tests for A→B→A instance reuse, margin/count/byte eviction, boundary membership, overlapping replacements, failures, version changes, mobile limits and 2D/source resets. Confirm new behavior fails on the old code.
- [ ] Frontend implementation in `public/assets/map.mjs`: store loaded descriptors/last-use counters; compute visible active candidates; select compatible chunks; retain eligible ready descriptors within the margin/budgets; retire conflicts and only then pump new active requests. Keep white/count updates tied to ready coverage. Use small named methods for version matching and budget estimation where shared by lifecycle paths.
- [ ] Integrate explicit reset behavior in `public/assets/app.mjs` only where needed, preserve AJAX/view guards, and bump app/map cache keys in `public/index.php` and app import. Run `node tests/map-preview.mjs && node tests/frontend.mjs && node tests/model-quality.mjs`.
- [ ] Browser: use isolated authenticated fixture, compare fixed nearby pan/zoom/return route before and after; count actual model additions/removals and loaded resources, inspect duplicates, retention and repeated route plateau. Exercise source/style/2D reset and actual scene metadata from PHP. Record headless limitations and measured results.
- [ ] Review combined diff for spec then correctness; fix only material findings. Run relevant PHP/Node/HTTP checks, inspect modes and git diff, commit explicit paths. Fast-forward production under existing `db/worker.lock`, verify authenticated boundary protection and public assets; keep cron and source data untouched.

## Acceptance examples

```js
assert.equal(map.models.get(assetId), originalInstance); // A -> nearby B -> A, within budgets
assert.equal(map.coveredAssets.size, fullChunk.members.length); // viewport only contains some members
assert.equal(readyChunkMembers.size, readyChunkMembersArray.length); // no duplicates
assert.ok(retainedBytes <= 64 * 1024 * 1024); // desktop extra retention
```

Measure reduced reloads rather than promise a hardware frame rate. The earlier atlas benchmark improved draw calls but not initial loading; this work targets revisits and boundary churn. New source versions or valid eviction still require a fresh load.
