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

The only publishable resource is the configuration file:

```bash
php artisan vendor:publish --tag="quine-config"
```

## Usage

<!-- Add a basic usage example here. -->

## Claude Code hook

quine ships a PostToolUse hook for Claude Code. After every Write or Edit inside a Laravel app that has quine installed, it runs `php artisan quine:nudge <file>` and, only when a recipe has something to say, hands that back to the agent as additional context. An ordinary edit produces nothing: a nudge that fires on every edit becomes wallpaper, so silence is the default and a nudge earns its place by carrying the reason and the coverage gap.

Add this to the `hooks` block of `~/.claude/settings.json`, merging with any PostToolUse entries you already have:

```json
{
    "hooks": {
        "PostToolUse": [
            {
                "matcher": "Write|Edit",
                "hooks": [
                    {
                        "type": "command",
                        "command": "php /path/to/your/app/vendor/ohwhatnow/quine/hooks/claude-code/post-edit.php"
                    }
                ]
            }
        ]
    }
}
```

The script needs no Composer autoload and finds the Laravel app by walking up from the edited file to the nearest `artisan`, so one copy serves every project: copy `hooks/claude-code/post-edit.php` somewhere permanent (say `~/.claude/hooks/quine-post-edit.php`) and point the command at that instead of a path inside one app's `vendor`. It does nothing for files outside a Laravel app, or inside an app that does not have quine installed.

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
