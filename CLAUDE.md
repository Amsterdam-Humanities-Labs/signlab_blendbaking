# blendBaking

A small standalone tool over the ZIN baked-mocap corpus: download gloss SRT files
for baked sign-language videos, review the base-gloss vocabulary those SRTs
contain, and serve gloss timings per sentence as JSON.

`README.md` is the long-form reference — architecture, the gloss index and its
cache, regenerating `categories.json`, and the environment constraints. Read it
before changing behaviour. This file is the short version plus the rules that are
easy to violate without noticing.

## Repository scope

**This directory is its own git repository, separate from `/web/zin`.** No shared
history, branches or commits. `git log` and `git blame` here are scoped to
blendBaking only. The relationship to `zin` is two dependencies and nothing else:

1. An HTTP dependency on `getZinnen.php?action=listMocapFiles` for every row of
   data. blendBaking never queries the database and never re-derives
   baked/gloss status itself.
2. A filesystem dependency on `BB_EAF_DIR` (`/web/zin/eaf/zin/`) for gloss SRT
   *contents*, once a video's `base` is known.

Do not add a third. In particular, do not `require` anything out of `/web/zin`.

## Layout

| Path | What it is |
|---|---|
| `index.html` | The whole UI. Three Bootstrap tabs, one inline `<script>`, no build step. |
| `api.php` | Every endpoint. Defines functions on include; only dispatches on `$_GET['action']` when not on CLI, so tests can `require_once` it. |
| `srtGloss.php` | Pure SRT/gloss library. No output, no I/O beyond reading paths it is handed. |
| `categories.json` | Hand-curated gloss → category map. Checked in, never auto-generated. |
| `glosses_transformed.json` | Signbank export (~11 MB), source of the `senses` lookup. A hand-copied snapshot; no generator here. |
| `cache/` | Git-ignored. Always safe to delete; repopulates on next request. |
| `tests/`, `scripts/` | CLI only. Both carry an `.htaccess` denying web access. |

## Commands

```bash
cd /web/blendBaking

php tests/TestRunner.php          # whole suite; exit 0 iff everything passed
php scripts/find_uncategorized.php   # must exit 0 before committing categories.json
php scripts/dump_base_glosses.php    # every base gloss in the corpus, most frequent first
```

There is no linter, formatter, package manager or build step. `php -l <file>` is
the only static check.

## api.php actions

All actions except `zip` respond with JSON and always with HTTP 200 — failures
are `{"success": false, "error": "..."}` in the body. Error strings are Dutch.
CORS is `*` on every action; the data is public and read-only.

| Action | Purpose |
|---|---|
| `list` | Proxies `listMocapFiles` with `baked=1&hasGloss=1` fixed. Backs the SRT tab. |
| `glosses` | The aggregated gloss index plus `categoryMap`. `refresh=1` rebuilds. Backs the Glossen tab. |
| `timings` | **The public API.** Gloss cue timings per sentence. Documented in the API tab. |
| `categories` | `categories.json`'s contents. |
| `zip` | Streams a ZIP of gloss SRTs. POST a single comma-joined `bases` field. |
| `rebuildIndex` | Forces a gloss-index rebuild; returns `{success, bases, files}`. |

The API tab in `index.html` is hand-written documentation, in English, of the
`timings` action. There is no generated schema — **if you change `timings`'
parameters or response shape, update that tab in the same commit.**

## Invariants

These are the things that have already gone wrong here, or would go wrong
silently if broken.

**A partial corpus is never served as a whole one.** `bb_fetch_all_videos()`
pages until it has every row. If a page fails partway through, it returns an
error and caches nothing. A short list looks exactly like a complete one to
every caller — this is the one failure mode nothing downstream can detect.

**Every cache file lives in `dirname(BB_CACHE)`.** `TestSrtGloss`'s fake-upstream
fixture isolates itself by pre-defining `BB_CACHE` alone. A cache anchored to
`__DIR__` instead gets the fixture's three fake videos written into the real
`cache/` and served to production for a whole TTL. This already happened once
when `BB_VIDEO_CACHE` was added. Two things guard it now: a per-constant
assertion (`testVideoCacheIsIsolatedWithGlossCache`,
`testSensesCacheIsIsolatedWithGlossCache`) and `TestRunner.php`, which snapshots
`cache/` before and after the run and fails the suite on any new file. Adding a
cache constant means adding its assertion too.

**Bump `BB_INDEX_SCHEMA` when the cached aggregate's shape changes.** Otherwise a
cache written by older code is served with the new fields missing until the TTL
expires. It is currently 3 (bumped when occurrences gained `end`).

**`cache/` must be group-writable by `www-data`.** `@file_put_contents` fails
silently otherwise (the `@` suppresses the warning) and every request re-reads
and re-aggregates every gloss SRT from disk. `chgrp www-data cache && chmod 775
cache`. Note this also means **do not leave cache files you created as your own
user** — running the tool from a CLI as yourself writes files `www-data` then
cannot overwrite. Delete them when you're done.

