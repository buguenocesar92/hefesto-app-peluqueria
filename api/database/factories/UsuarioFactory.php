<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolSalon;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'nombre' => $this->faker->name(),
            'correo' => $this->faker->unique()->safeEmail(),
            'rol' => RolSalon::Recepcion->value,
            // SIEMPRE en `null`: esta aplicación no maneja contraseñas. La
            // columna existe porque Laravel la espera. Ver docs/07.
            'password' => null,
            'activo' => true,
        ];
    }

    public function administradora(): static
    {
        return $this->state(fn (): array => ['rol' => RolSalon::Administrador->value]);
    }

    public function profesional(): static
    {
        return $this->state(fn (): array => ['rol' => RolSalon::Profesional->value]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}