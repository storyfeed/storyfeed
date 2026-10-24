---
name: storyfeed-development
description: Build activity feeds with Storyfeed (storyfeed/storyfeed). Use when making models Feedable, publishing activities with Storyfeed::activity() or Storyfeed::record(), writing headlines, icons or group headlines in routes/feed.php with Story::for()->verb(), reading feeds with Storyfeed::feed() (live, log, involving, cursorPaginate), rendering with <x-storyfeed::feed> or FeedItem, attaching bodies (Prose, Image, Table, CallToAction), composing a feed by hand with Storyfeed::compose(), testing with Storyfeed::fake(), or debugging with storyfeed:doctor.
---

# Storyfeed Development

Storyfeed is pre-1.0. When unsure, read the docs at https://docs.storyfeed.dev, or use its MCP server at https://mcp.storyfeed.dev (`search_docs`, `read_page`, `get_example`), rather than guessing an API. Unknown methods throw: there is no `__call` magic.

## Workflow

1. **Make every participating model Feedable**: implement `Storyfeed\Contracts\Feedable`, use `Storyfeed\Concerns\InteractsWithFeed`, and return `FeedEntity::make()->label(...)` from `toFeed()`. Without `toFeed()`, a default label comes from attributes such as `name`.
2. **Publish where the action happens**, in a controller, action or listener:

   ```php
   use Storyfeed\Facades\Storyfeed;

   Storyfeed::activity()
       ->by($customer)            // actor; defaults to the signed-in user
       ->action('place', $order)  // verb + object
       ->to($shop)                // target; also for() on() with() into() in() from()
       ->data(['total' => $order->total]) // frozen at publication
       ->publish();
   ```

   Other role setters: `using()` (instrument), `resulting()` (result), `context()`, `origin()`. Call `publishedAt($time)` when importing past events, `startsAt()` and `endsAt()` for a time range, and `meta([...])` for your own batch metadata.
   - A role can be an entity array with no model behind it: `->object(['type' => 'vote', 'label' => 'their vote', 'media' => ['icon' => $url]])`. A named non-model participant such as Stripe is a party: `Storyfeed::parties(['Stripe'])` declares the names.
   - `Storyfeed::anonymous()` records an unknown actor. `Storyfeed::actor($user, fn () => ...)` and `Storyfeed::context($model, fn () => ...)` scope a callback. `Storyfeed::resolveActorUsing(fn () => ...)` changes the default actor.
   - Verbs: the `Storyfeed\Act` enum (`Act::Send->actor($user)->object($doc)->publish()`) covers common ones, and `Storyfeed::verbs([...])` declares your own. `ActivityType` is Activity Streams vocabulary only and is not a verb. `Storyfeed::publish($story)` and `Storyfeed::publishNow($story)` send a one-verb Story class; `Storyfeed::route($name, $object)` records a named story.
   - Pause recording with `Storyfeed::stopRecording()` and `Storyfeed::startRecording()`, or wrap a callback in `Storyfeed::withoutRecording(fn () => ...)` or `Storyfeed::recording(fn () => ...)`. `Storyfeed::isRecording()` reads the state.
   - For a model that cannot use the trait, `Storyfeed::feedable(Model::class)` registers it; `Storyfeed::deleteFromFeed($model)` and `Storyfeed::forceDeleteFromFeed($model)` remove it, and `Storyfeed::tombstone($type, $ids)` leaves a tombstone. `Storyfeed::guessFeedLabelsUsing()` sets the default label and `Storyfeed::requireFeedableMorphMap()` requires a morph map.
3. **Describe it in `routes/feed.php`**:

   ```php
   use App\Models\Order;
   use Storyfeed\Facades\Story;

   Story::for(Order::class)->group(function () {
       Story::verb('place')->headline(':actor placed :object[ with :target]')->icon('shopping-bag');
       Story::verb('complete')->headline(':actor completed :object')->icon('receipt')->intent('success');
   });
   ```

   - A headline can be a closure that receives `Storyfeed\ActivityContext` and returns a template.
   - `Story::resource(Model::class)` defines create, update, delete and restore in one call.
   - `Story::verb('sync')->keepLatest()` keeps only the newest live row per object; pass `per: ['actor', 'object']` to keep one per actor and object, or `within: '1 hour'` to limit it to a window; older rows are soft-deleted and leave every feed. Never declare it on a timeline that needs every row. `keepFor()` and `keepForever()` set retention for `storyfeed:prune`.
