# Storyfeed — Activity streams for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)
[![GitHub Tests Action Status](https://github.com/storyfeed/storyfeed/actions/workflows/run-tests.yml/badge.svg)](https://github.com/storyfeed/storyfeed/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)

> **Pre-1.0.** Method names, class names, config keys, database columns and the
> docs change without a deprecation cycle. Pin an exact tag, never a range.

Storyfeed is work inspired by sixteen years of ad-hoc implementations of the
activity feed pattern in production applications for clients, where several
technical details and edge cases remained unoptimized or unsolved. This package has
been written from scratch to solve those problems properly and standardize the
pattern into something that is feature-rich, developer friendly, and aligned with
the W3C's public
[Activity Streams 2.0](https://www.w3.org/TR/activitystreams-core/) specification.

Storyfeed records activities from your application and reads them back as a feed —
the pattern behind GitHub's dashboard, Slack's activity and social timelines
generally.

> *Sally confirmed Delivery #1042 for Acme Co.*
> *Bob, Sally, and 3 others uploaded files to Project X.*

The second headline is one feed item covering several activities. Which activities
collapse together, along which axis, and what the resulting sentence says is
configurable.

Renderers build against a versioned payload contract; every feed item ships fully
described (headline template, icon, linked entities), so a renderer needs no
domain knowledge of your application.

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
