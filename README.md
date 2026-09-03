# blendBaking

blendBaking is a small standalone tool for two chores around the ZIN baked-mocap
corpus: downloading gloss SRT files for baked sign-language videos, and reviewing
the base-gloss vocabulary those SRTs contain, grouped into categories.

**This directory is its own git repository, separate from `/web/zin`.** It has no
shared history, branches, or commits with `zin` — treat commits, `git log`, and
`git blame` here as scoped to blendBaking only. The two repos are related only in
that blendBaking is an HTTP client of zin's `getZinnen.php` API; there is no
filesystem or code dependency between them beyond that HTTP call and, for one
script, direct disk access to `zin`'s EAF directory (see below).

## What it does

`index.html` is a single Bootstrap page with three tabs:

- **SRT bestanden** ("SRT files") — lists baked videos that have a gloss SRT,
  with search, a status filter, and ZIP download (selected rows, or everything
  matching the current filter).
- **Glossen** ("Glosses") — the aggregated base-gloss vocabulary across those
  SRTs: how often each base gloss occurs, in how many videos, its spelling
  variants, and its category. Has a "Herbouw index" (rebuild index) button and
  CSV/JSON export.

  Each gloss row expands. The panel lists every sentence containing that gloss
  — the video's base filename, the sentence text, its thema, the exact cue and
  start timecode of each occurrence (`AAP-A @ 00:00:01,470`), and SRT and FBX
  links — plus a button that ZIPs the gloss SRTs of just those sentences.

  A gloss signed twice in one sentence appears once in the panel with both
  timecodes listed. FBX files are linked per row rather than bundled:
  `PT-1hand` spans hundreds of videos, and the FBX files run to several MB
  each, so an archive for one common gloss would be enormous.

  The panel needs no extra endpoint — it joins the `occurrences` list from
  `action=glosses` against the video rows the SRT tab has already loaded.

- **API** — hand-written integration documentation for `action=timings`, in
  English while the rest of the UI is Dutch, because its audience is whoever is
  wiring a player or a pipeline to the endpoint rather than the operators using
  the other two tabs. Includes a live "Try it" box that calls the real endpoint
  and pretty-prints the response.

  There is no generated schema. **If you change `timings`' parameters or
  response shape, update this tab in the same commit.**

### Model files: FBX, not GLB

