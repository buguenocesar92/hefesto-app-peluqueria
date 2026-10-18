<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de los seeders.
 *
 * Por omisión no siembra NADA: una instalación real se da de alta con el comando
 * `php artisan peluqueria:crear-salon`, no con datos de ejemplo.
 *
 * Para sembrar el salón de ejemplo hay que pedirlo explícito:
 *
 *     php artisan db:seed --class=Database\\Seeders\\SalonDeEjemploSeeder
 *
 * Eso es a propósito: un `db:seed` sin argumentos en una instalación con datos
 * reales no debe escribir nada.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Vacío a propósito. Ver la nota del encabezado.
    }
}