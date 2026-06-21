<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\QueryDefinition;
use App\Models\RawRforceRecord;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\RForceQueryResult;
use App\Services\RForce\RForceQueryExecutor;
use App\Services\RForce\RForceSyncManager;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_hash_is_deterministic_for_identical_records(): void
    {
        $hashA = hash('sha256', json_encode(['Id' => 'A1', 'Name' => 'Acme']));
        $hashB = hash('sha256', json_encode(['Id' => 'A1', 'Name' => 'Acme']));
        $this->assertSame($hashA, $hashB);
    }

    public function test_first_sync_buckets_everything_as_inserted(): void
    {
        [$sync, $runs] = $this->makeSync();
        $definition = $this->makeDef();
        $source = $definition->externalSource;
        $run = $runs->start($source, 'accounts');

        $diff = $sync->upsertWithChangeDetection($source, $definition, $run, [
            ['Id' => 'A1', 'Name' => 'Acme'],
            ['Id' => 'A2', 'Name' => 'Globex'],
        ]);

        $this->assertSame(['inserted' => 2, 'changed' => 0, 'unchanged' => 0, 'malformed' => 0], $diff);
        $this->assertSame(2, RawRforceRecord::query()->count());

        $row = RawRforceRecord::query()->where('external_id', 'A1')->firstOrFail();
        $this->assertNotNull($row->first_seen_at);
        $this->assertNotNull($row->last_seen_at);
        $this->assertSame($row->first_seen_at->toIso8601String(), $row->last_seen_at->toIso8601String());
    }

    public function test_attributes_block_populates_source_object_type_and_source_url(): void
    {
        [$sync, $runs] = $this->makeSync();
        $definition = $this->makeDef();
        $source = $definition->externalSource;
        $run = $runs->start($source, 'accounts');

        $sync->upsertWithChangeDetection($source, $definition, $run, [
            [
                'attributes' => [
                    'type' => 'Account',
                    'url'  => '/services/data/v60.0/sobjects/Account/001abc',
                ],
                'Id'   => 'A1',
                'Name' => 'Acme',
            ],
        ]);

        $row = RawRforceRecord::query()->where('external_id', 'A1')->firstOrFail();
        $this->assertSame('Account', $row->source_object_type);
        $this->assertSame('/services/data/v60.0/sobjects/Account/001abc', $row->source_url);
    }

    public function test_second_sync_buckets_unchanged_vs_changed_vs_new(): void
    {
        [$sync, $runs] = $this->makeSync();
        $definition = $this->makeDef();
        $source = $definition->externalSource;
        $run1 = $runs->start($source, 'accounts');

        $sync->upsertWithChangeDetection($source, $definition, $run1, [
            ['Id' => 'A1', 'Name' => 'Acme'],
            ['Id' => 'A2', 'Name' => 'Globex'],
        ]);

        $a1Before = RawRforceRecord::query()->where('external_id', 'A1')->firstOrFail();
        $originalFirstSeen = $a1Before->first_seen_at;

        // Force a clock gap so last_seen_at clearly moves forward.
        sleep(1);

        $run2 = $runs->start($source, 'accounts');
        $diff = $sync->upsertWithChangeDetection($source, $definition, $run2, [
            ['Id' => 'A1', 'Name' => 'Acme Updated'],   // changed
            ['Id' => 'A2', 'Name' => 'Globex'],          // unchanged
            ['Id' => 'A3', 'Name' => 'Initech'],         // new
        ]);

        $this->assertSame(['inserted' => 1, 'changed' => 1, 'unchanged' => 1, 'malformed' => 0], $diff);

        $a1After = RawRforceRecord::query()->where('external_id', 'A1')->firstOrFail();
        $this->assertSame($originalFirstSeen->toIso8601String(), $a1After->first_seen_at->toIso8601String());
        $this->assertGreaterThan($originalFirstSeen->getTimestamp(), $a1After->last_seen_at->getTimestamp());
        $this->assertSame('Acme Updated', $a1After->payload['Name']);
    }

    public function test_records_without_id_are_counted_as_malformed(): void
    {
        [$sync, $runs] = $this->makeSync();
        $definition = $this->makeDef();
        $source = $definition->externalSource;
        $run = $runs->start($source, 'accounts');

        $diff = $sync->upsertWithChangeDetection($source, $definition, $run, [
            ['Name' => 'No id at all'],
            ['Id' => 'A1', 'Name' => 'Has id'],
        ]);

        $this->assertSame(1, $diff['malformed']);
        $this->assertSame(1, $diff['inserted']);
        $this->assertSame(1, RawRforceRecord::query()->count());
    }

    /** @return array{0:RForceSyncManager,1:SyncRunManager} */
    private function makeSync(): array
    {
        // Connector unused in upsertWithChangeDetection; pass a stub.
        $stub = new class implements RForceConnector {
            public function ping(): bool { return true; }
            public function query(string $soql): RForceQueryResult
            {
                return new RForceQueryResult(0, true, null, []);
            }
            public function queryMore(string $nextRecordsUrl): RForceQueryResult
            {
                return new RForceQueryResult(0, true, null, []);
            }
        };
        $runs = new SyncRunManager();
        $sync = new RForceSyncManager(new RForceQueryExecutor($stub), $runs);
        return [$sync, $runs];
    }

    private function makeDef(): QueryDefinition
    {
        $source = (new SyncRunManager())->defaultSource();
        return QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.all',
            'soql'               => 'SELECT Id, Name FROM Account',
            'target_dataset'     => 'accounts',
            'enabled'            => true,
        ]);
    }
}
