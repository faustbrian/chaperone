# Circuit Breakers

Circuit breakers prevent cascading failures by temporarily stopping execution when a service becomes unreliable. This guide covers implementing circuit breakers to protect external services and handle failures gracefully.

## Understanding Circuit Breakers

A circuit breaker has three states:

- **Closed** - Normal operation, requests pass through
- **Open** - Service is failing, requests are blocked immediately
- **Half-Open** - Testing if service has recovered

### State Transitions

```
Closed → Open: When failure threshold is exceeded
Open → Half-Open: After timeout period expires
Half-Open → Closed: When success threshold is met
Half-Open → Open: When any request fails
```

## Basic Usage

### Protecting an External API

```php
use Cline\Chaperone\Facades\CircuitBreaker;
use Illuminate\Support\Facades\Http;

class ProcessPayment implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        CircuitBreaker::for('payment-gateway')
            ->execute(function () {
                $response = Http::post('https://api.payment.com/charge', [
                    'amount' => 1000,
                    'currency' => 'USD',
                ]);

                return $response->json();
            });
    }
}
```

### With Fallback Logic

```php
CircuitBreaker::for('payment-gateway')
    ->execute(
        function () {
            return Http::post('https://api.payment.com/charge', $data)
                ->json();
        },
        fallback: function () {
            // Queue payment for later processing
            PendingPayment::create($data);

            return ['status' => 'queued'];
        }
    );
```

## Configuration

### Global Configuration

Set defaults in `config/chaperone.php`:

```php
'circuit_breaker' => [
    'enabled' => env('CHAPERONE_CIRCUIT_BREAKER_ENABLED', true),
    'failure_threshold' => env('CHAPERONE_CIRCUIT_BREAKER_THRESHOLD', 5),
    'success_threshold' => env('CHAPERONE_CIRCUIT_BREAKER_SUCCESS_THRESHOLD', 2),
    'timeout' => env('CHAPERONE_CIRCUIT_BREAKER_TIMEOUT', 300),
    'half_open_attempts' => env('CHAPERONE_CIRCUIT_BREAKER_HALF_OPEN_ATTEMPTS', 3),
],
```

### Per-Service Configuration

Customize settings for specific services:

```php
CircuitBreaker::for('external-api')
    ->withThreshold(10)              // Open after 10 failures
    ->withSuccessThreshold(3)        // Close after 3 successes
    ->withTimeout(600)               // Wait 10 minutes before half-open
    ->withHalfOpenAttempts(5)        // Allow 5 test requests
    ->execute(fn() => $this->callApi());
```

## Circuit Breaker Patterns

### HTTP API Calls

Protect HTTP requests to external services:

```php
use Cline\Chaperone\Facades\CircuitBreaker;
use Illuminate\Support\Facades\Http;

class SyncExternalData implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        $data = CircuitBreaker::for('external-api')
            ->withThreshold(5)
            ->withTimeout(300)
            ->execute(
                function () {
                    return Http::retry(3, 100)
                        ->get('https://api.example.com/data')
                        ->json();
                },
                fallback: function () {
                    // Use cached data
                    return Cache::get('external-data-fallback', []);
                }
            );

        $this->processData($data);
    }

    private function processData(array $data): void
    {
        // Process logic
    }
}
```

### Database Operations

Protect operations against unstable database connections:

```php
use Cline\Chaperone\Facades\CircuitBreaker;
use Illuminate\Support\Facades\DB;

class ExportToExternalDatabase implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        $records = $this->fetchRecords();

        foreach ($records as $record) {
            CircuitBreaker::for('external-db')
                ->withThreshold(3)
                ->execute(
                    function () use ($record) {
                        DB::connection('external')
                            ->table('records')
                            ->insert($record->toArray());
                    },
                    fallback: function () use ($record) {
                        // Queue for later retry
                        FailedExport::create([
                            'record_id' => $record->id,
                            'data' => $record->toArray(),
                        ]);
                    }
                );
        }
    }
}
```

### Third-Party Services

Protect calls to third-party services like payment gateways, email providers, or SMS services:

