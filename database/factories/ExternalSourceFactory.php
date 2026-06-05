<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExternalSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalSource>
 */
class ExternalSourceFactory extends Factory
{
    protected $model = ExternalSource::class;

    public function definition(): array
    {
        return [
            'name'   => 'rforce',
            'label'  => 'rForce / Salesforce gateway',
            'config' => ['base_url' => 'https://gateway.example.com/rforce'],
            'active' => true,
        ];
    }
}
