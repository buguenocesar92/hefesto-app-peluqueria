<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Profesional;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Profesional>
 */
class ProfesionalFactory extends Factory
{
    protected $model = Profesional::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'nombre' => $this->faker->name(),
            'especialidad' => $this->faker->randomElement([
                'Color',
                'Corte',
                'Estética facial',
                'Manicure y pedicure',
                'Depilación',
            ]),
            'silla' => 'S' . $this->faker->numberBetween(1, 6),
            'correo' => $this->faker->safeEmail(),
            'telefono' => '+56 9 ' . $this->faker->numerify('#### ####'),
            // Texto libre y NO validado contra ninguna fuente oficial. Se deja en
            // `null` salvo que la instalación lo complete. Ver docs/07.
            'registro_profesional' => null,
            'registro_verificado' => false,
            'color_agenda' => '#' . $this->faker->hexColor(),
            'activo' => true,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}