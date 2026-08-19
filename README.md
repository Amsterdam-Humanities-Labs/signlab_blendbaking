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

`index.html` is a single Bootstrap page with two tabs:

- **SRT bestanden** ("SRT files") — lists baked videos that have a gloss SRT,
  with search, a status filter, and ZIP download (selected rows, or everything
  matching the current filter).
- **Glossen** ("Glosses") — the aggregated base-gloss vocabulary across those
  SRTs: how often each base gloss occurs, in how many videos, its spelling
  variants, and its category. Has a "Herbouw index" (rebuild index) button and
  CSV/JSON export.

  Each gloss row expands. The panel lists every sentence containing that gloss
  — the video's base filename, the sentence text, its thema, the exact cue and
  start timecode of each occurrence (`AAP-A @ 00:00:01,470`), and SRT and GLB
  links — plus a button that ZIPs the gloss SRTs of just those sentences.

  A gloss signed twice in one sentence appears once in the panel with both
  timecodes listed. GLBs are linked per row rather than bundled: `PT-1hand`
  spans 427 videos, so a GLB archive for it would run to roughly 100 MB.

  The panel needs no extra endpoint — it joins the `occurrences` list from
  `action=glosses` against the video rows the SRT tab has already loaded.

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

- **`list`** — proxies `listMocapFiles` with `baked=1&hasGloss=1` fixed, plus
  pass-through params `mcpStatusTijdAnnotatie`, `search`, `page`, `limit`
  (default 500). Backs the SRT tab's table.
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

`bb_glosses()` builds the gloss aggregate by paging through
`listMocapFiles?baked=1&hasGloss=1` (500 rows per page) until it has every
matching video, reading each one's gloss SRT, and aggregating cues to base
glosses (`srtGloss.php`'s `gloss_aggregate()`). The result is cached to
`cache/gloss_index.json` for `BB_CACHE_TTL` (600s) and tagged with
`BB_INDEX_SCHEMA`; a cache written by older code, or one whose schema doesn't
match, is rebuilt rather than trusted with fields missing. If pagination fails
partway through, nothing is cached and an explicit error is returned — a
partial fetch must never be served as if it were the whole corpus.

`cache/` is git-ignored; deleting it is always safe; it repopulates on the next
request that needs it (or immediately on `refresh=1` / `rebuildIndex`).

`cache/` must be group-writable by `www-data` (the Apache worker user) —
`@file_put_contents(BB_CACHE, ...)` fails silently otherwise (the `@`
suppresses the warning), and every request then re-reads and re-aggregates
all gloss SRTs from disk instead of hitting the cache. `chgrp www-data cache
&& chmod 775 cache` (or equivalent) is enough; matches `/web/zin/cache`'s
`www-data:www-data 755`.

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

Runs `TestSrtGloss` (cue parsing, base-gloss folding, aggregation) and
`TestCategories` (the categorization pattern rules, and cross-checks against
`categories.json` itself). Exit code is 0 iff every test passed.

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
