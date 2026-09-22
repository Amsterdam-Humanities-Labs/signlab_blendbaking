# signlab_blendAnims (blendBaking)
A tool for the baked mocap recordings of the sentence (zin) corpus. It downloads gloss SRTs, sorts the base glosses into categories, and serves gloss timings as JSON.

## What it does
- `index.html` has three tabs. **SRT bestanden**: baked videos with a gloss SRT; search, filter and download as ZIP. **Glossen**: each base gloss with its count, variants and category; expand it for every sentence, timecode, SRT and FBX link; export as CSV or JSON. **API**: docs for `action=timings` with a live "Try it" button.
- `api.php` actions: `list`, `glosses`, `timings` (the public API), `categories`, `zip`, `rebuildIndex`. `CLAUDE.md` has the details and the rules the code relies on.
- `timings` parameters, all optional: `base` or `bases` (comma-separated), `sentenceId`, `gloss` (a base gloss), `search`, `mcpStatusTijdAnnotatie`, `page`, `limit` (default 25, max 200). The status is always HTTP 200, so check `success`.
- The index files in `cache/` live for 600 s. `refresh=1` or `rebuildIndex` rebuilds them. If you run it from the command line as yourself, delete `cache/*.json` afterwards.
- All rows come from `GET /zin/getZinnen.php?action=listMocapFiles`. SRT contents come from `BB_EAF_DIR` on disk. There is no database access.
- An FBX link is the stem of the baked GLB plus `.fbx`. It never uses the upstream `fbxFilename`, which points to a different recording when `glbIsLatestTake` is false.

## Where it runs
Core server: `/web/blendBaking`, https://signcollect.nl/blendBaking/. Demo hosts: dev2 `/web/blendBaking`, dev-1 `/srv/signcollect/web/blendBaking`, at `/blendBaking/`.
The folder name differs from the repo name: `repos.tsv` puts `signlab_blendAnims` in `<docroot>/blendBaking`.
https://avatar.signcollect.nl is a different app (a Vite server outside this repo), not this tool.

## Status
Production. Other projects use the `timings` API.

## How to run / deploy
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack) deploys it. There is no build step. On demo hosts `rewrite-urls.sh` turns the signcollect.nl defaults of `BB_ZIN_API` and `BB_MEDIA_ORIGIN` into same-origin URLs.
```bash
php tests/TestRunner.php      # exit 0 only if all pass; fails if the run leaves files in cache/
php -l api.php srtGloss.php   # the only static check
```
After new vocabulary, update the hand-made `categories.json`:
```bash
php scripts/dump_base_glosses.php [--baked-only] > /tmp/base_glosses.tsv   # summary on stderr
# in categories.json, give each new base gloss a slug under "categories"
php scripts/find_uncategorized.php   # must exit 0 before you commit
```
Get live counts from the API; never hardcode them:
```bash
curl -s 'https://signcollect.nl/zin/getZinnen.php?action=listMocapFiles&baked=1&hasGloss=1&limit=1' \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["total"]." videos\n";'
```

## Configuration
There is no config file. The `define()`s at the top of `api.php` set the defaults, and each can be overridden.

| Constant | Default |
|---|---|
| `BB_EAF_DIR` | `sc_dir('zin/eaf/zin')` |
| `BB_ZIN_API` / `BB_MEDIA_ORIGIN` | `https://signcollect.nl/zin/getZinnen.php` / `https://signcollect.nl` |
| `BB_SENSES` | `sc_path('signbank_data/glosses_transformed.json')` |
| `BB_CACHE_TTL`, `BB_INDEX_SCHEMA`, `BB_TIMINGS_LIMIT`/`MAX_LIMIT`, `BB_MAX_ZIP` | 600 s, 3, 25/200, 2000 |

`cache/` is not in git. The `www-data` group must be able to write to it: `chgrp www-data cache && chmod 775 cache`. `sc_paths.php` is copied from signcollect-lib; do not edit it here.

## Dependencies
- [signlab_zin](https://github.com/Amsterdam-Humanities-Labs/signlab_zin): `getZinnen.php?action=listMocapFiles` over HTTP, and the SRTs on disk in `<docroot>/zin/eaf/zin/`.
- The Signbank export `<docroot>/signbank_data/glosses_transformed.json`. [signlab_signCollect-v2](https://github.com/Amsterdam-Humanities-Labs/signlab_signCollect-v2) makes it; [signlab_pythonCron](https://github.com/Amsterdam-Humanities-Labs/signlab_pythonCron) schedules it.
- `<docroot>/gebarenoverleg_media/fbx/post_processed/` for the FBX and GLB links.
- [signlab_signcollect-lib](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-lib): `sc_paths.php`.
