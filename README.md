<p align="center">
<a href="https://storyfeed.dev/">
  <picture>
    <source media="(prefers-reduced-motion: reduce)" srcset="https://raw.githubusercontent.com/storyfeed/storyfeed/2d5d84e351d606dff9521769b32960676bbbbbb0/art/storyfeed-static-light.png">
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/storyfeed/storyfeed/2d5d84e351d606dff9521769b32960676bbbbbb0/art/storyfeed-animated-dark.gif">
    <img src="https://raw.githubusercontent.com/storyfeed/storyfeed/2d5d84e351d606dff9521769b32960676bbbbbb0/art/storyfeed-animated-light.gif" width="800" height="214" alt="Storyfeed. New activities advance through a three-card feed.">
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

> *Sally confirmed Delivery #1042 for Acme Co.*
>
> *Bob, Sally, and 3 others uploaded files to Project X.*

The second headline is one feed item covering several activities.

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
