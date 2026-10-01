---
name: storyfeed-development
description: Build activity feeds with Storyfeed (storyfeed/storyfeed). Use when making models Feedable, publishing activities with Storyfeed::activity() or Storyfeed::record(), writing headlines, icons or group headlines in routes/feed.php with Story::for()->verb(), reading feeds with Storyfeed::feed() (live, summary, log, involving, cursorPaginate), rendering with <x-storyfeed::feed> or FeedItem, attaching bodies, or debugging with storyfeed:doctor.
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

   Other role setters: `using()` (instrument), `resulting()` (result), `context()`, `origin()`. Call `publishedAt($time)` when importing past events.
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
4. **Read the feed**: `Storyfeed::feed()->get()`, or `->cursorPaginate(15)->withQueryString()` for pages.
5. **Check the result**: `php artisan storyfeed:doctor` reports verbs that have no headline and other definition drift. `storyfeed:verbs --used` compares the definitions against the verbs actually recorded.

## Grouping

| Axis | Collapses | Singular tokens allowed |
|---|---|---|
| `repeat` | one actor repeating a verb | `:actor` `:target` |
| `actors` | many actors, same verb and target | `:target` |
| `targets` | one actor across targets | `:actor` |
| `object` | many actions on one object | `:actor` `:object` |

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
- Grouping happens when an activity is published. `storyfeed:curate` runs hourly to choose `live()` groups.

## Read modes and filters

- `live()` (the default) returns grouped rows. `log()` returns one row per activity. `summary(Period::Day|Week|Month|Hour)` returns one row per actor per calendar period.
- Filters: `involving($model)` matches any role; `actor()`, `object()`, `target()` and `context()` each match one role. `$model->storyfeed()` is `involving($model)`.
- `query(fn (ActivityBuilder $query) => ...)` adds custom constraints.

## Rendering

- `composer require storyfeed/ui`, then `<x-storyfeed::feed :page="$page" />` (Tailwind v4 + `@tailwindcss/typography`).
- Customise the views with `vendor:publish --tag=storyfeed-views`.
- Custom views iterate the page: each item is a `Storyfeed\Support\FeedItem` with `headline()`, `actor()`, `publishedAt()`, `glyph()`, `intent()`, `thread()`, `object()->bodies()`, `children()` and `count()`.

## Bodies

```php
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\Prose;

FeedEntity::make()
    ->label($this->name)
    ->body(Prose::make($this->description))
    ->body(KeyValue::make()->items('Station', $this->station));
```

- `data()` on the activity is frozen at publication.
- `body()` in `toFeed()` is stored and updated whenever the model saves.
- `body()` on the `FeedMedia` returned by the static `feedMedia(FeedContext $context)` is built from current values when the feed is read, and never stored.

## Pitfalls

- Do not record a null actor for system work. Use a party for a named non-model participant; a null actor means "unknown".
- Compare morph types with `getMorphClass()`, never `get_class()`.
- Keep verbs as stable strings. Renaming a verb leaves old rows under the old name.