4. **Read the feed**: `Storyfeed::feed()->get()`, or `->cursorPaginate(15)->withQueryString()` for pages.
5. **Check the result**: `php artisan storyfeed:doctor` reports verbs that have no headline and other definition drift, including `keep_latest.never_supersedes` (a keep-latest verb with many live rows and none superseded: key it `per: 'target'` or `per: 'context'`), `keep_latest.split` (a keep-latest verb also published without it) and `keep_latest.undeclared` (superseded rows on a verb that never declared it). `storyfeed:verbs --used` compares the definitions against the verbs actually recorded. App checks register with `Storyfeed::checks([...])`; `Storyfeed::doctor()` runs them in code.
6. **Test it**: `Storyfeed::fake()`, run the code, then `assertPublished($verb, $object)`, `assertNotPublished()`, `assertPublishedCount()`, `assertNothingPublished()`, and `assertQueued()`, `assertNotQueued()`, `assertQueuedCount()`, `assertNothingQueued()` for queued publishing; `Storyfeed::published()` and `Storyfeed::queued()` return the activities.

## Grouping

| Axis | Collapses | Singular tokens allowed |
|---|---|---|
| `repeat` | one actor repeating a verb | `:actor` `:target` |
| `actors` | many actors, same verb and target | `:target` |
| `targets` | one actor across targets | `:actor` |
| `object` | many actions on one object | `:actor` `:object` |
| `actorsOnTarget` | many actors, each on their own object, same target | `:target` |
| `context` | many entries naming one context, in a composed feed's `groupBy()` | `:context` |

```php
use Storyfeed\Grouping\GroupBuilder;

Story::for(Order::class)->verb('place')->grouped(
    fn (GroupBuilder $group) => $group->repeat(':actor placed :count orders with :target'),
);

Story::verb('place')->grouped(
    fn (GroupBuilder $group) => $group->actors(':actors ordered from :target'),
);
```

- `repeat` and `object` groups hold one type, so their headlines may sit under `Story::for()`. `actors` and `targets` headlines go on the verb alone and must not name a type.
- Plural tokens (`:actors`, `:targets`, …) are allowed in any group headline. A singular token is allowed only when every member shares that role.
- Declare an `actorsOnTarget` headline on the verb alone: comments whose object is the new comment and target is the document share one row that pins `:target`.
- Extension points for advanced grouping: `Storyfeed::axes([...])` adds an axis, `Storyfeed::bundleables([...])` marks types that bundle, and `Storyfeed::healers([...])` registers repair jobs for `storyfeed:heal`.
- Grouping happens when an activity is published. `storyfeed:curate` runs hourly to choose `live()` groups.

## Read modes and filters

