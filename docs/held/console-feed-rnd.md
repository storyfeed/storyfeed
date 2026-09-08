# W93 — console feed feasibility, held for an owner decision

Investigated 2026-09-08 on branch `w93-console-feed-rnd`, based on core
`f2658e8`. Worktree: `/tmp/storyfeed-w93`. Nothing in `src/`, the contract,
configuration files, migrations, or sibling repositories changed. Nothing merges.

**A readable, coloured, live feed is feasible at negligible renderer cost.
Termwind is a useful authoring layer, already installed by Laravel. Real inline
images are reachable through terminal graphics protocols, but this run establishes
image encoding and transport framing, not a visually verified photo-feed demo.**
The installed iTerm2 and kitty applications could not be controlled by the available
computer-use tool. Do not confuse the successful byte tests below with seeing pixels.

There are two evidence gaps: the stock demo has no typed image slots and no
missing-snapshot entities; and terminal GUI inspection is blocked. The requested
real degraded-entity transcript is therefore not supplied. The missing
context shown below is an unpinned group role, **not** a degraded entity. No fake
activities, labels or photos have been substituted into the feed to disguise either
gap. An optional request to the owner for labelled payload-copy probes was pending
when this report was prepared; those probes are not part of this prototype.

## Run it

From this branch's worktree, with PHP 8.4+ and its existing Composer development
dependencies installed:

```sh
cd /tmp/storyfeed-w93
composer install
php workbench/console/artisan w93:init
php workbench/console/artisan storyfeed:demo --days=7 --seed=1
php workbench/console/artisan storyfeed:console --rung=termwind --limit=8 --interval=2
```

This is a real Laravel artisan application bootstrapped by the existing Testbench
installation, with commands registered in `workbench/console/artisan`. It never
loads a host `.env`. Its SQLite file is exclusively `build/w93.sqlite` in this
worktree. `w93:init` creates missing demo schema using existing migration stubs;
it does not reset existing data. Seeding uses the actual shipped
`storyfeed:demo`, which printed **105 activities across seven days**. Repeating
seeding appends more demo activities; use the existing `storyfeed:demo --fresh`
only when deliberately resetting this disposable demo database.

On this machine, Homebrew PHP aborts loading missing `libvmaf.1.dylib`. The verified
runtime was `/Users/jasper/Library/Application Support/Herd/bin/php85` (8.5.8).
Use that explicit binary for `php` above here; this is a local toolchain problem,
not an extra prototype dependency.

Escalate the same feed, one rung at a time, inside a terminal:

```sh
php workbench/console/artisan storyfeed:console --once --rung=ascii
php workbench/console/artisan storyfeed:console --once --rung=box
php workbench/console/artisan storyfeed:console --once --rung=256
php workbench/console/artisan storyfeed:console --once --rung=truecolor
COLORTERM=truecolor php workbench/console/artisan storyfeed:console --once --rung=termwind
php workbench/console/artisan storyfeed:console --once --expanded --limit=2
php workbench/console/artisan storyfeed:console --once --width=36
php workbench/console/artisan storyfeed:console > build/w93-plain.txt
```

`NO_COLOR`, `--no-ansi`, `TERM=dumb`, and non-TTY output select plain ASCII
chrome and no graphics. Piping emits **one frame and exits**, even without
`--once`. Unicode names remain Unicode: ASCII means the renderer's chrome,
not destructive transliteration of supplied text. The command intentionally does
not let `--ansi` override pipe safety. If a development runner sets `NO_COLOR`,
remove it deliberately for the colour comparison (`env -u NO_COLOR ...` on Unix).

For live rewrite evidence, run in another shell:

```sh
cd /tmp/storyfeed-w93
php workbench/console/artisan storyfeed:curate --rehash
```

For the automated two-process experiment, including a PTY resize and exit check:

```sh
python3 workbench/console/verify-live.py '/Users/jasper/Library/Application Support/Herd/bin/php85'
```

Ctrl-C exits the watcher and restores its alternate screen/cursor on the tested
Unix path. `--polls=4` makes a bounded rehearsal. Keep a small head page on stage;
this prototype has no scrolling viewport or keyboard disclosure.