Both tabs link the animation as **FBX**. Upstream returns `glbUrl` (the baked
GLB) and `fbxFilename` (the newest take's FBX); `api.php` derives the FBX link
from `glbUrl` by swapping the extension, and does *not* use `fbxFilename`.

`fbxFilename` is the newest take, while `glbUrl` is the newest *baked* take —
different takes whenever `glbIsLatestTake` is false. Linking `fbxFilename` would
hand out an animation whose timeline does not match the SRT timings, sentence
and take number shown beside it, with nothing on screen revealing the mismatch.
Every baked take ships its `.fbx` and `.glb` side by side in
`/gebarenoverleg_media/fbx/post_processed/` under the same stem (857 of each,
zero unmatched, as of 2026-08-21), so the extension swap keeps every field of a
row pinned to one take.

`action=list` adds `fbxUrl` alongside upstream's `glbUrl` rather than replacing
it — that action is a proxy, and a proxy that drops fields is a lossy one. The
public `timings` action returns `fbxUrl` only.

All row data in both tabs ultimately comes from one upstream endpoint:
`GET https://signcollect.nl/zin/getZinnen.php?action=listMocapFiles`. blendBaking
never queries the database directly and never re-derives baked/gloss status
itself — `api.php` fetches from that endpoint (`bb_fetch_videos()` in
`api.php`) and layers ZIP bundling, gloss aggregation, and category lookup on
top. See `/web/zin/mocapFiles.php` and the `listMocapFiles` entry in
`/web/zin/CLAUDE.md` for the upstream contract (fields, filters, pagination).

The one exception to "everything comes from the HTTP API" is gloss SRT file
*contents*: once `api.php` has a video's `base` from the API response, it reads
the SRT itself straight off disk at `BB_EAF_DIR` (`/web/zin/eaf/zin/` by
default) to parse gloss cues and to serve ZIP downloads. That is a real
filesystem dependency on the `zin` tree, distinct from the HTTP dependency on
`getZinnen.php`.

## `api.php` actions

All actions except `zip` respond with JSON. `zip` streams a file.

Every action responds with HTTP 200 even on failure — errors are
`{"success": false, "error": "..."}` in the body, so callers must check
`success` rather than the status code. Error strings are Dutch.

Every action also sends `Access-Control-Allow-Origin: *` and answers `OPTIONS`
preflights with 204. The data is public and read-only, there is no session to
leak, and the FBX/GLB files these endpoints link to are already served with the
same header by `/gebarenoverleg_media/fbx/post_processed/.htaccess`. There is
deliberately no `Allow-Credentials` — it would make the wildcard origin illegal
anyway.

- **`list`** — proxies `listMocapFiles` with `baked=1&hasGloss=1` fixed, plus
  pass-through params `mcpStatusTijdAnnotatie`, `search`, `page`, `limit`
  (default 500). Each row gains a derived `fbxUrl`. Backs the SRT tab's table.

- **`timings`** — the public API: gloss cue timings per sentence. One record per
  baked video, each carrying the sentence text, thema, take number, `fbxUrl`,
  links to all three annotation tiers (`srtUrl`, `nederlandsSrt`, `gvgSrt`), and
  a `glosses` list with every cue's `gloss`, folded `baseGloss`, Signbank
  `senses`, raw `start`/`end` timecodes, and integer
  `startMs`/`endMs`/`durationMs`.

  All params optional: `base`/`bases` (comma-joined), `sentenceId`, `gloss` (a
  *base* gloss), `search`, `mcpStatusTijdAnnotatie`, `page`, `limit` (default
  25, capped at 200 — each sentence in a page costs one SRT read).

  `base`, `sentenceId` and `gloss` are filtered locally against the cached
  video list rather than pushed upstream, because none of them has an upstream
  equivalent and mixing local and remote filters would make `total` mean
  different things depending on which filters a caller combined. Only the rows
  in the requested page have their SRT read, so page size — not corpus size —
  is what costs disk I/O.

  A sentence whose SRT cannot be read is still returned, with `glosses: []` and
  a message in `srtError`. Dropping it would make `total` disagree with what a
  client can actually page through, and would hide a broken file behind a
  silently shorter list.

  Documented for consumers in `index.html`'s API tab.

### The three annotation tiers

ELAN exports one SRT per annotation tier into `BB_EAF_DIR`, all sharing one
timeline:

| Suffix | Field | Contents | Coverage (2026-09-03) |
|---|---|---|---|
| `_Signbank_ID_glossen.srt` | `srtUrl` | One cue per sign, the gloss as annotated | 739 / 739 |
| `_Nederlands.srt` | `nederlandsSrt` | The Dutch sentence as one cue | 739 / 739 |
| `_Gebaar-voor-gebaar.srt` | `gvgSrt` | Sign-by-sign Dutch, roughly parallel to the gloss cues | 739 / 739 |
| `_Handvorm.srt` | *(not exposed)* | Handshape annotation | 15 / 739 |

`srtUrl` keeps its name and its gloss-tier meaning — clients depend on it, so
it was not repurposed into an object of tiers. The two new fields are derived
from upstream's `glossSrtUrl` by swapping the suffix, so all three links follow
whatever host upstream serves the gloss SRT from. Unlike the FBX, they are
checked against the filesystem first: `BB_EAF_DIR` is local, so the check is
free, and a `null` is more useful to a client than a URL that 404s.

Note the directory also holds timestamped `*_backup_*.srt` copies of every
tier. `bb_srt_path()` matches on an *exact* suffix, which is the only thing
keeping those out of both this and the ZIP download. Do not loosen it into a
prefix or glob match.

### Gloss senses

Each cue in `glosses` carries a `senses` array — the meanings Signbank records
for that sign — looked up in `glosses_transformed.json`, an ~11 MB Signbank
export checked in beside `categories.json`. It is a hand-copied snapshot
(`cp /web/glosses_transformed.json .`); there is no generator here, and it can
lag newly added signs.

The lookup key is the **exact annotated gloss**, not the folded `baseGloss`:
Signbank has `HUILEN-A` but no `HUILEN`, so folding first would lose almost
every match. `gloss_senses_lookup()` then falls back to a case-insensitive
match, which recovers the handful of annotation typos that differ from Signbank
only in case (`PT-1HAND` for `PT-1hand`, `zonde` for `ZONDE`, `in-f` for
`IN-F`). That fallback is only safe because Signbank itself contains zero
glosses differing only by case; `gloss_senses_index()` drops any lowercase key
that more than one gloss spells, so a future collision returns nothing rather
than guessing. **This does not mean gloss case is insignificant elsewhere** —
`srt_base_gloss()` stays case-sensitive and `PT-1hand`/`PT-1HAND` remain
separate rows in the gloss index and in `categories.json`.

`senses` is always an array, `[]` when nothing resolves. That flattens two
distinct situations — a gloss that is in Signbank but carries no senses
(`nvt`, 168 such entries in the export) and one that is absent entirely
(`PO+PT`, `-`) — though `gloss_senses_index()` keeps the distinction internally
if it is ever wanted in the response.

The distilled index is cached to `cache/senses_index.json` and invalidated by
**mtime, not TTL**: the export is a checked-in file that only changes when
someone copies a new one in, so a TTL would either re-parse 11 MB on a schedule
for nothing or serve a stale index after an update. Parsing the full export
costs ~0.11 s and ~48 MB; the distilled index is 0.27 MB and ~0.005 s.

Coverage as of 2026-09-03, across 739 baked videos: 98.4% of the 901 distinct
cues resolve to a Signbank entry. Excluding `nvt` — a real cue meaning "no
gloss assigned to this stretch" — 98.4% of gloss occurrences carry senses. The
largest genuine gap is `PO+PT` (43 occurrences, absent from Signbank).
- **`glosses`** — the aggregated gloss index (see below) plus `categoryMap`.
  Param `refresh=1` forces a rebuild instead of serving the cache. Backs the
  Glossen tab.
- **`categories`** — just `categories.json`'s contents (`{categories, glosses}`),
  or `{"categories":[],"glosses":{}}` if the file is missing/unreadable.
- **`zip`** — POST (or GET) `bases`, either an array or a comma-separated
  string of video base filenames. Streams a ZIP of each base's gloss SRT. Bases
  are validated against an allow-list regex before touching the filesystem, and
  path traversal is rejected outright rather than sanitised. Requests for more
  than `BB_MAX_ZIP` (2000) bases still return a ZIP, but the excess is dropped
  and a `TRUNCATED.txt` manifest is added inside the archive — there is no
  reliable way to signal truncation via headers on a file-download response, so
  the archive itself carries the warning.
  `index.html` sends this as a single comma-joined `bases` field via `fetch`,
  not a per-base form field: a form POST with one hidden `bases[]` input per
  base is silently truncated by PHP's `max_input_vars` (1000 on this host)
  *before* `api.php` ever runs, which defeats the `TRUNCATED.txt` protection
  outright — the excess bases never arrive to be counted as excess. A single
  field has no such limit regardless of how many bases it lists. The `fetch`
  response is also checked by `Content-Type`: a JSON body is rendered in the
  page's alert; a ZIP blob triggers a client-side download — a plain form POST
  to a JSON error response would instead navigate the user away to a raw JSON
  blob.
- **`rebuildIndex`** — forces the gloss index cache to rebuild (equivalent to
  `glosses&refresh=1`) and returns just `{success, bases, files}`. What the
  "Herbouw index" button calls.

### The gloss index and its cache

`bb_fetch_all_videos()` pages through `listMocapFiles?baked=1&hasGloss=1` (500
rows per page) until it has every matching video, and caches the unfiltered
result to `cache/videos.json` for `BB_CACHE_TTL` (600s). Both `glosses` and
`timings` go through it, so paging through timings does not re-fetch all of
upstream on every request. Filtered fetches are not cached — keying a cache by
filter combination would expire in ways callers cannot reason about.

If pagination fails partway through, nothing is cached and an explicit error is
returned — a partial fetch must never be served as if it were the whole corpus.
A short list looks exactly like a complete one to every caller, which makes
this the one failure mode nothing downstream can detect after the fact.

`bb_glosses()` builds the gloss aggregate on top of that list, reading each
video's gloss SRT and aggregating cues to base glosses (`srtGloss.php`'s
`gloss_aggregate()`). The result is cached to `cache/gloss_index.json` and
tagged with `BB_INDEX_SCHEMA` (currently 3); a cache written by older code, or
one whose schema doesn't match, is rebuilt rather than trusted with fields
missing. Bump the schema whenever the aggregate's shape changes — 3 is where it
landed when occurrences gained an `end` timecode.

