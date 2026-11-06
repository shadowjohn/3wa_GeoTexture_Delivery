# Shared atlas scenes

The user explicitly approved connecting shared atlases, chunk preview and a whole-region GLB export. This continues the reviewed roadmap and Ponytail mode; implementation proceeds within that authorization.

## Chosen approach

Repack existing, validated procedural GLBs into derived scenes. Preserve their texture patterns, source geometry and the original individual files. Split UV triangles at repeat boundaries before mapping them into atlas cells with wrapped gutters; simply scaling repeating UVs would sample neighboring images. Atlas tiles use 128 pixels for map previews and the original 512 pixels for export. Deduplicate identical images within each scene and combine compatible geometry/materials. This changes preview texture resolution, not the underlying recipe or the individual deliverable.

Alternatives rejected for this slice: inventing a new appearance recipe would change colors/windows unnecessarily; external atlas URIs would compromise portable GLBs and require SDK texture caching changes. Atlas images are embedded once per combined scene so buildings actually share GPU textures.

## Scope and contracts

Three procedural v1 styles are supported. Photo exports retain their existing flow and quality semantics. Preview uses 256 metre Web Mercator grid cells, sorted near the viewport center, and stable groups of up to 32 buildings; return at most 8 chunks (the frontend loads 4 on small screens). Query full grid cells so small pans do not create new partial-cell identities. Each derived scene fingerprints the ordered immutable asset IDs/hashes, kind, recipe, origin and pipeline version. Missing, changed or damaged inputs cannot silently count as included.

The existing worker lock protects both building and scene work. SQLite scene_jobs stores queued/running/completed/failed/cancelled state and bounded progress. Each normal CLI batch handles pending scenes before buildings; explicit single-job web batches keep their old scope. Interrupted running scene jobs are requeued only after the worker owns the lock. Scene work has a bounded child timeout; current work can finish beyond the batch's start-new-work budget. All scene files stay under private artifacts and all APIs/downloads require the existing login. Queue/cancel writes require CSRF. Downloads recheck file hashes and frozen input assets.

POST preview_scenes {bbox:[west,south,east,north]} returns {scenes:[{id,status,members:[asset_id],model?,error?}],recipe_key,limit:8}. A completed model has the existing map model fields with asset_id='scene-'+id and URL api.php?action=scene_file&id=ID&format=glb. Chunk readiness hides only its covered individual models/white extrusions. Until ready, existing single-building previews continue; stale responses/style switches, failed chunks, 2D and disposal retain bounded downloads. Footprints remain the building selection surface.

POST create_scene_export {polygon} freezes current source selection and valid individual asset versions, returns a scene job. GET scene_status?id=ID returns {id,kind,status,progress,phase,total,error,download_url?}. POST cancel_scene_export {id} cancels pending/running export. GET scene_file?id=ID&format=zip|glb streams an authenticated immutable result. Export jobs are reused by fingerprint; explicitly requesting failed/cancelled exports retries them. The ZIP includes original models, selection.geojson, buildings.geojson, manifest.json, scene/whole_block.glb and scene/manifest.json. Missing models remain listed, never synthesized as completed. An all-missing selection is rejected for merged export while legacy ZIP remains available.

New merged export is limited to 2,000 selected buildings and 20 km extent, plus bounded geometry/output complexity (2 million triangles and 256 MiB GLB). Limits fail clearly rather than omit buildings. The map endpoint bounds cell queries and queued chunks; failed previews fall back to original models. No bulk regeneration of existing assets, new service, package, or change to authentication is required.

## Python scene protocol

`python -m pipeline.scene input.json output.glb` reads a bounded request:

```json
{"version":1,"scene_id":"64 hex","kind":"preview","recipe_key":"procedural-tw-v1","origin":{"longitude":121.4,"latitude":25.17},"entries":[{"asset_id":"32 hex","path":"/private/models/id.glb","sha256":"64 hex","source_key":"new-taipei-8418","build_id":"B1","source_hash":"64 hex","recipe_key":"procedural-tw-v1","longitude":121.4,"latitude":25.17}]}
```

Verify each source hash and canonical procedural GLB identity; reject external resources/transformed or unsupported scenes, invalid arrays/indices, non-finite coordinates, malformed ranges and unreasonable repeat/size counts. Reproject local east/north metres to the shared origin (including cosine latitude scaling for east and normal correction). Keep Y up, Z south, altitude/heading zero, scale one. Source surface keys are prefixed with asset_id to avoid collisions. Embedded scene metadata records building/source identity and surface ranges; source models and synthetic labeling remain authoritative.

Write output.glb and output.manifest.json. Manifest fields: version=1, scene_id, kind, recipe_key, appearance_kind=procedural, verification_status=synthetic, longitude, latitude, altitude=0, heading=0, scale=1, up_axis=Y, forward_axis=-Z, model_sha256, members (ordered asset IDs), buildings (source identity list), stats {buildings,triangles,primitives,textures,texture_pixels,atlas_tile_size}. Atlas max edge 4096, two wrapped gutter pixels, linear/clamped sampling to avoid cross-cell mip bleeding. Decode/create one atlas at a time; do not retain all export PNGs as decoded images.

stdout only flushed NDJSON progress {phase:'loading'|'packing'|'writing',done:int,total:int}; stderr holds bounded diagnostics, nonzero exit signals failure. PHP parses progress, validates the output manifest/fingerprint and GLB, then atomically publishes. UI progress reserves final progress for ZIP packaging and never says complete before files are published.

## Verification

Python tests cover deterministic atlas packing, UV wrap clipping (including negative UVs), pixel samples/borders, deduplication, exact footprint/height/location, concave/hole/MultiPolygon and legacy/grouped source GLBs, provenance/range coverage and malformed/oversized/tampered sources. PHP integration covers queue/cache/invalidation/cancel/recovery, snapshot export/missing inputs, hash checks, package contents and safe IDs/paths. HTTP tests cover authentication, CSRF, methods and download headers. Frontend tests cover chunk fallback/membership/stale styles/bounded loads and asynchronous export polling/cancel/download. Use actual browser E8 and independent GLB opening; measure draw calls, texture objects/pixels and render time on a representative block, with software-GPU limitations stated. Deploy under worker lock only after tests and review.
