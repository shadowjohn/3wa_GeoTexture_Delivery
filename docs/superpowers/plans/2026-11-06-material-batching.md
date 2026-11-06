# Same-material primitive batching

Approved scope: priority 2 of docs/2026-11-06-optimization-review.md. Preserve the appearance, coordinates, UVs, normals, holes, heights, source identity, recipe keys and existing single-building artifacts. Change only how compatible triangles are packaged and validated. No new dependency or runtime stack.

## Contract

Procedural GLBs contain three primitives: wall, roof and base, each using its existing material. Each primitive has its existing common `kind`, `textured`, `required_texture` extras plus `surface_ranges`: a nonempty list of `{key, index_offset, index_count}`. Ranges refer to index elements, align to triangles, are ordered, contiguous, positive, and cover the whole primitive. Each range keeps the existing wall/roof/base surface key. This allows independent area/coverage verification while drawing one primitive per material. Original per-primitive `wall_key`/`surface_key` GLBs remain accepted. The manifest continues to enumerate individual surfaces with unchanged area and provenance.

## Implementation and checks

- [ ] Python: add focused assertions for three primitives on ordinary/concave/hole/MultiPolygon inputs; expand ranges to verify all triangle positions, normals, UVs and keys against the old output. Preserve single-file/batch equivalence. Combine groups by existing material/kind in pipeline/procedural.py only; do not change photo export behavior. New metadata has no external URI or source data not already present.
- [ ] PHP: accept and strictly validate optional surface_ranges in glbTextureQuality; compute each surface area from its actual triangle range using glbPrimitiveArea. Keep legacy parsing, texture-chain/UV/geometry/hash checks. Reject gaps, overlap, out-of-bounds/non-triangle/zero/invalid ranges and inconsistent manifest surface data.
- [ ] Tests: procedural registration/export of grouped output, malformed-range rejection with recomputed hashes, old-format compatibility, all prior app/Python/HTTP suites that touch geometry or quality.
- [ ] Measure representative real buildings: primitive count and bytes; compare decoded geometry per surface and embedded texture hashes. Use a fixed browser scene to compare actual draw calls/load behavior if the existing renderer permits instrumentation; identify software-rendered measurements and do not claim hardware FPS gains from primitive counts alone.
- [ ] Spec review, correctness review, targeted checks, deploy under worker flock and verify actual queued generation. Preserve existing artifacts; queued/newly generated models receive batching. Record measurements and any limitation before proceeding to shared-atlas/chunk design.