`BB_VIDEO_CACHE` is deliberately derived from `dirname(BB_CACHE)` rather than
`__DIR__`. `TestSrtGloss`'s fake-upstream fixture isolates itself by
pre-defining `BB_CACHE` alone, so a second cache anchored to `__DIR__` gets the
fixture's three fake videos written into the real `cache/` and served to
production for a whole TTL. That happened once during development;
`testVideoCacheIsIsolatedWithGlossCache` guards it now. **Any new cache file
must be derived the same way.**

`cache/` is git-ignored; deleting it is always safe; it repopulates on the next
request that needs it (or immediately on `refresh=1` / `rebuildIndex`).

`cache/` must be group-writable by `www-data` (the Apache worker user) —
`@file_put_contents(BB_CACHE, ...)` fails silently otherwise (the `@`
suppresses the warning), and every request then re-reads and re-aggregates
all gloss SRTs from disk instead of hitting the cache. `chgrp www-data cache
&& chmod 775 cache` (or equivalent) is enough; matches `/web/zin/cache`'s
`www-data:www-data 755`.

The same applies to the cache *files*, which is easy to get wrong: running the
tool from a CLI as yourself writes `cache/*.json` owned by you, and `www-data`
can then neither overwrite nor replace them, so production silently stops
caching. A group-writable directory does not save you here. `rm cache/*.json`
after any local CLI run that populated it.