**The FBX comes from the GLB's stem, never from upstream's `fbxFilename`.**
`fbxFilename` is the *newest* take; `glbUrl` is the newest *baked* take. They are
different takes whenever `glbIsLatestTake` is false, and using `fbxFilename`
hands out an animation that does not match the SRT timings, sentence and take
number in the same record — with nothing in the response revealing it. Every
baked take ships `.fbx` and `.glb` side by side under one stem, so `bb_fbx_url()`
swaps the extension. If that ever stops holding, it 404s rather than returning
the wrong take, which is the better failure.

**`find` does not work under `/web/gebarenoverleg_media/`.** It's an rclone
mount; `find` silently returns zero results in directories `ls` lists thousands
of entries for. Use `scandir()` or `glob()` — never `find`, neither the shell
command nor PHP's `exec('find ...')`. Enforced project-wide rather than
re-litigated per directory, including for `BB_EAF_DIR`, which is not itself on
the mount.

**State every count's unit: videos or sentences.** The MCP status columns live on
the `sentences` table, but every row `listMocapFiles` returns is a `video`, and
one sentence can have several baked takes. "X sentences are Klaar" and "X videos
are Klaar" are both legitimate and easy to conflate. Any UI copy, log line, doc
or API field that reports a count must say which it counts.

**Take numbers restart per recording session.** A base's takes are numbered from
0 within each session date, not globally, so ordering by take number alone picks
whichever session happens to have the highest number rather than the newest one.
Order by the pair `(date, take)`. `getLatestMocapFile` in `getZinnen.php` gets
this wrong and also returns a `glbUrl` without checking the file exists — it was
left as-is deliberately, so do not copy its pattern.

**`baked` and `hasGloss` are independent predicates** from independent pipelines
— "does a GLB exist for this take" and "does a gloss SRT exist for this base".
They coincide across the current corpus, but nothing enforces it and no code
should assume one implies the other.

**Do not remove `tests/.htaccess` or `scripts/.htaccess`.** `TestRunner.php`'s
fixture `shell_exec`s `php -S ... &` and `kill`; an anonymous HTTP request to it
would make the web server spawn and kill processes as `www-data`, and an aborted
request would orphan the listener. Run them from the CLI.

**The ZIP download posts one comma-joined `bases` field, not `bases[]` per base.**
A form POST with one hidden input per base is silently truncated by PHP's
`max_input_vars` (1000 on this host) *before* `api.php` runs, which defeats the
`TRUNCATED.txt` protection — the excess bases never arrive to be counted as
excess. `timings` accepts `bases` the same way for the same reason.

**There are three annotation tiers, and `srtUrl` is only one of them.** ELAN
exports `_Signbank_ID_glossen.srt` (one cue per sign, the glosses),
`_Nederlands.srt` (the sentence as one cue) and `_Gebaar-voor-gebaar.srt`
(sign-by-sign Dutch) per base, plus `_Handvorm.srt` for a small minority.
`timings` returns the first three as `srtUrl`, `nederlandsSrt` and `gvgSrt`.
`srtUrl` keeps its name and its gloss-tier meaning because clients depend on it
— do not repurpose it into an object of tiers.

The directory also holds timestamped `*_backup_*.srt` copies of every tier.
`bb_srt_path()` matches on an exact suffix, which is the only thing keeping
those out. Never loosen it into a prefix or glob match.

**Senses are looked up on the exact cue, never the folded base gloss.** Signbank
has `HUILEN-A` but no `HUILEN`, so folding first loses almost every match.
`gloss_senses_lookup()` falls back to a case-insensitive match for the handful
of annotation typos that differ from Signbank only in case (`PT-1HAND` for
`PT-1hand`); this is safe only because Signbank itself has zero
case-insensitive collisions, and `gloss_senses_index()` drops any key that
gains one rather than guessing. The fallback is a lookup convenience and does
**not** mean gloss case is insignificant anywhere else — `srt_base_gloss()`
stays case-sensitive.

`senses` is always an array, `[]` when nothing resolves. That conflates "in
Signbank with no senses" (`nvt`) with "not in Signbank" (`PO+PT`, `-`); the
index keeps the distinction internally if it is ever needed in the response.

## Gloss folding

`srt_base_gloss()` strips one trailing `-<letter>`, so `HUILEN-A` and `HUILEN-B`
both fold to `HUILEN`. It is **case-sensitive**, and the corpus genuinely
contains both `PT-1hand` and `PT-1HAND`, which therefore do not fold together.
That is existing behaviour reflected in `categories.json`; changing it is a
corpus-wide recategorisation, not a bug fix.

`nvt` is a real cue meaning "no gloss assigned to this stretch of signing", not a
null. It is aggregated and returned like any other gloss.

A cue whose text is nothing but digits is indistinguishable from an SRT index
line and gets swallowed as one. No gloss in the vocabulary is a bare number.
Don't "fix" it by dropping the index skip — that emits every index as a phantom
gloss.

## Numbers

Do not hardcode corpus counts anywhere — they drift every time more takes get
baked. `README.md` has the commands to get them live. Any count written into a
doc should carry the date it was measured.
