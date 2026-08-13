# Storyfeed — Activity streams for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)
[![GitHub Tests Action Status](https://github.com/storyfeed/storyfeed/actions/workflows/run-tests.yml/badge.svg)](https://github.com/storyfeed/storyfeed/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)

> **Status: under active development.** See the [roadmap](ROADMAP.md) for what's in
> progress. APIs below reflect the working design spec and may shift until v1.0.

Storyfeed is an implementation of the activity stream pattern — the feed of meaningful
events at the heart of products like GitHub's dashboard:

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

## Activity streams, not activity logs

Laravel is well served for *audit logging* — recording who changed what, for
compliance and debugging. This is the other thing: the human-readable narrative a
product shows its own users.

The difference shows up the moment a feed gets busy. An audit log has five rows;
a stream has one sentence:

> *Bob, Sally, and 3 others uploaded files to Project X.*

Deciding which activities collapse together, along which axis, and what the
resulting sentence should honestly say is most of the work — and it is the work
this package does for you. It is also why teams reach for a hosted feed service.
You shouldn't have to.

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
  Bring your own UI, or use **`storyfeed/ui`** (coming) — polished pre-built
  components by Tey Labs.

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

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

**A commitment, so you can build on this safely:** everything MIT today stays MIT.
This package is complete on its own — recording, reads, grouping, curation and the
payload contract — and nothing in it will move behind a licence later. Paid
companions like `storyfeed/ui` add convenience on top; they never take anything
away, and a feed built on the core alone will keep working exactly as it does now.

Building your own renderer against the payload contract is expected and
encouraged — there's a plain Blade reference loop in the docs precisely so you
can.