- `live()` (the default) returns one-action burst rows. `log()` returns one row per activity. `summary()` is retired and throws naming Live. A burst closes after 15 quiet minutes or 4 hours; configure `grouping.bursts` or `Story::verb('upload')->bursts(within: '15 minutes', ceiling: '4 hours')`. Migrate existing history with `storyfeed:curate --rebuild-bursts` while publishers are paused.
- Filters: `involving($model)` matches any role; `actor()`, `object()`, `target()` and `context()` each match one role. `$model->storyfeed()` is `involving($model)`.
- `query(fn (ActivityBuilder $query) => ...)` adds custom constraints.
- Named feeds are audience presets: declare them once with `Storyfeed::feeds([...])`, read one with `Storyfeed::feed('name')`.
- A feed need not come from storage. `Storyfeed::feed()->source('roadmap')` reads a named source from `storyfeed.sources` (an `array` source holds static items; `Storyfeed::source('roadmap')` returns the source itself), and `Storyfeed::extend('github', fn ($app, array $config) => new GitHubSource($config))` registers a driver.
- `Storyfeed::compose()` builds a feed by hand from data you already have and reads it into the same payload: `->add(fn (Entry $entry) => $entry->by($team)->action('ship', $release)->publishedAt($date))`, `->addMany($items, fn ($item, Entry $entry) => ...)`, and `->inOrder()` to keep your order (Live never groups it). An entry takes its own `->headline(':actor shipped :object', [...])`. Model roles must be Feedable; use an entity array otherwise.
- A composed feed groups only when it asks: `->groupBy(Group::byContext()->headline(':context shipped :count changes'))` folds the entries that name one `->context($release)` into one group node, in kept order and for dateless entries too. Entries with no context, or alone in theirs, read solo. `members($node['id'])` on the same composed feed reads a group's members past its `children`. Only `byContext()` is accepted; Live infers the other axes.
- `Entry::objects($files)` instead of `object()` composes a composite, as `Storyfeed::activity()->objects()` records one: one `composite` group node in every read mode (log, live, kept order), members newest first or in kept order, never curated into Live groups and never folded into a context group. Members may be models, party names or entity arrays; `object()` with `objects()` throws. Its `headline()` is the group's and may not name `:object`. `members($node['id'])` on the same composed feed pages its members.
- An app that composes and renders without core's tables calls `Storyfeed::withoutStorage()` in a service provider's `boot()`.

## Rendering

- `composer require storyfeed/ui`, then `<x-storyfeed::feed :page="$page" />` (Tailwind v4 + `@tailwindcss/typography`).
- Customise the views with `vendor:publish --tag=storyfeed-views`.
- Custom views iterate the page: each item is a `Storyfeed\Support\FeedItem` with `headline()`, `actor()`, `publishedAt()`, `glyph()`, `intent()`, `object()->bodies()`, `children()` and `count()`.

## Bodies

Core bodies: `Prose`, `KeyValue`, `ItemList`, `Excerpt`, `MediaObject`, `FileAttachment`, `Image`, `Table`, `CallToAction`, `Code`, `Callout`, and `Component` for a renderer-owned component. Links inside bodies use `FeedLink::to($url)` or `FeedLink::toEntity()`.

```php
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\Prose;

FeedEntity::make()
    ->label($this->name)
    ->body(Prose::make($this->description))
    ->body(KeyValue::make()->items('Station', $this->station));
```

- `Image::make($url)->alt('...')->size('card')`: the size is one of `thumbnail`, `inline`, `card`, `feature` or `full` (the default), and an unknown name throws. The renderer owns the lengths. `Image::make()` with no argument shows the entity's `preview` picture.
- `Excerpt` is complete by default; call `truncated()` only when the text was cut.
- `Table::make($headers, $rows)->title('...')`; `CallToAction::make(subject: '...', content: '...')->action('See it', FeedLink::to($url))`.
- `Code::make($source)->language('diff')->title('...')`: the language is a name a highlighter takes (`php`, `diff`), or null when unknown; the fallback line is the title, else the first line of the source.
- `Callout::make('...')->intent('warning')->title('...')`: the intent is an open, app-owned word with a glyph intent's rules, defaulting to `note`, with `info()`, `success()`, `warning()` and `danger()` shortcuts; the fallback line is the title, else the content.
- Any body takes `fallback('...')` (plain text for renderers that cannot draw it) and `maxHeight('16rem')` or `fullHeight()`.
- `data()` on the activity is frozen at publication.
- `body()` in `toFeed()` is stored and updated whenever the model saves.
- `body()` on the `FeedMedia` returned by the static `feedMedia(FeedContext $context)` is built from current values when the feed is read, and never stored.

## Pitfalls

- Do not record a null actor for system work. Use a party for a named non-model participant; a null actor means "unknown".
- Compare morph types with `getMorphClass()`, never `get_class()`.
- Keep verbs as stable strings. Renaming a verb leaves old rows under the old name.
