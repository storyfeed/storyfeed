## Storyfeed

Storyfeed records activity feeds for Laravel: an app publishes activities explicitly ("who did what to what") and reads them back as a timeline, grouped rows or per-actor summaries, all from one JSON payload contract. Core is headless; `storyfeed/ui` renders it in Blade. Storyfeed is pre-1.0, so check https://docs.storyfeed.dev before relying on a remembered API. The `storyfeed-development` skill covers grouping, bodies and parties in more depth.

### Conventions

- Models that appear in activities implement `Storyfeed\Contracts\Feedable` and use `Storyfeed\Concerns\InteractsWithFeed`; `toFeed()` returns a `FeedEntity`.
- Headlines, icons and group headlines live in `routes/feed.php` (created by `php artisan storyfeed:install`), never inline where the activity is published.
- Verbs are free-form strings in the base form (`place`, `complete`, `reprice`); the headline turns them into prose.
- Roles follow Activity Streams 2.0: `actor`, `object`, `target`, `context`, `origin`, `result`, `instrument`.
- An activity with no actor is anonymous (who did it is unknown). A named non-model participant such as Stripe is a party, not a null actor.
- Run `php artisan storyfeed:doctor` to check definitions against what is recorded.

### Feedable models

@verbatim
<code-snippet name="A Feedable model" lang="php">
use Illuminate\Database\Eloquent\Model;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedEntity;

class Order extends Model implements Feedable
{
    use InteractsWithFeed;

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make()->label("Order #{$this->reference}");
    }
}
</code-snippet>
@endverbatim

### Publishing activities

`by()` sets the actor (defaulting to the signed-in user), `action($verb, $object)` sets the verb and object, and `to()` sets the target. `data()` stores values frozen at publication.

@verbatim
<code-snippet name="Publish an activity" lang="php">
use Storyfeed\Facades\Storyfeed;

Storyfeed::activity()
    ->by($customer)
    ->action('place', $order)
    ->to($shop)
    ->publish();

// Equivalent, with named arguments:
Storyfeed::record(verb: 'place', object: $order, actor: $customer, target: $shop);
</code-snippet>
@endverbatim

### Headlines in routes/feed.php

Role tokens (`:actor`, `:object`, `:target`, …) become linked labels. Square brackets mark an optional segment that is dropped when its role is empty.

@verbatim
<code-snippet name="Define a headline" lang="php">
use App\Models\Order;
use Storyfeed\Facades\Story;

Story::for(Order::class)
    ->verb('place')
    ->headline(':actor placed :object[ with :target]')
    ->icon('shopping-bag');
</code-snippet>
@endverbatim

### Grouping

Related activities collapse into one row. Give each group axis its own headline with `grouped()`. `:count` is the number of activities in the group.

Live prefers many actors on one object, then many actors on one target, before a person's rows across things. For comments whose object is the new comment and target is the document, use `actorsOnTarget()` (or `Group::byActorsOnTarget()`). It pins `:target`, while the objects stay in samples/children. Declare this headline on the verb: the target axis may mix object types.

@verbatim
<code-snippet name="Group headlines" lang="php">
use App\Models\Order;
use Storyfeed\Facades\Story;
use Storyfeed\Grouping\GroupBuilder;

Story::for(Order::class)->verb('place')->grouped(
    fn (GroupBuilder $group) => $group->repeat(':actor placed :count orders with :target'),
);

// The targets axis may mix types and goes on the verb alone:
Story::verb('place')->grouped(
    fn (GroupBuilder $group) => $group->targets(':actor ordered across :targets'),
);

Story::verb('comment')->grouped(
    fn (GroupBuilder $group) => $group->actorsOnTarget(':actors commented on :target'),
);
</code-snippet>
@endverbatim

### Reading the feed

`Storyfeed::feed()` returns a builder. `get()` returns a collection of nodes (`FeedItem` readers); `cursorPaginate()` and `simplePaginate()` return Laravel paginators with the nodes in `data`, plus `payload_version` and `sync_token`. All serialize to JSON, so you can return them from a route; page a feed with `cursorPaginate()`. There is no `paginate()`. The read modes are `live()` (one-action bursts, and the default) and `log()` (one row per activity). `summary()` is retired and throws naming Live. Live closes after 15 quiet minutes or a 4-hour ceiling; configure `grouping.bursts` or a verb's `bursts(within: '15 minutes', ceiling: '4 hours')`. Filter with `involving($model)`, `actor()`, `object()`, `target()` or `context()`.

@verbatim
<code-snippet name="Read the feed" lang="php">
use Storyfeed\Facades\Storyfeed;

Storyfeed::feed()->get();
Storyfeed::feed()->involving($order)->log()->limit(20)->get();
Storyfeed::feed()->live()->get();
$order->storyfeed()->get(); // the same as involving($order)
</code-snippet>
@endverbatim

### Rendering

Use `storyfeed/ui` (Tailwind v4 and the Typography plugin) with @verbatim`<x-storyfeed::feed :page="$page" />`@endverbatim, or iterate the page yourself. Each item is a `Storyfeed\Support\FeedItem` with `headline()`, `actor()`, `publishedAt()`, `children()` and `count()`.

### Bodies

An entity can carry bodies (`Storyfeed\Body\Prose`, `KeyValue`, `ItemList`, `Excerpt`, `MediaObject`, `FileAttachment`). Bodies added in `toFeed()` are stored and updated with the model. Bodies added in `feedMedia()` are built from current values each time the feed is read.

@verbatim
<code-snippet name="Bodies on an entity" lang="php">
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\Prose;

return FeedEntity::make()
    ->label($this->name)
    ->body(Prose::make($this->description))
    ->body(KeyValue::make()->items('Station', $this->station));
</code-snippet>
@endverbatim
