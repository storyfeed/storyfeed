<p align="center">
<a href="https://storyfeed.dev/">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/storyfeed/storyfeed/refs/heads/readme/samples/art/storyfeed-header-dark.svg">
    <img src="https://raw.githubusercontent.com/storyfeed/storyfeed/refs/heads/readme/samples/art/storyfeed-header-light.svg" width="328" alt="Storyfeed">
  </picture>
</a>
</p>

# Storyfeed — Activity Feeds for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)
[![GitHub Tests Action Status](https://github.com/storyfeed/storyfeed/actions/workflows/run-tests.yml/badge.svg)](https://github.com/storyfeed/storyfeed/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)

> **Pre-1.0.** Method names, class names, config keys, database columns and the
> docs are subject to change at any time.

Storyfeed records activities from your Laravel application and reads them back
as a feed: the pattern behind GitHub's dashboard, Slack's activity and social
timelines generally, popularized by Facebook's News Feed in 2006.

<picture>
  <source media="(prefers-reduced-motion: reduce)" srcset="https://raw.githubusercontent.com/storyfeed/storyfeed/refs/heads/readme/samples/art/samples/showcase-light.png">
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/storyfeed/storyfeed/refs/heads/readme/samples/art/samples/showcase-dark.svg">
  <img src="https://raw.githubusercontent.com/storyfeed/storyfeed/refs/heads/readme/samples/art/samples/showcase-light.svg" width="800" alt="Storyfeed in six scenes: recording an activity, reading it back grouped, rows with photo and key-value bodies, a feed scoped to one person, a new activity joining its group, and one feed rendered by the Blade, Vue and React kits.">
</picture>

Rendered by the Blade kit in [storyfeed/ui](https://github.com/storyfeed/ui) from activities recorded through this package.

Storyfeed grew out of sixteen years of rough implementations across production
applications, where many gaps were left unsolved. This package revisits that
pattern and rebuilds its architecture from scratch, to close those gaps and edge
cases and to align with the W3C's
[Activity Streams 2.0](https://www.w3.org/TR/activitystreams-core/) specification.

## Documentation

Installation, recording, reading, grouping and the payload contract are documented
at **[docs.storyfeed.dev](https://docs.storyfeed.dev)**, which tracks `main` and
changes with it.

- [Roadmap](ROADMAP.md) — what is built and what is in progress
- [Changelog](CHANGELOG.md)

## Credits

- [Jasper Tey](https://github.com/jaspertey) / [Tey Labs](https://teylabs.com)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
