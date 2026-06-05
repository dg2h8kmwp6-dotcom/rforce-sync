<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Sheets\Contracts\SheetsExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stream a normalized dataset to a Google Sheet.
 *
 * No-ops unless the SheetsExporter implementation is enabled. The dataset name
 * resolves to a model + column list inside this job — kept here so the export
 * surface stays a single moving part.
 */
class ExportDatasetToGoogleSheetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $dataset,
        public readonly array $options = [],
    ) {
        $this->onQueue((string) config('rforce.sync.queue', 'default'));
    }

    public function handle(SheetsExporter $exporter): void
    {
        $resolver = $this->resolvers()[$this->dataset] ?? null;
        if ($resolver === null) {
            throw new \InvalidArgumentException("Unknown dataset: {$this->dataset}");
        }

        $rows = $resolver();
        $exporter->export($this->dataset, $rows, $this->options);
    }

    /**
     * Map dataset name => callable returning rows to export.
     *
     * Keeping resolution data-driven so a new dataset is a one-line addition.
     *
     * @return array<string, callable():array<int, array<string,mixed>>>
     */
    private function resolvers(): array
    {
        return [
            'accounts' => static fn (): array => \App\Models\NormalizedAccount::query()
                ->orderBy('name')
                ->get()
                ->map(fn ($a): array => [
                    'external_id'    => $a->external_id,
                    'name'           => $a->name,
                    'industry'       => $a->industry,
                    'website'        => $a->website,
                    'phone'          => $a->phone,
                    'annual_revenue' => $a->annual_revenue,
                    'last_synced_at' => optional($a->last_synced_at)->toIso8601String(),
                ])
                ->all(),

            'contacts' => static fn (): array => \App\Models\NormalizedContact::query()
                ->orderBy('last_name')
                ->get()
                ->map(fn ($c): array => [
                    'external_id'         => $c->external_id,
                    'account_external_id' => $c->account_external_id,
                    'first_name'          => $c->first_name,
                    'last_name'           => $c->last_name,
                    'email'               => $c->email,
                    'phone'               => $c->phone,
                    'last_synced_at'      => optional($c->last_synced_at)->toIso8601String(),
                ])
                ->all(),

            'opportunities' => static fn (): array => \App\Models\NormalizedOpportunity::query()
                ->orderByDesc('close_date')
                ->get()
                ->map(fn ($o): array => [
                    'external_id'         => $o->external_id,
                    'account_external_id' => $o->account_external_id,
                    'name'                => $o->name,
                    'stage'               => $o->stage,
                    'amount'              => $o->amount,
                    'close_date'          => optional($o->close_date)->toDateString(),
                    'is_won'              => $o->is_won,
                    'is_closed'           => $o->is_closed,
                ])
                ->all(),
        ];
    }
}
