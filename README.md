# signlab_blendAnims (blendBaking)
Tool over the ZIN baked-mocap corpus: download gloss SRTs, review the base-gloss vocabulary by category, serve gloss timings as JSON.

## What it does
- `index.html` tabs: **SRT bestanden** (baked videos with a gloss SRT, search/filter, ZIP download), **Glossen** (base-gloss counts, variants, category; expand for every sentence, timecode, SRT and FBX link; CSV/JSON export), **API** (hand-written docs for `action=timings` with a live "Try it").
- `api.php` actions: `list`, `glosses`, `timings` (public API), `categories`, `zip`, `rebuildIndex`. Details and invariants: `CLAUDE.md`.
- `timings` params (all optional): `base`/`bases` (comma-joined), `sentenceId`, `gloss` (base gloss), `search`, `mcpStatusTijdAnnotatie`, `page`, `limit` (25, max 200). Always HTTP 200; check `success`.
- Caches `cache/videos.json` and `cache/gloss_index.json` for 600 s; `refresh=1` or `rebuildIndex` rebuilds. Delete `cache/*.json` after a CLI run as your own user.
- Every row comes from `GET /zin/getZinnen.php?action=listMocapFiles`; SRT contents are read from `BB_EAF_DIR` on disk. No DB access.
- FBX links are the baked GLB's stem with `.fbx`, never upstream's `fbxFilename` (a different take when `glbIsLatestTake` is false).

## Where it runs
core (production): `/web/blendBaking`, https://avatar.signcollect.nl (Apache reverse proxy). Demo: dev2 `/web/blendBaking`, dev-1 `/srv/signcollect/web/blendBaking`, at `/blendBaking/`.
The repo name differs from the directory: repos.tsv maps `signlab_blendAnims` to `<docroot>/blendBaking`.

## Status
production (`timings` has outside consumers)

## How to run / deploy
Deployed by the stack: https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack. No build step; `rewrite-urls.sh` edits `BB_ZIN_API` and `BB_MEDIA_ORIGIN` on demo hosts.
```bash
php tests/TestRunner.php               # exit 0 iff all pass; fails if the run leaves files in cache/
php -l api.php srtGloss.php            # the only static check
```
Regenerate `categories.json` (hand-curated) after new vocabulary:
```bash
php scripts/dump_base_glosses.php [--baked-only] > /tmp/base_glosses.tsv   # summary on stderr
# edit categories.json: assign each new base gloss to a slug in "categories"
php scripts/find_uncategorized.php     # must exit 0 before committing
```
Live counts (never hardcode them):
```bash
curl -s 'https://signcollect.nl/zin/getZinnen.php?action=listMocapFiles&baked=1&hasGloss=1&limit=1' \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["total"]." videos\n";'
```

## Configuration
No config file; `define()`s at the top of `api.php`, each overridable:

| Constant | Default |
|---|---|
| `BB_EAF_DIR` | `sc_dir('zin/eaf/zin')` |
| `BB_ZIN_API` / `BB_MEDIA_ORIGIN` | `https://signcollect.nl/zin/getZinnen.php` / `https://signcollect.nl` |
| `BB_SENSES` | `sc_path('signbank_data/glosses_transformed.json')` |
| `BB_CACHE_TTL`, `BB_INDEX_SCHEMA`, `BB_TIMINGS_LIMIT`/`MAX_LIMIT`, `BB_MAX_ZIP` | 600 s, 3, 25/200, 2000 |

Not in git: `cache/` (must be group-writable by `www-data`: `chgrp www-data cache && chmod 775 cache`). `sc_paths.php` is vendored from signcollect-lib; do not edit it here.

## Dependencies
- signlab_zin: HTTP (`getZinnen.php?action=listMocapFiles`) and SRTs on disk in `<docroot>/zin/eaf/zin/`.
- Signbank export `<docroot>/signbank_data/glosses_transformed.json` (from signlab_signCollect-v2, scheduled by signlab_pythonCron).
- `<docroot>/gebarenoverleg_media/fbx/post_processed/` for FBX/GLB links; signlab_signcollect-lib (`sc_paths.php`).
