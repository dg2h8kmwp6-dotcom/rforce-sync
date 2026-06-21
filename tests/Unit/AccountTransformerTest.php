<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\NormalizedAccount;
use App\Models\RawRforceRecord;
use App\Services\RForce\Transformers\AccountTransformer;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountTransformerTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_promoted_columns_and_keeps_unknown_keys_in_extra(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        $run     = $manager->start($source, 'accounts');

        $payload = [
            'Id'                => 'A1',
            'Name'              => 'Acme',
            'AccountNumber'     => '12345',
            'Industry'          => 'Manufacturing',
            'Website'           => 'https://acme.test',
            'AnnualRevenue'     => '1000000.50',
            'NumberOfEmployees' => 42,
            'BillingCity'       => 'Springfield',
            'OwnerId'           => 'U99',
            'CreatedDate'       => '2026-01-01T00:00:00Z',
            'LastModifiedDate'  => '2026-05-01T12:00:00Z',
            'CustomNotes__c'    => 'extra info',
        ];

        $raw = RawRforceRecord::query()->create([
            'external_source_id' => $source->id,
            'last_sync_run_id'   => $run->id,
            'source_object_type' => 'Account',
            'external_id'        => 'A1',
            'payload'            => $payload,
            'payload_hash'       => hash('sha256', json_encode($payload)),
            'first_seen_at'      => Carbon::now(),
            'last_seen_at'       => Carbon::now(),
        ]);

        (new AccountTransformer())->transform($raw, $run);

        $account = NormalizedAccount::query()->where('external_id', 'A1')->firstOrFail();

        $this->assertSame('Acme', $account->name);
        $this->assertSame('12345', $account->account_number);
        $this->assertSame('Manufacturing', $account->industry);
        $this->assertSame('Springfield', $account->billing_city);
        $this->assertEquals('1000000.50', $account->annual_revenue);
        $this->assertSame(42, $account->employee_count);
        $this->assertSame('U99', $account->owner_external_id);
        $this->assertNotNull($account->external_modified_at);
        $this->assertNotNull($account->last_synced_at);

        // Unknown payload field should be retained in `extra`.
        $this->assertIsArray($account->extra);
        $this->assertSame('extra info', $account->extra['CustomNotes__c'] ?? null);
    }

    public function test_transform_is_idempotent(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        $run     = $manager->start($source, 'accounts');

        $payload = ['Id' => 'A1', 'Name' => 'Acme'];
        $raw = RawRforceRecord::query()->create([
            'external_source_id' => $source->id,
            'last_sync_run_id'   => $run->id,
            'source_object_type' => 'Account',
            'external_id'        => 'A1',
            'payload'            => $payload,
            'payload_hash'       => hash('sha256', json_encode($payload)),
            'first_seen_at'      => Carbon::now(),
            'last_seen_at'       => Carbon::now(),
        ]);

        $t = new AccountTransformer();
        $t->transform($raw, $run);
        $t->transform($raw, $run);

        $this->assertSame(1, NormalizedAccount::query()->count());
    }
}
