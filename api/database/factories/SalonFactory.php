<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Salon>
 */
class SalonFactory extends Factory
{
    protected $model = Salon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = $this->faker->unique()->company() . ' Hair';

        return [
            'id' => (string) Str::uuid(),
            'slug' => Str::slug($nombre) . '-' . Str::lower(Str::random(4)),
            'nombre' => $nombre,
            'nombre_corto' => Str::limit($nombre, 20, ''),
            'direccion' => $this->faker->address(),
            'telefono' => '+56 2 ' . $this->faker->numerify('### ####'),
            'correo' => $this->faker->unique()->safeEmail(),
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}