## Rendering ladder: benefit, cost, stopping point

These are measured costs, not estimates of terminal paint latency. To repeat:

```sh
php workbench/console/artisan storyfeed:console --json > build/w93-payload.json
php workbench/console/measure.php
```

Actual output, using eight real head nodes, 80 columns and 100 renders. Timed region
excludes Laravel boot, database access, network access and terminal paint:

```text
PHP 8.5.8; 8 real head nodes; 100 renders at 80 columns; no DB or network in timed region
ascii      median= 0.226 ms p95= 0.345 ms bytes=940 SGR=0 RGB=0
box        median= 0.232 ms p95= 0.433 ms bytes=1456 SGR=0 RGB=0
256        median= 0.228 ms p95= 0.296 ms bytes=2062 SGR=76 RGB=0
truecolor  median= 0.232 ms p95= 0.309 ms bytes=2366 SGR=76 RGB=38
termwind   median= 3.240 ms p95= 4.391 ms bytes=2967 SGR=112 RGB=56
Termwind img element: "before\nafter\n"
```

| Rung | What it buys | What it costs / where it stops |
| --- | --- | --- |
| ASCII | Initial badges, rail, separators, wrapped sentences, honest groups, timestamps | No typography beyond characters; photos cannot exist as pixels here. Domain labels remain intact. |
| Unicode box drawing | Cleaner continuous rail and rule | UTF-8 font support and cell-width accounting. Wide/combined graphemes are harder than bytes. |
| 256 colours | Muted connecting words, bright bold entities even without URLs | Indexed palette reduces subtle colours; terminal theme still influences perception. ~2 KB for this frame. |
| 24-bit raw ANSI | Exact RGB noun/muted palette | Terminal must support RGB; this explicit rung emits it without negotiation. No new visual structure or pixels. ~2.4 KB. |
| Termwind | HTML-ish nested node markup; utility colours, weight and indentation; emits RGB via Symfony when supported | Roughly 14x the raw renderer's CPU here, but only ~3 ms per frame. Requires escaping two markup layers and deliberate whitespace/cell layout. No image element. |
| Half blocks | A real raster reduced to foreground/background colours on `▀` characters | Optional GD resampling, two pixels per cell, low spatial resolution. Recognisable coarse artwork is plausible, fine photo detail is lost. Not visually verified here. |
| iTerm2 / kitty | Actual image pixels at a chosen cell width, alongside text | Fetch, decode, protocol bytes, cache, placement and redraw lifecycle. The terminal becomes a material runtime dependency. Encoding tested; paint unverified. |
| SIXEL | Actual raster in SIXEL-capable terminals, including a Windows route | This experimental encoder uses 64 colours, 256px maximum width, no dithering/RLE. Palette banding and greater implementation burden; not a production encoder and not visually verified. |

The colour rungs are achievable and useful. Character blocks are achievable but
likely ugly for detailed photos. A pixel protocol can preserve actual photographic
detail; the loss of browser-like typography, layout and interaction is a different
limit. It would be misleading to say “a terminal cannot get close” globally. It can
get close to a compact read-only timeline in a selected terminal. This prototype
stops at cell-based layout, static group expansion, absent arbitrary component
adapters, and unverified image placement. It does not reproduce the browser feed's
interaction or visual finish.

Rasterising the entire browser-style card could mimic its appearance, but then
text is pixels, selection/search/accessibility suffer, every update resends an
image, and the useful independent text-renderer demonstration has been replaced
by a screenshot viewer. That is not worth pursuing for this talk.

## Termwind versus the reference node

