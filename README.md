# Storyfeed — Activity streams for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)
[![GitHub Tests Action Status](https://github.com/storyfeed/storyfeed/actions/workflows/run-tests.yml/badge.svg)](https://github.com/storyfeed/storyfeed/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)

> **Status: pre-1.0, and genuinely unfinished.** This package is being designed in
> public, not maintained in public. Anything here can change without a deprecation
> cycle — method names, class names, config keys, database columns, the docs, this
> README. There are no alpha tags out of politeness; there are alpha tags because
> it is an alpha. See [Stability before 1.0](#stability-before-10).

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
  Bring your own UI, or use **`storyfeed/ui`** — pre-built Vue/Inertia and Blade
  components, free and MIT like the core. Coming; see
  [How this is packaged](#how-this-is-packaged).

## Stability before 1.0

**Nobody is being asked to depend on this yet.** It is not marketed, not announced,
and as of today the author is its only user. If you found it and want to build on
it, you are welcome to — but you are doing so at your own risk, and the risk is
real rather than boilerplate.

What "pre-1.0" means here, concretely:

- **Breaking changes ship without a deprecation cycle**, and without a major
  version bump — the version is still `0.x` precisely so it can. Two renaming
  waves landed in a single week (`for()` → `involving()`;
  `flat`/`grouped`/`curated` → `log`/`live`/`summary`), and there will be more.
- **The documentation changes with it**, and can lag it. A doc describing a method
  that was renamed yesterday is a bug, not a promise — please report it, but do
  not plan around it.
- **Pin an exact commit or tag, never a range.** `dev-main` is a moving target and
  is meant to be.
- **Design decisions get reversed.** They are reversed in the open, with the
  reasoning written down, and sometimes within days of being made.

**The one exception, and it is deliberate:** the [payload
contract](docs/payload.md) is versioned independently of the package and does not
churn. It is the boundary a renderer builds against, so it hardens early and stays
hard while everything behind it moves. That is the whole point of having it — the
package is free to change its mind because the contract is not.

Two things are also not up for revision, for the avoidance of doubt: everything
published under MIT stays MIT (see [License](#license)), and the payload contract's
compatibility promise above. Instability is about the API surface, not about
walking back commitments.

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

## How this is packaged

| Package | Licence | Where |
|---|---|---|
| `storyfeed/storyfeed` — the core | MIT | Packagist |
| `storyfeed/ui` — Vue/Inertia + Blade feed components | MIT | Packagist |
| `storyfeed/filament` — the Filament plugin | Commercial, ~$49 one-time | Sold through [Anystack](https://anystack.sh) (licence key + private Composer endpoint) |

**The whole pattern is free, end to end.** The core plus `storyfeed/ui` render a
real feed — recording, reads, grouping, curation, the payload contract and
components that consume it — without paying anyone. That is deliberate: a
package you cannot see working is not worth learning.

**One adapter is paid, and here is the honest reason.** Between 2026-08-14 and
2026-08-18 this README said *every* adapter, Filament included, would be MIT. It
now says the Filament plugin is a paid product. That is a narrowing of something
already written down in public, so it gets explained rather than quietly edited:

- **Filament is the one corner of this ecosystem with a working paid-plugin
  market.** It has a plugin directory, a norm of paying for plugins, and an
  audience that already does. Charging there is a normal transaction rather than
  a toll booth bolted onto an open-source project.
- **What that plugin sells is live-feed correctness and Filament-native
  components.** A live feed has rules that every consumer gets wrong
  independently: reconcile by node identity, not list position; drop and refetch
  when `sync_token` changes; an empty page is not the end of the feed; follow
  with a live cursor. Getting those right once, on behalf of everyone who
  installs it, is worth funding — and funding is what keeps it right as Filament
  moves.
- **What it explicitly does *not* sell is safety.** Deciding which verbs an
  audience may see — the thing that makes a customer-facing feed trustworthy —
  lives in the MIT core, where every surface can reach it and `storyfeed:doctor`
  can check it. Putting that behind a paywall would mean the free core could not
  honestly ship a customer-facing feed at all, and a package that cannot be
  trusted without paying is not open core; it is a hostage situation.
- **Sponsorship funds pace; the plugin funds maintenance.** They are different
  jobs, and pretending one could do both is what produced the wrong answer four
  days earlier.
- **Now is the only honest moment to change this.** At the time of writing this
  project has no releases anyone depends on, no installs and no stars — there is
  nobody whose plans this breaks. The same edit made after the package has
  adopters would be a bait and switch, so it is being made before, in the open,
  with the reasoning attached.

## Sponsoring

**Sponsorship sets the MIT packages' pace, never their price.** Every adapter is
a surface that has to keep working, so how far `storyfeed/ui` goes depends on how
much time sponsorship makes available:

- **Unsponsored** — it moves at my own pace, and realistically ships the
  Vue/Inertia and Blade components only.
- **Sponsored** — Livewire and React become reachable, along with faster response
  on the core.

Nothing behind sponsorship is access. If the package earns a place in your app,
that is the way to say so.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

**A commitment, so you can build on this safely:** everything published under MIT
stays MIT — the core and `storyfeed/ui` — and nothing that ships MIT will move
behind a licence later. This package is complete on its own: recording, reads,
aggregation, curation and the payload contract.

The one paid piece, `storyfeed/filament`, is a separate repository that is
commercial from its first commit; it is not a part of this package being taken
away. The scope of the commitment above was narrowed on 2026-08-18 — before this
project had a single adopter — and the reasoning is in
[How this is packaged](#how-this-is-packaged).

Building your own renderer against the payload contract is expected and
encouraged — there's a plain Blade reference loop in the docs precisely so you
can.
