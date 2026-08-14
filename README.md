# Storyfeed — Activity streams for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)
[![GitHub Tests Action Status](https://github.com/storyfeed/storyfeed/actions/workflows/run-tests.yml/badge.svg)](https://github.com/storyfeed/storyfeed/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)

> **Status: under active development.** See the [roadmap](ROADMAP.md) for what's in
> progress. APIs below reflect the working design spec and may shift until v1.0.

Every product you use at scale has one. GitHub's dashboard, Slack's activity, the
feed on every social network you have ever opened — the same pattern, solved over
and over by teams with the headcount to solve it properly.

**None of this is a new idea.** It is a well-understood pattern that has simply
stayed out of reach for ordinary applications: you either build it yourself and
discover how much detail is hiding inside it, or you rent it from a hosted
service.

Storyfeed brings it to any Laravel app — including the small ones.

> *Sally confirmed Delivery #1042 for Acme Co.*
> *Bob, Sally, and 3 others uploaded files to Project X.*

It follows the [W3C Activity Streams 2.0](https://www.w3.org/TR/activitystreams-core/)
specification under the hood, adapted for practical usage and great developer
experience within Laravel applications.

```php
Storyfeed::record(ActivityVerb::Confirm, object: $delivery, actor: $user, target: $customer);

// or fluently, when you need to build conditionally
Storyfeed::activity(ActivityVerb::Confirm, $delivery)->actor($user)->for($customer)->publish();

Storyfeed::feed()->context($project)->get();
```

## The feature nobody asks for

Across sixteen years of building operational portals, this is the one feature
that was never requested and consistently loved the most. It usually arrived as a
placeholder — something to fill an empty homepage — and became the thing people
opened the app to look at.

Users don't ask for an activity feed, because they don't know it is a thing they
can have. They recognise it instantly once it is there.

## Activity streams, not activity logs

Laravel is well served for *audit logging* — recording who changed what, for
compliance and debugging. This is the other thing: the human-readable narrative a
product shows its own users.

The difference shows up the moment a feed gets busy. An audit log has five rows;
a stream has one sentence:

> *Bob, Sally, and 3 others uploaded files to Project X.*

Deciding which activities collapse together, along which axis, and what the
resulting sentence can honestly claim is most of the work. That is the part this
package does for you.

## What it does

- **Curated recording** — a typed, autocomplete-friendly API for publishing
  meaningful activities from your domain events or observers. Keep your verbs in
  an enum and they become IDE-discoverable and typo-proof. Not an audit log: you
  choose what makes the feed.
- **Fast, self-describing reads** — entity snapshots kill polymorphic N+1s; every feed
  item ships fully described (headline template, icon, linked entities) so renderers
  need zero domain knowledge.
- **Smart grouping** — activities aggregate the way social feeds do
  (*"…and 3 others"*), via a behind-the-scenes curation process.
- **Activity Streams 2.0** — spec-conformant JSON-LD serialization
  (`Activity`, `OrderedCollection`), with ActivityPub federation on the long-range
  roadmap.
- **Headless by design** — the core emits a stable, versioned payload contract.
  Bring your own UI, or use **`storyfeed/ui`** — pre-built components, free and
  MIT like everything else here. Coming; see [Sponsoring](#sponsoring).

## Installation

```bash
composer require storyfeed/storyfeed

php artisan vendor:publish --tag="storyfeed-migrations"
php artisan migrate
php artisan vendor:publish --tag="storyfeed-config"
```

## Documentation

Full documentation ships alongside the v0.x milestones — see the
[roadmap](ROADMAP.md) for what's in progress. The design-spec documents (payload
contract, Activity Streams 2.0 conformance, the Story authoring layer, feed curation)
will be published as each area stabilizes.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Jasper Tey](https://github.com/jaspertey) / [Tey Labs](https://teylabs.com)
- [All Contributors](../../contributors)

## Sponsoring

`storyfeed/ui` — the pre-built renderer components — will be **free and MIT**, like
everything else here. Gating it was the original plan and it was the wrong one:
holding the UI back means nobody can see the whole pattern working end to end,
which is the only way this is worth learning.

**Sponsorship sets its pace instead of its price.** Every adapter is a surface that
has to keep working, so how far `storyfeed/ui` goes depends on how much time
sponsorship makes available:

- **Unsponsored** — it moves at my own pace, and realistically ships Vue/Inertia
  and Filament adapters only.
- **Sponsored** — Livewire, Blade components and React become reachable, along
  with faster response on the core.

If it earns a place in your app, that is the way to say so.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

**A commitment, so you can build on this safely:** everything MIT today stays MIT,
and nothing here will move behind a licence later — not the core, not the UI
components. This package is complete on its own: recording, reads, aggregation,
curation and the payload contract.

Building your own renderer against the payload contract is expected and
encouraged — there's a plain Blade reference loop in the docs precisely so you
can.
