<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\QueryDefinition;
use App\Models\RawRforceRecord;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\RForceQueryResult;
use App\Services\RForce\Exceptions\RForceException;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidateQueriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_continues_validating_after_a_failed_query(): void
    {
        $this->bindConnector(new SelectiveConnector([
            'accounts.smoke'      => new RForceQueryResult(1, true, null, [['Id' => 'A1']]),
            'orders.incremental'  => new RForceException('Field "Imaginary__c" does not exist on Order'),
            'products.incremental'=> new RForceQueryResult(3, true, null, [
                ['Id' => 'P1'], ['Id' => 'P2'], ['Id' => 'P3'],
            ]),
        ]));

        $this->seedDef('accounts.smoke',       'accounts', 'SELECT Name FROM Account LIMIT 1');
        $this->seedDef('orders.incremental',   'orders',   'SELECT Id, Imaginary__c FROM Order');
        $this->seedDef('products.incremental', 'products', 'SELECT Id FROM Product2');

        $this->artisan('rforce:validate-queries')
            ->expectsOutputToContain('Imaginary__c')
            ->assertFailed();

        // Returns FAILURE because one query failed, but every query was
        // attempted (failure didn't short-circuit the loop).
        /** @var SelectiveConnector $connector */
        $connector = app(RForceConnector::class);
        $this->assertSame(
            ['accounts.smoke', 'orders.incremental', 'products.incremental'],
            $connector->called(),
        );
    }

    public function test_only_flag_limits_validation_scope(): void
    {
        $this->bindConnector(new SelectiveConnector([
            'orders.incremental' => new RForceQueryResult(0, true, null, []),
        ]));

        $this->seedDef('accounts.smoke',      'accounts', 'SELECT Name FROM Account LIMIT 1');
        $this->seedDef('orders.incremental',  'orders',   'SELECT Id FROM Order');
        $this->seedDef('contacts.incremental','contacts', 'SELECT Id FROM Contact');

        $this->artisan('rforce:validate-queries', ['--only' => ['orders.incremental']])
            ->assertSuccessful();

        /** @var SelectiveConnector $connector */
        $connector = app(RForceConnector::class);
        $this->assertSame(['orders.incremental'], $connector->called());
    }

    public function test_does_not_persist_raw_records(): void
    {
        $this->bindConnector(new SelectiveConnector([
            'accounts.smoke' => new RForceQueryResult(1, true, null, [['Id' => 'A1', 'Name' => 'Acme']]),
        ]));

        $this->seedDef('accounts.smoke', 'accounts', 'SELECT Name FROM Account LIMIT 1');

        $this->artisan('rforce:validate-queries')->assertSuccessful();

        $this->assertSame(0, RawRforceRecord::query()->count());
    }

    public function test_validation_caps_the_saved_limit(): void
    {
        $captured = null;
        $connector = new class($captured) implements RForceConnector {
            public ?string $lastSoql = null;
            public function __construct(?string $capture) {}
            public function ping(): bool { return true; }
            public function query(string $soql): RForceQueryResult
            {
                $this->lastSoql = $soql;
                return new RForceQueryResult(0, true, null, []);
            }
            public function queryMore(string $nextRecordsUrl): RForceQueryResult
            {
                return new RForceQueryResult(0, true, null, []);
            }
        };
        $this->app->instance(RForceConnector::class, $connector);

        // Saved query has a much larger LIMIT than the validation cap.
        $this->seedDef('orders.incremental', 'orders', 'SELECT Id FROM Order LIMIT 5000');

        $this->artisan('rforce:validate-queries', ['--limit' => 7])
            ->assertSuccessful();

        $this->assertSame('SELECT Id FROM Order LIMIT 7', $connector->lastSoql);
    }

    public function test_no_definitions_produces_warning_not_failure(): void
    {
        $this->bindConnector(new SelectiveConnector([]));

        $this->artisan('rforce:validate-queries')
            ->expectsOutputToContain('No enabled QueryDefinitions')
            ->assertSuccessful();
    }

    private function seedDef(string $name, string $dataset, string $soql): QueryDefinition
    {
        $source = app(SyncRunManager::class)->defaultSource();
        return QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => $name,
            'soql'               => $soql,
            'target_dataset'     => $dataset,
            'enabled'            => true,
        ]);
    }

    private function bindConnector(SelectiveConnector $connector): void
    {
        $this->app->instance(RForceConnector::class, $connector);
    }
}

/**
 * Test connector that dispatches by the SOQL's FROM-clause object name.
 * Each entry is either a RForceQueryResult to return or an Exception to throw.
 */
class SelectiveConnector implements RForceConnector
{
    /** @var array<string, RForceQueryResult|\Throwable> */
    private array $byQuery;

    private array $called = [];

    /** @param array<string, RForceQueryResult|\Throwable> $byQuery keyed by query NAME */
    public function __construct(array $byQuery)
    {
        $this->byQuery = $byQuery;
    }

    public function ping(): bool
    {
        return true;
    }

    public function query(string $soql): RForceQueryResult
    {
        $name = $this->matchKey($soql);
        $this->called[] = $name;
        $response = $this->byQuery[$name] ?? null;

        if ($response instanceof \Throwable) {
            throw $response;
        }
        if ($response instanceof RForceQueryResult) {
            return $response;
        }
        // Unknown SOQL — return an empty page so the test doesn't have to
        // enumerate every catalog query just to assert one.
        return new RForceQueryResult(0, true, null, []);
    }

    public function queryMore(string $nextRecordsUrl): RForceQueryResult
    {
        return new RForceQueryResult(0, true, null, []);
    }

    /** @return list<string> */
    public function called(): array
    {
        return $this->called;
    }

    /**
     * Match SOQL to a registered query name by sniffing the FROM clause
     * + WHERE shape. Tests register by the QueryDefinition NAME for clarity;
     * we map back via this lookup table.
     */
    private function matchKey(string $soql): string
    {
        $matchers = [
            'accounts.smoke'       => fn (string $s): bool => str_contains($s, 'FROM Account') && str_contains($s, 'LIMIT 1') && ! str_contains($s, 'WHERE'),
            'accounts.incremental' => fn (string $s): bool => str_contains($s, 'FROM Account')   && str_contains($s, 'WHERE'),
            'orders.incremental'   => fn (string $s): bool => str_contains($s, 'FROM Order')     && ! str_contains($s, 'FROM OrderItem'),
            'contacts.incremental' => fn (string $s): bool => str_contains($s, 'FROM Contact'),
            'order_products.incremental' => fn (string $s): bool => str_contains($s, 'FROM OrderItem'),
            'products.incremental' => fn (string $s): bool => str_contains($s, 'FROM Product2'),
            'sales_appointments.incremental' => fn (string $s): bool => str_contains($s, 'FROM Sales_Appointment__c'),
        ];

        foreach ($matchers as $name => $match) {
            if ($match($soql)) {
                return $name;
            }
        }
        return '_unknown';
    }
}