```php
use Cline\Chaperone\Facades\CircuitBreaker;

class SendNotifications implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        $users = $this->getUsers();

        foreach ($users as $user) {
            // Email circuit breaker
            CircuitBreaker::for('email-service')
                ->execute(
                    fn() => $this->sendEmail($user),
                    fallback: fn() => Log::warning("Email queued for {$user->email}")
                );

            // SMS circuit breaker
            CircuitBreaker::for('sms-service')
                ->execute(
                    fn() => $this->sendSms($user),
                    fallback: fn() => Log::warning("SMS queued for {$user->phone}")
                );
        }
    }

    private function sendEmail($user): void
    {
        // Email sending logic
    }

    private function sendSms($user): void
    {
        // SMS sending logic
    }
}
```

### File Storage

Protect operations against cloud storage services:

```php
use Cline\Chaperone\Facades\CircuitBreaker;
use Illuminate\Support\Facades\Storage;

class ProcessAndStoreFiles implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        $files = $this->getFiles();

        foreach ($files as $file) {
            $processed = $this->processFile($file);

            CircuitBreaker::for('s3-storage')
                ->withThreshold(5)
                ->withTimeout(180)
                ->execute(
                    function () use ($processed, $file) {
                        Storage::disk('s3')->put(
                            $file->path,
                            $processed
                        );
                    },
                    fallback: function () use ($processed, $file) {
                        // Store locally as fallback
                        Storage::disk('local')->put(
                            "pending-upload/{$file->path}",
                            $processed
                        );
                    }
                );
        }
    }
}
```

## Advanced Features

### Manual Circuit Control

Manually open or close circuits:

```php
use Cline\Chaperone\CircuitBreakers\CircuitBreakerManager;

$manager = app(CircuitBreakerManager::class);

// Manually open circuit (stop all requests)
$manager->open('payment-gateway');

// Manually close circuit (resume normal operation)
$manager->close('payment-gateway');

// Force half-open state (allow test requests)
$manager->halfOpen('payment-gateway');

// Reset circuit (clear failure count)
$manager->reset('payment-gateway');
```

### Check Circuit State

Query the current circuit state before executing:

```php
use Cline\Chaperone\CircuitBreakers\CircuitBreakerManager;

$manager = app(CircuitBreakerManager::class);

if ($manager->isOpen('payment-gateway')) {
    // Use alternative payment method
    $this->useBackupGateway();
} else {
    // Use primary gateway
    CircuitBreaker::for('payment-gateway')
        ->execute(fn() => $this->processPrimaryGateway());
}
```

### Circuit Metrics

Access circuit breaker metrics:

```php
use Cline\Chaperone\Database\Models\CircuitBreaker as CircuitBreakerModel;

$breaker = CircuitBreakerModel::where('service', 'payment-gateway')->first();

echo $breaker->state;          // closed, open, half_open
echo $breaker->failure_count;  // Consecutive failures
echo $breaker->success_count;  // Consecutive successes (in half-open)
echo $breaker->last_failure_at; // Last failure timestamp
echo $breaker->opened_at;      // When circuit opened
```

### Multiple Fallback Strategies

Implement cascading fallbacks:

```php
CircuitBreaker::for('primary-api')
    ->execute(
        function () {
            return Http::get('https://primary-api.com/data')->json();
        },
        fallback: function () {
            // Try backup API
            return CircuitBreaker::for('backup-api')
                ->execute(
                    function () {
                        return Http::get('https://backup-api.com/data')->json();
                    },
                    fallback: function () {
                        // Use cached data as last resort
                        return Cache::get('data-fallback', []);
                    }
                );
        }
    );
```

## Event Handling

Listen to circuit breaker events:

