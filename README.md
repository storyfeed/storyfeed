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
Storyfeed::activity()
    ->actor($user)
    ->confirm($delivery)
    ->for($customer)
    ->publish();

Storyfeed::feed()->context($project)->get();
```

## What it does

- **Curated recording** — a fluent builder (and, coming, declarative `Story` classes)
  for publishing meaningful activities from your domain events or observers. Not an
  audit log: you choose what makes the feed.
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
