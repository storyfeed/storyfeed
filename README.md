# Storyfeed — Activity streams for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)
[![GitHub Tests Action Status](https://github.com/storyfeed/storyfeed/actions/workflows/run-tests.yml/badge.svg)](https://github.com/storyfeed/storyfeed/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)

> **Pre-1.0.** Method names, class names, config keys, database columns and the
> docs change without a deprecation cycle. Pin an exact tag, never a range.

Storyfeed records activities from your application and reads them back as a feed —
the pattern behind GitHub's dashboard, Slack's activity and social timelines
generally. Serialization follows
[Activity Streams 2.0](https://www.w3.org/TR/activitystreams-core/).

A recording reads as the sentence it publishes:

```php
use Storyfeed\Facades\Storyfeed;

Storyfeed::activity()->by($user)->action('confirm', $delivery)->to($customer)->publish();
```

A read starts from the model whose story you want:

```php
$customer->storyfeed()->get();
```

> *Sally confirmed Delivery #1042 for Acme Co.*
> *Bob, Sally, and 3 others uploaded files to Project X.*

The second headline is one feed item covering several activities. Which activities
collapse together, along which axis, and what the resulting sentence says is
configurable.

## Installation

```bash
composer require storyfeed/storyfeed

php artisan vendor:publish --tag="storyfeed-migrations"
php artisan migrate
php artisan vendor:publish --tag="storyfeed-config"
```

## Recording

Verbs are free-form strings. Nothing has to be declared before the first recording
works:

```php
Storyfeed::activity()->by($user)->action('upload', $file)->to($project)->publish();
```

`to()` sets the target. `on()`, `with()`, `into()`, `in()` and `for()` set the same
slot — pick the preposition your verb takes, and the stored row is identical.

An enum buys autocomplete, typo-proofing and an Activity Streams 2.0 mapping per
case, and drops into the same chain:

```php
Storyfeed::activity()->by($user)->action(ActivityVerb::Upload, $file)->to($project)->publish();
```

## Reading

A model's own feed is every activity it took part in, in any role:

```php
$project->storyfeed()->get();
```

The facade reads the same feed, and takes the roles one at a time:

```php
Storyfeed::feed()->involving($project)->get();   // any role
Storyfeed::feed()->context($project)->get();     // what happened inside this container
```

How much a page collapses is a per-read choice:

| Mode | What comes back |
|---|---|
| `log()` | Every activity, ungrouped — the atomic timeline. |
| `live()` | Repeat-only grouping — *"Sally uploaded 12 photos"*. |
| `summary()` | Multi-axis collapsing — *"Bob, Sally, and 3 others uploaded files to Project X"*. The default. |

```php
$project->storyfeed()->summary()->get();
```

### Named feeds

A named feed is a verb allowlist declared once, in a service provider, instead of
at every call site. `storyfeed:doctor` reports any verb no restricted feed decides:

```php
// AppServiceProvider::boot()
Storyfeed::feeds([
    'customer' => fn (FeedBuilder $feed) => $feed
        ->only(['order.placed', 'order.confirmed', 'order.delivered'])
        ->log(),
]);
```

```php
$order->storyfeed('customer')->get();
```

The allowlist filters verbs; it does not scope rows.
`Storyfeed::feed('customer')->get()` returns every order in the system, correctly
verb-filtered. A Feed class takes its subject as a typed constructor argument, so
an unscoped read fails instead of returning someone else's activity.

## Reference

- [Roadmap](ROADMAP.md) — what is built and what is in progress
- [Changelog](CHANGELOG.md)
- [License](LICENSE.md) — MIT

Renderers build against a versioned payload contract; every feed item ships fully
described (headline template, icon, linked entities), so a renderer needs no
domain knowledge of your application.

---

I have built this feature by hand in operational portals for sixteen years — a new
implementation each time, and the same details to get right each time.

Storyfeed is original work, written from scratch for this package. It contains no
code, schema, data or confidential material belonging to any past employer or
client. What carries over is experience with the problem, not artefacts of solving
it elsewhere.

The data model is not proprietary either: it implements the W3C's public
[Activity Streams 2.0](https://www.w3.org/TR/activitystreams-core/) specification —
actor, verb, object, target, and the `OrderedCollection` serialization — and the
vocabulary enums are transcriptions of that published spec, verified in the test
suite against the W3C context document.

— [Jasper Tey](https://github.com/jaspertey) / [Tey Labs](https://teylabs.com)
