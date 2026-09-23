# Agent notes for signlab_blendAnims (blendBaking)
README.md covers what it does, deploy, config and `categories.json`. These are the rules the code relies on.

## Scope and layout
- Own repo, separate from `/web/zin`. Only two links to zin: HTTP `getZinnen.php?action=listMocapFiles` for every row, and reading SRTs from `BB_EAF_DIR`. Do not add a third; never `require` from `/web/zin`.
- `api.php` holds every endpoint and only dispatches when not on CLI, so tests can `require_once` it. `srtGloss.php` is a pure library (no output, no I/O beyond given paths).
- Check: `php tests/TestRunner.php`, `php -l <file>`, and `php scripts/find_uncategorized.php` must exit 0 before committing `categories.json`.

## api.php
- All actions but `zip` return JSON with HTTP 200; failures are `{"success": false, "error": "..."}` (Dutch). CORS `*`.
- `timings` is the public API, documented in the API tab of `index.html`. Change its params or response and that tab in the same commit.
- The ZIP form posts one comma-joined `bases` field; `bases[]` gets cut by `max_input_vars` before PHP runs.

## Invariants
- Never serve a partial corpus: `bb_fetch_all_videos()` errors and caches nothing if any page fails.
- Every cache file lives in `dirname(BB_CACHE)` (the test fixture relies on it). Bump `BB_INDEX_SCHEMA` when the cached shape changes. `cache/` must be group-writable by `www-data`; don't leave cache files owned by you.
- The FBX comes from the GLB's stem, never from upstream `fbxFilename` (that is the newest take, not the newest baked take).
- `find` returns nothing on the rclone mount `/web/gebarenoverleg_media/`; use `scandir()`/`glob()`.
- Only rows with `mcpStatusTijdAnnotatie=Klaar` have adjusted cue times; filter on it before comparing tiers by time.
- Take numbers restart per recording session. `baked` and `hasGloss` are independent.
- Keep `tests/.htaccess` and `scripts/.htaccess` (they deny web access).
- Senses are looked up on the exact cue, not the folded gloss. `srt_base_gloss()` strips one trailing `-<letter>` and is case-sensitive (`PT-1hand` and `PT-1HAND` stay apart).
- Don't hardcode corpus counts; if a doc states one, date it.
