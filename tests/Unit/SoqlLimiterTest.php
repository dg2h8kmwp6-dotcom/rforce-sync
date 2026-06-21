<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RForce\Soql\SoqlLimiter;
use Tests\TestCase;

class SoqlLimiterTest extends TestCase
{
    public function test_apply_appends_limit_when_missing(): void
    {
        $this->assertSame(
            'SELECT Id FROM Account LIMIT 10',
            SoqlLimiter::apply('SELECT Id FROM Account', 10),
        );
    }

    public function test_apply_replaces_existing_limit(): void
    {
        $this->assertSame(
            'SELECT Id FROM Account LIMIT 10',
            SoqlLimiter::apply('SELECT Id FROM Account LIMIT 500', 10),
        );
    }

    public function test_apply_replaces_limit_case_insensitively(): void
    {
        $this->assertSame(
            'SELECT Id FROM Account LIMIT 5',
            SoqlLimiter::apply('SELECT Id FROM Account limit 500', 5),
        );
    }

    public function test_apply_preserves_placeholders(): void
    {
        $this->assertSame(
            'SELECT Id FROM Order WHERE LastModifiedDate > {{last_sync_at}} LIMIT 10',
            SoqlLimiter::apply(
                'SELECT Id FROM Order WHERE LastModifiedDate > {{last_sync_at}}',
                10,
            ),
        );
    }

    public function test_apply_preserves_order_by_when_appending_limit(): void
    {
        $this->assertSame(
            'SELECT Id FROM Account ORDER BY Name DESC LIMIT 7',
            SoqlLimiter::apply('SELECT Id FROM Account ORDER BY Name DESC', 7),
        );
    }

    public function test_apply_strips_trailing_semicolon(): void
    {
        $this->assertSame(
            'SELECT Id FROM Account LIMIT 10',
            SoqlLimiter::apply('SELECT Id FROM Account;', 10),
        );
    }

    public function test_apply_returns_original_when_limit_is_null_or_zero(): void
    {
        $this->assertSame('SELECT Id FROM Account', SoqlLimiter::apply('SELECT Id FROM Account', null));
        $this->assertSame('SELECT Id FROM Account', SoqlLimiter::apply('SELECT Id FROM Account', 0));
        $this->assertSame('SELECT Id FROM Account', SoqlLimiter::apply('SELECT Id FROM Account', -5));
    }

    public function test_has_limit_detects_clause(): void
    {
        $this->assertTrue(SoqlLimiter::hasLimit('SELECT Id FROM Account LIMIT 100'));
        $this->assertTrue(SoqlLimiter::hasLimit('SELECT Id FROM Account limit 100'));
        $this->assertFalse(SoqlLimiter::hasLimit('SELECT Id FROM Account'));
        $this->assertFalse(SoqlLimiter::hasLimit('SELECT Id FROM Account WHERE Limit_Type__c = \'X\''));
    }

    public function test_cap_lowers_existing_limit_only_when_larger(): void
    {
        $this->assertSame(
            'SELECT Id FROM Account LIMIT 10',
            SoqlLimiter::cap('SELECT Id FROM Account LIMIT 500', 10),
        );
        $this->assertSame(
            'SELECT Id FROM Account LIMIT 5',
            SoqlLimiter::cap('SELECT Id FROM Account LIMIT 5', 10),
        );
    }

    public function test_cap_appends_when_no_existing_limit(): void
    {
        $this->assertSame(
            'SELECT Id FROM Account LIMIT 10',
            SoqlLimiter::cap('SELECT Id FROM Account', 10),
        );
    }

    public function test_cap_preserves_placeholders(): void
    {
        $this->assertSame(
            'SELECT Id FROM Order WHERE LastModifiedDate > {{last_sync_at}} LIMIT 10',
            SoqlLimiter::cap(
                'SELECT Id FROM Order WHERE LastModifiedDate > {{last_sync_at}}',
                10,
            ),
        );
    }

    public function test_apply_handles_multiline_soql(): void
    {
        $input = <<<'SOQL'
SELECT Id, Name, AccountId
FROM Order
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
SOQL;

        $out = SoqlLimiter::apply($input, 10);

        $this->assertStringEndsWith('LIMIT 10', $out);
        $this->assertStringContainsString('{{last_sync_at}}', $out);
        $this->assertStringContainsString('ORDER BY LastModifiedDate ASC', $out);
    }
}