```php
use Cline\Chaperone\Events\CircuitBreakerOpened;
use Cline\Chaperone\Events\CircuitBreakerClosed;
use Cline\Chaperone\Events\CircuitBreakerHalfOpened;
use Illuminate\Support\Facades\Event;

// Circuit opened - service is failing
Event::listen(CircuitBreakerOpened::class, function ($event) {
    Log::critical("Circuit breaker opened for {$event->service}", [
        'failure_count' => $event->failureCount,
        'opened_at' => $event->openedAt,
    ]);

    // Send alert to operations team
    Alert::send("Circuit breaker opened: {$event->service}");

    // Enable fallback mechanisms
    FallbackService::enable($event->service);
});

// Circuit closed - service recovered
Event::listen(CircuitBreakerClosed::class, function ($event) {
    Log::info("Circuit breaker closed for {$event->service}");

    // Disable fallback mechanisms
    FallbackService::disable($event->service);

    // Send recovery notification
    Alert::send("Circuit breaker recovered: {$event->service}");
});

// Circuit half-opened - testing recovery
Event::listen(CircuitBreakerHalfOpened::class, function ($event) {
    Log::info("Circuit breaker half-opened for {$event->service}");

    // Monitor test requests closely
    Monitor::watch($event->service);
});
```

## Monitoring and Alerting

### Dashboard Integration

Create a circuit breaker dashboard:

```php
use Cline\Chaperone\Database\Models\CircuitBreaker;

class CircuitBreakerController extends Controller
{
    public function index()
    {
        $breakers = CircuitBreaker::all();

        return view('dashboard.circuit-breakers', [
            'open' => $breakers->where('state', 'open'),
            'half_open' => $breakers->where('state', 'half_open'),
            'closed' => $breakers->where('state', 'closed'),
        ]);
    }

    public function show($service)
    {
        $breaker = CircuitBreaker::where('service', $service)->firstOrFail();

        return view('dashboard.circuit-breaker-details', [
            'breaker' => $breaker,
            'history' => $breaker->history()->latest()->take(100)->get(),
        ]);
    }
}
```

### Metrics Collection

Track circuit breaker metrics:

```php
use Cline\Chaperone\Events\CircuitBreakerOpened;
use Cline\Chaperone\Events\CircuitBreakerClosed;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    Metrics::increment('circuit_breaker.opened', [
        'service' => $event->service,
    ]);

    Metrics::gauge('circuit_breaker.failure_count', $event->failureCount, [
        'service' => $event->service,
    ]);
});

Event::listen(CircuitBreakerClosed::class, function ($event) {
    Metrics::increment('circuit_breaker.closed', [
        'service' => $event->service,
    ]);
});
```

### Health Checks

Implement circuit breaker health checks:

```php
use Cline\Chaperone\Database\Models\CircuitBreaker;

class CircuitBreakerHealthCheck
{
    public function check(): array
    {
        $openBreakers = CircuitBreaker::where('state', 'open')->get();

        if ($openBreakers->isEmpty()) {
            return [
                'status' => 'healthy',
                'message' => 'All circuit breakers closed',
            ];
        }

        return [
            'status' => 'unhealthy',
            'message' => "Open circuits: {$openBreakers->pluck('service')->join(', ')}",
            'open_breakers' => $openBreakers->pluck('service')->toArray(),
        ];
    }
}
```

## Best Practices

### 1. Choose Appropriate Thresholds

```php
// Fast-failing service (respond quickly to issues)
CircuitBreaker::for('critical-service')
    ->withThreshold(3)    // Open after 3 failures
    ->withTimeout(60);    // Test after 1 minute

// Tolerant service (allow more failures before opening)
CircuitBreaker::for('non-critical-service')
    ->withThreshold(10)   // Open after 10 failures
    ->withTimeout(600);   // Test after 10 minutes
```

### 2. Always Provide Fallbacks

```php
// Good - has fallback
CircuitBreaker::for('service')
    ->execute(
        fn() => $this->primaryAction(),
        fallback: fn() => $this->fallbackAction()
    );

// Bad - no fallback (will throw exception when open)
CircuitBreaker::for('service')
    ->execute(fn() => $this->primaryAction());
```

### 3. Use Specific Service Names

```php
// Good - specific and identifiable
CircuitBreaker::for('stripe-payment-gateway');
CircuitBreaker::for('sendgrid-email-service');
CircuitBreaker::for('aws-s3-storage');

// Bad - too generic
CircuitBreaker::for('api');
CircuitBreaker::for('service');
CircuitBreaker::for('external');
```

