<div align="center">
    <h1>Quine</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/ohwhatnow/quine"><img src="https://img.shields.io/packagist/v/ohwhatnow/quine.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/ohwhatnow/quine"><img src="https://img.shields.io/packagist/php-v/ohwhatnow/quine.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/ohwhatnow/quine"><img src="https://badge.laravel.cloud/badge/ohwhatnow/quine?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/ohwhatnow/quine/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/ohwhatnow/quine/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/ohwhatnow/quine"><img src="https://img.shields.io/packagist/dt/ohwhatnow/quine.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Gather useful stats and help AI coding agents

## Installation

You can install the package via Composer:

```bash
composer require ohwhatnow/quine
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="quine"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="quine-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="quine-migrations"
php artisan migrate
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="quine-assets"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Quine! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Ohffs](https://github.com/ohwhatnow)
- [All Contributors](../../contributors)

## License

Quine is open-sourced software licensed under the [MIT license](LICENSE.md).
