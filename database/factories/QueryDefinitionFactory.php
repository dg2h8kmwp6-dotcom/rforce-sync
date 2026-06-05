<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExternalSource;
use App\Models\QueryDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryDefinition>
 */
class QueryDefinitionFactory extends Factory
{
    protected $model = QueryDefinition::class;

    public function definition(): array
    {
        return [
            'external_source_id' => ExternalSource::factory(),
            'name'               => $this->faker->unique()->slug(2),
            'description'        => $this->faker->sentence(),
            'soql'               => 'SELECT Id, Name FROM Account',
            'target_object'      => 'Account',
            'transformer_class'  => null,
            'enabled'            => true,
            'schedule'           => null,
            'last_run_at'        => null,
        ];
    }
}
