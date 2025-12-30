[![GitHub Workflow Status][ico-tests]][link-tests]
[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

------

# Chaperone

A powerful Laravel package that supervises and guards long-running queue jobs with health monitoring, circuit breaker protection, and intelligent recovery mechanisms. Ensures your background jobs complete successfully or recover gracefully from failures.

## Requirements

> **Requires [PHP 8.4+](https://php.net/releases/)** and Laravel 11+

## Installation

```bash
composer require cline/chaperone
```

Publish configuration and migrations:

```bash
php artisan vendor:publish --tag=chaperone-config
php artisan vendor:publish --tag=chaperone-migrations
php artisan migrate
```

## Quick Start

Add the `Supervised` trait to any queue job and call `heartbeat()` to signal it's alive:

```php
use Cline\Chaperone\Concerns\Supervised;
use Illuminate\Contracts\Queue\ShouldQueue;

class ImportUsers implements ShouldQueue
{
    use Supervised;

    public function handle(): void
    {
        $users = User::cursor();
        $total = User::count();

        foreach ($users as $index => $user) {
            $this->processUser($user);

            // Signal we're alive
            $this->heartbeat(['current_user' => $user->email]);

            // Report progress
            $this->reportProgress($index + 1, $total);
        }
    }
}
```

Monitor supervised jobs:

```bash
php artisan chaperone:monitor
```

## Documentation

- **[Getting Started](cookbook/getting-started.md)** - Installation, configuration, and first supervised job
- **[Basic Supervision](cookbook/basic-supervision.md)** - Heartbeats, progress tracking, and health monitoring
- **[Circuit Breakers](cookbook/circuit-breakers.md)** - Protect against cascading failures
- **[Resource Limits](cookbook/resource-limits.md)** - Enforce memory, CPU, and disk limits
- **[Artisan Commands](cookbook/artisan-commands.md)** - CLI monitoring and management tools
- **[Configuration](cookbook/configuration.md)** - Complete configuration reference

## Key Features

- ✅ **Health Monitoring** - Track heartbeats and detect stuck/zombie jobs
- ✅ **Circuit Breakers** - Protect external services from cascading failures
- ✅ **Resource Limits** - Enforce memory, CPU, and disk usage constraints
- ✅ **Progress Tracking** - Monitor job completion with real-time progress
- ✅ **Lifecycle Hooks** - Callbacks for stuck, timeout, and resource violations
- ✅ **Dead Letter Queue** - Automatic handling of permanently failed jobs
- ✅ **Observability** - Integration with Laravel Pulse, Telescope, and Horizon
- ✅ **Artisan Commands** - Real-time monitoring dashboard and management tools
- ✅ **Event System** - Comprehensive events for custom monitoring and alerting

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please use the [GitHub security reporting form][link-security] rather than the issue queue.

## Credits

- [Brian Faust][link-maintainer]
- [All Contributors][link-contributors]

## License

The MIT License. Please see [License File](LICENSE.md) for more information.

[ico-tests]: https://git.cline.sh/faustbrian/chaperone/actions/workflows/quality-assurance.yaml/badge.svg
[ico-version]: https://img.shields.io/packagist/v/cline/chaperone.svg
[ico-license]: https://img.shields.io/badge/License-MIT-green.svg
[ico-downloads]: https://img.shields.io/packagist/dt/cline/chaperone.svg

[link-tests]: https://git.cline.sh/faustbrian/chaperone/actions
[link-packagist]: https://packagist.org/packages/cline/chaperone
[link-downloads]: https://packagist.org/packages/cline/chaperone
[link-security]: https://git.cline.sh/faustbrian/chaperone/security
[link-maintainer]: https://git.cline.sh/faustbrian
[link-contributors]: ../../contributors