## Regenerating `categories.json`

The category map is hand-curated and checked in — it is not regenerated
automatically. To update it after the corpus gains new vocabulary:

```bash
cd /web/blendBaking

# 1. Dump every base gloss currently in the corpus, most frequent first.
#    Add --baked-only to restrict the dump to baked+hasGloss videos only
#    (goes over HTTP to getZinnen.php instead of scanning BB_EAF_DIR directly).
php scripts/dump_base_glosses.php > /tmp/base_glosses.tsv

# 2. Categorize whatever's new — edit categories.json by hand, assigning each
#    new base gloss to one of the existing category slugs (or a new one, added
#    to the "categories" array first).

# 3. Verify completeness. This must exit 0 before committing.
php scripts/find_uncategorized.php
echo "exit=$?"
```

`find_uncategorized.php` reports, and fails on, three separate things: bases
present in the corpus but missing from `categories.json`, bases whose assigned
slug isn't declared in the `categories` array, and (informational only, does
not affect the exit code) bases in `categories.json` that no longer appear in
the corpus. An empty corpus dump is always treated as a broken scan, not a
vacuously-complete category map, so a dead upstream or a missing `BB_EAF_DIR`
fails loudly instead of exiting 0 with nothing checked.

Do not hardcode the current gloss/category counts anywhere when doing this —
they drift as the baked corpus grows. If you need current numbers, get them
live:

```bash
# Base glosses in the full corpus, and how many category slugs cover them:
php scripts/dump_base_glosses.php 2>&1 >/dev/null   # summary line goes to stderr
php -r '$d=json_decode(file_get_contents("categories.json"),true); \
  echo count($d["categories"])." categories, ".count($d["glosses"])." glosses\n";'

# Baked videos / distinct sentences with a gloss SRT, via the live API:
curl -s 'https://signcollect.nl/zin/getZinnen.php?action=listMocapFiles&baked=1&hasGloss=1&limit=1' | php -r \
  '$d=json_decode(stream_get_contents(STDIN),true); echo $d["total"]." videos\n";'
```

