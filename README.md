<p align="center">
<a href="https://storyfeed.dev/">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/storyfeed/storyfeed/27dd4c0416c31d92f86fdcbfe72d5edad4056a60/art/storyfeed-header-dark.svg">
    <img src="https://raw.githubusercontent.com/storyfeed/storyfeed/27dd4c0416c31d92f86fdcbfe72d5edad4056a60/art/storyfeed-header-light.svg" width="328" alt="Storyfeed">
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
  <source media="(prefers-reduced-motion: reduce)" srcset="https://raw.githubusercontent.com/storyfeed/storyfeed/27dd4c0416c31d92f86fdcbfe72d5edad4056a60/art/samples/showcase-light.png">
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/storyfeed/storyfeed/27dd4c0416c31d92f86fdcbfe72d5edad4056a60/art/samples/showcase-dark.svg">
  <img src="https://raw.githubusercontent.com/storyfeed/storyfeed/27dd4c0416c31d92f86fdcbfe72d5edad4056a60/art/samples/showcase-light.svg" width="800" alt="A Storyfeed feed growing, top first: a booking, then its person, a key-value card and a photo; three volunteers joining fold into one grouped row; photos arrive live and regroup with thumbnails. The camera pulls back: the whole feed is the picture in a row that reads You looked at Storyfeed's README, above earlier looks by others. Someone else's look arrives above yours, still typing, and the camera dives into its dots, back to the start.">
</picture>

Every frame is real output from activities recorded through this package.
Ships with Blade, Vue and React kits in [storyfeed/ui](https://github.com/storyfeed/ui).

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
