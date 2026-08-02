<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 99999),
            'status' => Organization::STATUS_ACTIVE,
            'timezone' => 'Asia/Karachi',
        ];
    }

    /** Set the workspace's business timezone (day/shift/attendance bucketing). */
    public function timezone(string $tz): static
    {
        return $this->state(fn () => ['timezone' => $tz]);
    }
}