(As of 2026-08-19: 619 baked videos across 606 sentences corpus-wide; 710 base
glosses among the baked+glossed subset; 1,513 base glosses across all 5,165
gloss SRT files on disk; `categories.json` covers all 1,513 under 21 category
slugs. These numbers move every time more takes get baked — don't treat them
as current without re-running the commands above.)

## Running the tests

```bash
cd /web/blendBaking
php tests/TestRunner.php
```

Runs `TestSrtGloss` (cue parsing, timecode conversion, gloss timings,
base-gloss folding, aggregation, ZIP truncation, cache isolation) and
`TestCategories` (the categorization pattern rules, and cross-checks against
`categories.json` itself). Exit code is 0 iff every test passed.

`TestRunner.php` snapshots `cache/` before and after the run and fails the
suite on any file that appeared, so a fixture leaking into the real cache is
caught automatically rather than by remembering to look. See `BB_VIDEO_CACHE`
above for why that matters.

`tests/` and `scripts/` both carry a `.htaccess` denying all web access —
CLI-only. `TestRunner.php`'s fixture helper `shell_exec`s `php -S ... &` and
`kill` to stand up a throwaway PHP server per test run; an anonymous HTTP
request to it would make the web server itself spawn and kill processes as
`www-data`, and an aborted request would orphan the listener. Do not remove
that `.htaccess` to "make tests browsable" — run them from the CLI instead.

## Environment constraints

These two came from real debugging time on this project; both are also
recorded in `/web/zin/CLAUDE.md` since they apply to any code touching the
same mount or the same tables, not just this tool.

1. **`find` does not work under `/web/gebarenoverleg_media/`.** It's an rclone
   mount, and the `find` executable silently returns zero results there even
   in directories `ls` lists thousands of entries for. This bit
   `/web/zin/mocapFiles.php` during development; every filesystem scan under
   that mount uses `scandir()` (see `mocap_build_index()`) or `glob()`, never
   `find` — either the shell command or PHP's `exec('find ...')`. blendBaking's
   own `dump_base_glosses.php` uses `scandir()` on `BB_EAF_DIR` for the same
   reason, even though `BB_EAF_DIR` (`/web/zin/eaf/zin/`) is not itself on the
   rclone mount — the habit is enforced project-wide rather than re-litigated
   per directory.

2. **State every count's unit: videos or sentences.** The MCP status columns
   (`mcp_status_tijd_annotatie`, `mcp_status_tijd_annotatie_gvg`,
   `mcp_status_postprocessing`) live on the `sentences` table, but every row
   `listMocapFiles` (and therefore `list`/`glosses` here) returns is a
   `video`. A single sentence can have multiple baked videos, so "X sentences
   are Klaar" and "X videos are Klaar" are different, both legitimate, and
   easy to conflate. Any UI copy, log line, or doc that reports a count must
   say which one it's counting.

Two more, worth knowing before touching this code even though they haven't
caused a bug here yet:

3. **Take numbers restart per recording session (date).** A base's takes are
   numbered from 0 within each session date, not globally, so ordering by take
   number alone picks whichever session happens to have the highest number,
   not the most recent one. `mocap_latest_take()` in `/web/zin/mocapFiles.php`
   orders by the pair `(date, take)` for exactly this reason — e.g. base
   `M20250610_9187` has session `251105` with takes 0-5, `260211` with takes
   0-1, and `260713` with takes 0-1; take-number-only ordering would silently
   pick a take from the oldest session.

4. **`baked` and `hasGloss` are independent predicates, from independent
   pipelines** — one asks "does a GLB exist for this take", the other "does a
   gloss SRT exist for this base" — and `listMocapFiles` computes them
   separately. They happen to coincide for the current corpus (every video
   both tabs show has both), but nothing enforces that, and code should not
   assume one implies the other.

5. **`getLatestMocapFile` in `getZinnen.php` is a separate, older action with
   known bugs — do not copy its pattern.** It orders takes by take number only
   (not `(date, take)`, see point 3 above), so it can return a stale take from
   an old session. It also builds and returns a `glbUrl` without ever checking
   the GLB file exists. It was deliberately left as-is (out of scope for the
   `listMocapFiles`/blendBaking work) rather than fixed in place, precisely so
   nobody mistakes its presence in the codebase as a vetted reference
   implementation.
