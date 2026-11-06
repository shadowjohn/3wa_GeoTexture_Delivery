# Procedural Facades Implementation Plan

> Execute with Superpowers test-driven-development and subagent-driven-development. User approved the design in chat; proceed within the existing deployment checkout and preserve current configuration, permissions and database.

**Goal:** Generate three deterministic illustrative building styles and deliver them through the existing authenticated map, task and export workflow.
**Architecture:** Reuse frozen task inputs, recipe_key separation, immutable asset registration and the worker lock. Add a Python generator and a separate procedural manifest validator; retain photo validation unchanged.
**Tech Stack:** PHP/PDO SQLite, existing E8 JavaScript modules, Python/Pillow/NumPy/Shapely, glTF 2.0.

- [x] Generator: `pipeline/procedural.py`, `tests/test_procedural.py`. CLI `python3 -m pipeline.procedural INPUT_JSON OUTPUT_GLB`; writes OUTPUT.manifest.json. Reuse geometry/GLB helpers from glb_export. Manifest version 1, appearance_kind procedural, style_key recipe_key, verification_status synthetic, source identity, model hash, placement, axis, surfaces + counts and embedded textures hashes. Tests deterministic bytes, style differences, holes/multipolygon roofs, true wall heights and normals, invalid heights/recipes, self-contained texture resources.
- [x] Backend: `src/Recipes.php`, `src/App.php`, `public/api.php`, `tests/procedural.php`. White-list four recipes; optional request parameter defaults to legacy. runBatch accepts optional job filter, validates 1..10 HTTP batch and CSRF. Invoke trusted CLI via argument-array proc_open with bounded runtime and logs, private temp files, cleanup. Validate procedural manifest against actual GLB texture/surface evidence and version before registering. Test independent variants, forced redo, cancellation, corrupt result rejection, export metadata and legacy behavior.
- [x] Frontend: `public/index.php`, `public/assets/app.mjs`, `app.css`, `model-quality.mjs`, `viewer.mjs`. Global style selector for map/selection/task/export; preserve polygons when switching and refresh collections; freeze controls during requests; explicit job style and process-batch button. Distinguish synthetic notices from photo verification. Tests request style propagation and stale result protection; use existing API wrapper implementation.
- [x] Verify: PHP syntax; PHP geometry/app/auth/procedural suites; Python generator and exporter suites; Node frontend/model-quality suites; authenticated disposable-state HTTP and real-browser smoke with screenshot; HTTP 401/403 boundary checks; final git diff and modes.
- [x] Document: README usage, Python dependencies and bounded batch behavior; test evidence and scale limitations. No cron, account changes, real-source edits or World Gacha asset copying.

## User steering applied

- Rename deployment to map_building_skin; keep old URL as 308 redirect preserving request methods.
- Password theft protection: preserve bcrypt password_hash/password_verify, bind sessions to stored password version, expire after 12h, reject stale sessions after password changes, use compare-and-swap password update. Auth regression tests expanded from 25 to 30. Existing accounts/passwords not reset.

## Verification evidence

- PHP: auth 30 checks, app 104 checks, geometry 17 checks, procedural integration passed.
- JavaScript: frontend and model-quality suites passed.
- Python: targeted generator, HTTP and GLB exporter suites: 20 passed, 21 subtests passed. The broader existing photo pipeline still requires the absent vendor/orthofacade/geo.py dependency.
- Disposable-state browser: three styles with real building geometry, task creation, processing, 3D preview, mobile layout, and ZIP download verified. Download contained three complete pastel models; ZIP integrity and model SHA-256 verified.
- Deployment: old URL redirects to new URL; login 200, unauthenticated API 401, private source/database URLs 403. SQLite main file mode 0660 plus named ACL supports both CLI and web writes without public download access.
