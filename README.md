# Storyfeed: Activity Streams for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/storyfeed/storyfeed/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/storyfeed/storyfeed/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/storyfeed/storyfeed/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/storyfeed/storyfeed/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/storyfeed/storyfeed.svg?style=flat-square)](https://packagist.org/packages/storyfeed/storyfeed)

Storyfeed is an implementation of the activity stream pattern. It follows several principles outlined in the [Activity Streams 2.0](https://www.w3.org/TR/activitystreams-core/) specification, adapted for practical usage within Laravel applications.

## Installation

> **Note**
> This package is currently under development.

You can install the package via composer:

```bash
composer require storyfeed/storyfeed
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="storyfeed-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="storyfeed-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="storyfeed-views"
```

## Usage

```php
$storyfeed = new Storyfeed\Storyfeed();
echo $storyfeed->echoPhrase('Hello, Storyfeed!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Jasper Tey](https://github.com/JasperTey)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
