<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Author;

/**
 * @extends Factory<Author>
 */
class AuthorFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Author>
     */
    protected $model = Author::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }
}