### 4. Log Circuit State Changes

```php
Event::listen(CircuitBreakerOpened::class, function ($event) {
    Log::critical("Circuit opened: {$event->service}", [
        'failure_count' => $event->failureCount,
        'timestamp' => $event->openedAt,
    ]);
});

Event::listen(CircuitBreakerClosed::class, function ($event) {
    Log::info("Circuit closed: {$event->service}");
});
```

### 5. Monitor Circuit Health

```php
// Regular health check
Schedule::call(function () {
    $openBreakers = CircuitBreaker::where('state', 'open')
        ->where('opened_at', '<', now()->subMinutes(30))
        ->get();

    if ($openBreakers->isNotEmpty()) {
        Alert::send("Long-running open circuits", [
            'services' => $openBreakers->pluck('service'),
        ]);
    }
})->everyFifteenMinutes();
```

## Testing Circuit Breakers

Test circuit breaker behavior in your test suite:

```php
use Tests\TestCase;
use Cline\Chaperone\Facades\CircuitBreaker;
use Cline\Chaperone\CircuitBreakers\CircuitBreakerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class CircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    public function test_circuit_opens_after_threshold(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        for ($i = 0; $i < 5; $i++) {
            try {
                CircuitBreaker::for('test-service')
                    ->withThreshold(5)
                    ->execute(fn() => Http::get('https://api.test.com'));
            } catch (\Exception $e) {
                // Expected to fail
            }
        }

        $manager = app(CircuitBreakerManager::class);
        $this->assertTrue($manager->isOpen('test-service'));
    }

    public function test_fallback_executes_when_circuit_open(): void
    {
        $manager = app(CircuitBreakerManager::class);
        $manager->open('test-service');

        $result = CircuitBreaker::for('test-service')
            ->execute(
                fn() => 'primary',
                fallback: fn() => 'fallback'
            );

        $this->assertEquals('fallback', $result);
    }

    public function test_circuit_closes_after_success_threshold(): void
    {
        $manager = app(CircuitBreakerManager::class);
        $manager->halfOpen('test-service');

        Http::fake(['*' => Http::response(['success' => true])]);

        // Execute successful requests to close circuit
        for ($i = 0; $i < 2; $i++) {
            CircuitBreaker::for('test-service')
                ->withSuccessThreshold(2)
                ->execute(fn() => Http::get('https://api.test.com'));
        }

        $this->assertFalse($manager->isOpen('test-service'));
    }
}
```

## Troubleshooting

### Circuit Won't Close

If a circuit remains open:

1. Check timeout configuration - may need to increase
2. Verify the underlying service is actually healthy
3. Check success threshold - may be too high
4. Review logs for continued failures in half-open state

```php
$breaker = CircuitBreaker::where('service', 'my-service')->first();
echo "State: {$breaker->state}";
echo "Failure count: {$breaker->failure_count}";
echo "Last failure: {$breaker->last_failure_at}";
```

### Circuit Opens Too Quickly

If circuits open too aggressively:

1. Increase failure threshold
2. Implement retry logic before circuit breaker
3. Add request timeout handling

```php
CircuitBreaker::for('service')
    ->withThreshold(10) // Increase from default 5
    ->execute(function () {
        return Http::retry(3, 100) // Retry before failing
            ->timeout(5)
            ->get('https://api.example.com');
    });
```

### Memory Issues with Many Circuits

For applications with many circuit breakers:

1. Enable circuit cleanup scheduler
2. Set appropriate retention periods
3. Monitor database table sizes

```php
// In App\Console\Kernel
Schedule::call(function () {
    CircuitBreaker::where('state', 'closed')
        ->where('updated_at', '<', now()->subDays(30))
        ->delete();
})->daily();
```

## Next Steps

- **[Resource Limits](resource-limits.md)** - Configure and enforce resource constraints
- **[Health Monitoring](health-monitoring.md)** - Advanced health checks and recovery
- **[Events](events.md)** - Complete event reference and listeners
- **[Advanced Usage](advanced-usage.md)** - Worker pools, deployment coordination, and more
- **[Configuration](configuration.md)** - Complete configuration reference