The owner's dependency correction is verified: installed `laravel/framework`
**v13.24.0** directly requires `nunomaduro/termwind: ^2.0`; installed Termwind is
**v2.4.0**, Symfony Console **v8.1.4**. The requirement also appears in the
[Laravel 13 manifest](https://github.com/laravel/framework/blob/13.x/composer.json).
This costs **zero new Composer packages in this Laravel application**. Collision,
Pest or Pail are not needed to justify Termwind's presence. Core's own manifest,
however, only requires Illuminate contracts, not the complete framework: do not
turn this into a promise about arbitrary non-Laravel consumers.

The structural correspondence is real:

```html
<div class="ml-2">
  <div>
    <span class="text-slate-600">│&nbsp;</span>
    <span class="font-bold text-cyan-300">[PR]&nbsp;Priya&nbsp;Raman</span>
    <span class="text-slate-400">&nbsp;approved&nbsp;</span>
    <span class="font-bold text-cyan-300">Packaging&nbsp;Comps</span>
  </div>
  <div class="text-slate-600">└──────────────────────────────</div>
</div>
```

This is the same broad rail/body/headline/entity-span/child-node decomposition as
`node.blade.php`. Token substitution, no-URL entity emphasis, unknown labels,
count truth and per-activity details can transfer. A snapshot-backed Party needs
no domain knowledge in either renderer. The experiment consumes `get()->toArray()`;
it does not import the reference adapter's presenter or query its models.

It is **not** the same markup with a different stylesheet:

- The Blade reference consumes a prepared presentation array (`avatars`, rendered
  headline HTML, `timestamp`, `media`, `details`, `expanded`, etc.). These are not
  all raw Payload v1 keys. The console must own those presentation decisions.
- Termwind parses a supported subset of HTML. Its installed `HtmlRenderer` supports
  `div`, `span`, lists, links, tables and certain text elements; unknown elements
  fall back to a generic div. The measured `<img>` probe emits no image or alt text.
  The project describes this authoring model in its
  [upstream README](https://github.com/nunomaduro/termwind).
- Utility classes are not browser CSS. Grid/flex behaviour, pixel geometry,
  rounded images, object-fit, responsive CSS and Alpine/Livewire do not transfer.
  The prototype explicitly wraps into cell-width lines before emitting markup.
- Span boundaries trimmed ordinary spaces on the first real run:
  `Priya RamanapprovedPackaging Comps`. The adapter now uses non-breaking spaces
  after its own wrapping. That repair is covered against real seeded headlines.
- Headline connective text stays muted and every substituted entity is bright/bold.
  Null `url` never flattens an entity into the surrounding sentence.
- `a` exists in Termwind and terminal hyperlinks are possible, but this prototype
  does not implement clickable entity URLs, mouse controls or modal semantics.
- Both paths need translation policy. This English R&D renderer uses the supplied
  templates as-is and owns English fallback/count/time text; it is not an i18n kit.

Raw ANSI buys deterministic colour escape sequences, alternate-screen clearing,
cursor visibility and graphics protocol placement outside Termwind's text parser.
It does **not** buy a higher text-layout ceiling merely by bypassing Termwind.
Both ultimately render into terminal cells.

## Images: evidence and terminal matrix

The shipped demo has **no media**. `--images=iterm2|kitty|sixel|blocks` is implemented
for real `entity.media.icon`, `preview` and `image` when present, but it produces no
photos from the untouched demo. It never substitutes `media.url` (the full image)
for a missing thumbnail. Icon pictures currently render below the row rather than
inside the initials rail. Groups retain initials from actor exemplars; group
exemplar image composition is not implemented.

A separate transport experiment uses the existing NASA asset
[PIA18033](https://images.nasa.gov/details/PIA18033), **not a feed fixture**, and
shares the feed renderer's media path. No asset is committed. Download it into
ignored build output and serve it locally to remove internet variability:

```sh
curl -L --fail 'https://images-assets.nasa.gov/image/PIA18033/PIA18033~small.jpg' -o build/w93-source.jpg
python3 -m http.server 8933 --bind 127.0.0.1 --directory build
# Another shell:
php workbench/console/graphics-probe.php http://127.0.0.1:8933/w93-source.jpg
# In the corresponding actual terminal, explicitly request paint:
php workbench/console/graphics-probe.php http://127.0.0.1:8933/w93-source.jpg --emit=iterm2
php workbench/console/graphics-probe.php http://127.0.0.1:8933/w93-source.jpg --emit=kitty
php workbench/console/graphics-probe.php http://127.0.0.1:8933/w93-source.jpg --emit=sixel
php workbench/console/graphics-probe.php http://127.0.0.1:8933/w93-source.jpg --emit=blocks
```

The measurement mode writes `.ansi` artifacts under `build/`, without spilling
binary protocols to its normal stdout. `--emit` paints only on a TTY. Stop the local
HTTP server after the experiment.

Actual output (640×640 JPEG, 68,407 input bytes):

```text
iterm2: cold fetch+convert+encode=50.53 ms; warm=0.240 ms; wire=702641 bytes; chunks=1; decoded PNG=526892 bytes; framing PASS
kitty: cold fetch+convert+encode=52.33 ms; warm=0.190 ms; wire=704159 bytes; chunks=172; decoded PNG=526892 bytes; framing PASS
blocks: decode+resample+encode=6.16 ms; wire=16842 bytes; no terminal paint verified
sixel: decode+resample+encode=23.07 ms; wire=114707 bytes; no terminal paint verified
```

The framing check reassembles base64 and verifies a decodable PNG; kitty payload
chunks are at most 4096 bytes and the final continuation flag is zero. SIXEL only
has an encoder/emission measurement, not a terminal decoder round trip. The first
remote HTTP image attempt timed out and returned a readable unavailable caption;
local serving made the transport test repeatable. These numbers exclude paint.

The common PNG path inflated this JPEG nearly eightfold, before base64. iTerm2
could transmit JPEG directly; kitty's PNG mode needs PNG (or raw RGB). The current
implementation deliberately shares conversion, so **~0.7 MB per displayed image
per frame** is the measured cost, not a protocol minimum. At two seconds this is
~0.35 MB/s per image, ~2.8 MB/s for eight. Cache hits eliminate fetch/conversion,
not repeated transport. Small source previews and retained kitty image IDs would
make a substantial difference. The prototype clears/retransmits images instead.

| Terminal / environment | Documented image route | Evidence level here |
| --- | --- | --- |
| iTerm2, macOS | [OSC 1337 inline images](https://iterm2.com/documentation-images.html); also SIXEL in supported releases | Installed 3.4.3; byte encoding tested; app inspection blocked. No claimed visual pass. |
| kitty, macOS/Linux | [Kitty graphics](https://sw.kovidgoyal.net/kitty/graphics-protocol/), direct PNG chunks | Installed 0.26.3; byte encoding tested; app inspection blocked. No claimed visual pass. |
| Ghostty, macOS/Linux | [Kitty graphics is documented](https://ghostty.org/docs/features); maintainer explicitly [declines SIXEL](https://github.com/ghostty-org/ghostty/discussions/2496) | Not installed/tested. Do not route SIXEL to Ghostty. |
| WezTerm | [iTerm protocol](https://wezterm.org/imgcat.html), kitty implementation documented in [change log](https://wezterm.org/changelog.html), [SIXEL support with limitations](https://wezterm.org/escape-sequences.html) | Not installed/tested. Kitty edge cases differ by release; no universal parity claim. |
| Windows Terminal | SIXEL introduced in [Preview 1.22](https://devblogs.microsoft.com/commandline/windows-terminal-preview-1-22-release/) | No Windows GUI tested; verify installed stable version and actual paint before choosing it for the talk. |
| xterm with graphics enabled | [SIXEL, conditional on build/configuration](https://invisible-island.net/xterm/manpage/xterm.html) | Not installed/tested; `TERM=xterm` alone does not establish support. |
| Generic terminal, IDE console, pipe, CI log | Plain text; colour where supported | Plain/PTY paths exercised. No graphics assumed. |

Modern iTerm2 release notes explicitly mention
[SIXEL decoder fixes](https://iterm2.com/downloads.html); that is support evidence,
not proof that every old release behaves identically. The prototype never guesses
a protocol from `$TERM`; the user explicitly selects one. Kitty has a query/reply
capability probe, and iTerm2 documents feature reporting; negotiation, tmux/screen
passthrough and SSH hop testing are not implemented. A wrong explicit protocol may
silently draw no image, leave blank space, or show escape debris on poor emulators.
The text row/caption remains the intended fallback.

A local surprise: installed Symfony Console 8.1.4 already contains internal
`Terminal/Image/ITerm2Protocol` and `KittyGraphicsProtocol` encoder classes. Those
are `@internal`, so this experiment does not couple to them. Their presence makes
future upstream reuse worth watching, but does not establish availability in the
Laravel 12 / Symfony 7 lane or give Termwind an `<img>` renderer.

The media loader allows explicit HTTP(S), rejects redirects, caps downloads at
512 KiB and rasters at four million pixels, uses a two-second timeout and a
32-entry in-memory cache. Non-PNG conversion, blocks and SIXEL need GD. Cache keys
are image URLs inside one disposable process/feed; no cross-feed persistence.
Failures retain the caption and row. This is a trusted demo loader, not a hardened
URL-fetch service: private network destinations are not filtered, signed URL
expiry is not refreshed within the cache, auth headers are not supplied, failed
URLs retry, and a list of cold failures can stall for several seconds. Never point
this isolated demo at production feeds to obtain prettier photos.

## Live means polling a head page

The only renderer read is:

```php
Storyfeed::feed()->only(['demo.*'])->summary()->limit($limit)->get()->toArray();
```

A fresh builder is created each time. No entity queries, snapshot access,
resolver calls or knowledge of Party storage is in the renderer. The harness
alone initializes schema and invokes the supplied seeder.

This is a **head-page monitor**, not an accumulated history reader. Every poll
replaces nodes and `next_cursor` together. That avoids stale singleton/group
merges and means a live group can change while keeping its ID. Older rows are
honestly advertised as available but not loaded. An empty page is reported as
empty; only null `next_cursor` produces “End of feed.” There is no load-more path
to mistake an empty page for exhaustion or reuse an old epoch cursor.

On a changed opaque `sync_token`, the command discards its state, performs a second
fresh head read, clears/redraws in its alternate screen, shows RESYNC, and adopts
the new token only after rendering succeeds. Null is a real baseline and changes
from null are detected. The token itself is never parsed, ordered or printed. A
failed read/render does not acknowledge it, so the next poll can retry. There is
no persisted stream/epoch state. The real rehash experiment proves signal and
refetch behaviour; because this monitor never scrolls deep, it does **not** prove
a multi-page accumulated-history client's reconciliation.

A two-second interval is a sensible demo starting point: roughly two seconds plus
read/render/image time to show a change. It is delay-after-work, not a fixed-rate
scheduler. Warm three-node reads/render/Unix size checks in the run below took
24–30 ms; startup was 56 ms. More frequent polling mainly adds database work, not
useful smoothness. PHP sleeps between polls; this is not a websocket listener.

The contract describes activity-published/deleted and batch-close event snapshots.
A host can use them to wake a consumer and then read the payload, but no complete
renderer push stream or universally available history-rewrite notification is
promised. Adding a broadcaster/queue/IPC service costs host integration and still
needs resync handling and periodic recovery. No extra transport is warranted for
this talk. Also, plain `curate` does **not** bump `sync_token`; use `--rehash` for
the demonstrated reset. The stock seeder itself also bumps the token, so an
additional seed is a reset demonstration, not proof of ordinary append-only live
publishing. The contract's “only backfills bump it” shorthand omits that demo path.

Actual output from the separate watcher and rehash processes (SGR/cursor controls
removed; NBSP normalised to spaces; full capture is `build/w93-live.txt`):

```text
curate exit: 0
Curated 105 activities.
watch exit: 0
STORYFEED | termwind | frame 1 | 55.8 ms | 100 cols
│ [PR] Priya Raman approved Packaging Comps  | 6h ago
└──────────────────────────────
│ [MA] Marcus Adeyemi updated Ship staging build 2 times  | 7h ago
│ [+] 2 activities; 2 children supplied (use --expanded)
└──────────────────────────────
│ [MA] Marcus Adeyemi commented on Colour Study  | 10h ago
└──────────────────────────────
Older history available; this prototype shows the head page only.
STORYFEED | termwind | frame 2 | 29.5 ms | 100 cols
RESYNC: history rewritten; discarded nodes + cursor; refetched head.
│ [PR] Priya Raman approved Packaging Comps  | 6h ago
└──────────────────────────────
│ [MA] Marcus Adeyemi updated Ship staging build 2 times  | 7h ago
│ [+] 2 activities; 2 children supplied (use --expanded)
└──────────────────────────────
│ [MA] Marcus Adeyemi commented on Colour Study  | 10h ago
└──────────────────────────────
Older history available; this prototype shows the head page only.
STORYFEED | termwind | frame 3 | 24.5 ms | 36 cols
│ [PR] Priya Raman approved Packag
│ ing Comps  | 6h ago
└──────────────────────────────
│ [MA] Marcus Adeyemi updated Ship
│  staging build 2 times  | 7h ago
│ [+] 2 activities; 2 children sup
│ plied (use --expanded)
└──────────────────────────────
│ [MA] Marcus Adeyemi commented on
│  Colour Study  | 10h ago
└──────────────────────────────
Older history available; this prototype shows the head page only.
PASS: real rehash triggered resync; 100 -> 36 columns; alternate screen restored.
```

The final PASS follows a fourth frame omitted here for length; the checked-in
script checks RESYNC, both widths and alternate-screen entry/exit escape codes.
It does not claim a screenshot of the reset.

## Payload truth and graceful degradation

| Fact | Treatment / ceiling |
| --- | --- |
| Null actor | `Someone`; never a fabricated system identity. `[?]` badge when no actor exemplars. |
| Entity object with null label | `[unavailable actor/object/etc.]`, preserving the row and the distinction from anonymity. Code path implemented; no stock-demo evidence for it. |
| Non-null label without URL | Full noun weight. This is almost the entire stock demo. |
| Plural roles | All seven roles supported; join exemplar labels and append overflow from `distinct`. Never promote an exemplar into an unpinned singular. |
| Group with no headline | Bare total activity count. No invented sentence based on its first child. Unknown axes use the same generic group handling. |
| Group children | Closed marker by default; `--expanded` recursively prints every supplied child. `children_truncated` prints the missing count. No claim that supplied children are all members. |
| Relative time | Under a minute “just now”, then minutes, hours, days through a week, then absolute date/time/zone. Future dates use absolute time; null/invalid dates remain a row with unknown time. |
| Thread/change/entity body | Thread quote and count, null-aware before/after values, portable entity body text. Raw Markdown is readable source, not a rich Markdown renderer; HTML bodies are stripped to text. These slots are absent in the stock demo and need more integration coverage. |
| Arbitrary data/component/glyph | No domain interpretation. Unknown component is labelled unsupported; arbitrary data and icon-set glyphs are not painted. A component token cannot supply the implementation it names. |
| Media failure | Caption/unavailable reason; row retained. Full media URL is not silently downloaded as a preview. |
| Terminal controls in content | C0/C1 controls stripped before output, then HTML and Symfony formatter text escaped independently. No payload-authored control sequence is trusted. |

A real expanded group, from `--once --expanded --limit=2`:

```text
STORYFEED | ascii | frame 1 | 43.3 ms | 84 cols
| [PR] Priya Raman approved Packaging Comps  | 6h ago
+------------------------------
| [MA] Marcus Adeyemi updated Ship staging build 2 times  | 7h ago
| [-] 2 activities; 2 children supplied
+------------------------------
  | [MA] Marcus Adeyemi completed Ship staging build  | 7h ago
  +------------------------------
  | [MA] Marcus Adeyemi completed Ship staging build  | 7h ago
  +------------------------------
Older history available; this prototype shows the head page only.
```

A stock-demo contract finding, from the original eight-row capture:

```text
| [BF] Bo Feldman uploaded 7 files to [unknown context]  | 13h ago
| [+] 7 activities; 7 children supplied (use --expanded)
+------------------------------
```

The emitted group template asks for `:context`, but its singular `context` is
null. This prototype refuses to copy `exemplars.contexts[0]` into the sentence.
That would reintroduce the exact unpinned-role lie the contract prohibits. The
shipped demo vocabulary authors `:context` on that aggregate; changing it is
outside this brief's read-only core scope. The contract gave enough information
to expose the mismatch rather than conceal it.

**The brief's “everything has a right answer in the payload” is too strong for
arbitrary component visuals, glyph sets and unavailable media.** The contract
makes a truthful degraded row possible. It does not carry an app's component
implementation, a glyph font, auth headers for an image request, terminal pixel
geometry or an assurance that an image URL resolves from this host. That is the
specific boundary of the independent-renderer argument.

## Where it belongs — recommendation, not a ruling

| Home | Benefit | Cost / decision |
| --- | --- | --- |
| Core `src/Console` | Easy discovery/registration, demo command precedent, no additional Laravel dependency | It is unequivocally a view layer. The present arch test excludes Illuminate View and View/Blade facades, not Termwind. Passing it is not permission to reinterpret “core stays headless.” Requires an explicit owner exception or policy change. |
| `storyfeed/ui` | Existing renderer ownership; headless core preserved | Adds a terminal target to the web renderer package's lifecycle and tests. This app already supplies Termwind, but users must install the UI package to get this demo. Separate entry points and terminal capability policy needed. |
| Separate demo package / workbench | Clean experiment ownership; console and image compatibility can change without core promises | Additional package/release/documentation setup if distributed. A workbench command is less convenient than a globally discovered artisan command. |

**Recommendation: keep this workbench experiment held; if the talk needs a
reusable distributable, use a small separate demo package.** `storyfeed/ui` is a
reasonable alternative if terminal rendering becomes a supported product target.
The owner should rule explicitly before any placement in core. No placement
policy is changed by this branch, and no dependencies were added.

Decision note for the talk/journal: the arch test's name says “no view or UI
dependencies” while its concrete list only forbids Laravel view types. A package
can pass that test while parsing HTML-ish markup to draw a UI. Termwind makes the
authoring argument stronger and the policy mismatch sharper at the same time.
This decision note lives in the allowed report; no out-of-scope journal file was
edited.

## Every dependency and demo embarrassment

- Existing PHP 8.4+ / Laravel application, Symfony Console and Termwind; Composer
  changes: **none**. DOM/libxml for Termwind and mbstring for cell counting are
  already part of the installed stack.
- Existing Orchestra Testbench is the standalone harness, a dev dependency,
  **not** a renderer requirement in a consuming Laravel app. PDO SQLite is needed
  only for this isolated database harness.
- Optional `ext-gd` for non-PNG conversion, SIXEL and block images. Raw PNG through
  iTerm2/kitty does not require GD. HTTP(S) uses PHP streams; HTTPS needs a working
  TLS/CA setup. No curl binary is needed by the runtime; the example download uses it.
- Optional Unix `stty` for refreshed dimensions and `pcntl` for handled termination.
  The fallback gets Symfony's initial dimensions. No ncurses, Node, browser runtime,
  Termwind installation, image executable or new Composer package is required.
- Python 3 is **only** for the repeatable PTY experiment and optional local image
  HTTP server. `measure.php` and the runnable feed need no Python.
- SIXEL could instead use libsixel's `img2sixel` for better quantisation/dithering,
  but that would add an executable/system library and portability/install work.
  None was installed; the limited PHP encoder is explicitly experimental.

What would embarrass the live demo:

1. Claiming photos from the shipped seeder: there are none. Claiming the unpinned
   context marker proves missing-snapshot degradation would also be false.
2. Choosing a terminal based only on `$TERM`, or using an untested tmux/SSH/IDE
   route. Test actual pixels, redraw, scrolling and Ctrl-C on the presentation
   laptop before promising the photo moment.
3. Eight rows with expanded children/images can exceed the screen. Full-screen
   redraw has no vertical viewport/clipping/scroll controller; overflow can scroll
   the headline off-screen and flicker. Use a small page (`--limit=3`) for rehearsal.
4. Narrow terminals retain facts but split words. The 36-column output above is
   truthful and ugly. Header/status lines can wrap natively; complex emoji/ZWJ and
   combining-grapheme widths are not solved by `mb_strwidth` codepoint wrapping.
5. Resize is measured on Unix; Windows resizing and signal cleanup are not proven.
   SIGKILL cannot run cleanup. Modern Windows colour/graphics support is a terminal
   feature, not proof that this watcher behaves identically there.
6. A remote image timeout can stall the synchronous frame. Images retransmit on
   every redraw, cached or not. Warm pictures are not a zero-bandwidth operation.
7. The default muted palette assumes a dark background. Light themes/projectors,
   low contrast, font substitution and image cursor placement need human visual QA.
8. The script is a head monitor, not an interactive history browser. `[+]` documents
   `--expanded`; it is not a clickable disclosure. No unsupported interaction is
   presented as working.

## Validation and shipping record

Validation is against the R&D worktree and its installed PHP 8.5.8 / Laravel
13.24.0 stack, not a claim about every supported combination.

- Targeted: `php85 -d zend.assertions=1 vendor/bin/pest tests/Console/ConsoleFeedRndTest.php`
  — exit 0, **3 passed, 151 assertions** on the first pass. Tests seed with the real
  demo command and verify all supplied node/child headlines, Termwind noun spacing,
  and narrow row width.
- Full suite: same PHP invocation with `vendor/bin/pest` — exit 0,
  **1024 passed, 1 warning, 5825 assertions, 54.80s**. The warning is an include of
  a missing generated PHPStan cache file in unchanged `FeedMakeArityRuleTest`.
  This is warning-bearing evidence, not an unqualified clean run. No baseline
  reproduction has been claimed for this run.
- Final full rerun after implementation: `php85 -d zend.assertions=1 vendor/bin/pest`
  — exit 0, **1025 passed, 5825 assertions, 26.66s**, no warning. The earlier
  warning-bearing run remains recorded above; a successful warm rerun does not
  establish why the first cache file was missing.
- Final `php85 vendor/bin/phpstan analyse --no-progress` — exit 0, no errors.
- Final `php85 vendor/bin/pint --test workbench/console tests/Console/ConsoleFeedRndTest.php`
  and separate `php85 vendor/bin/pint --test workbench/console/artisan` — both
  exit 0. The extensionless bootstrap was explicitly included.
- `git diff --cached --check` passed; all nine changed paths are allowlisted.
  A recursive comparison of worktree `src/` with the starting checkout found
  zero differing bytes. Privacy-name sweep found no matches in the additions.
- Remote CI state is recorded after the branch-only push below. No PR or merge
  is authorized or performed.
- Real SQLite seeding, direct head rendering, actual two-process rehash/PTY resize,
  ANSI/RGB measurements and graphics byte/framing experiments are reported above.
- GUI terminal rendering, Windows GUI behaviour, actual degraded-entity output,
  multi-page history reconciliation and a photo-containing real feed remain
  unverified. Nothing in this report promotes those to a pass.

### Remote verification

Implementation commit: `1e4274217e24b4620f26a9b1a73ff53cd76131ad`.
It was pushed only to `origin/w93-console-feed-rnd`.

- [run-tests](https://github.com/storyfeed/storyfeed/actions/runs/34287731116):
  **completed / success, all 16 jobs passed** — PHP 8.4/8.5 × Laravel 12/13 ×
  lowest/stable × Ubuntu/Windows.
- [PHPStan](https://github.com/storyfeed/storyfeed/actions/runs/34287731168):
  **completed / success** on that implementation commit.
- [Formatting](https://github.com/storyfeed/storyfeed/actions/runs/34287731173):
  **completed / success** on that implementation commit.

Those Windows jobs establish automated test compatibility, not Windows terminal
GUI rendering or watcher signal/resize behaviour. The final follow-up changes
only this report to record remote verification. Its SHA is available with
`git rev-parse HEAD`; the executed CI SHA above remains the implementation SHA.
No PR was opened and no merge was performed.
