<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Controlador base.
 *
 * Lo único que trae es el uso de `AuthorizesRequests`, que es lo que activa
 * `$this->authorize()`. Las Policies se descubren por convención: el modelo
 * `Cita` usa `CitaPolicy`, y así sucesivamente. No hay registro manual.
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;
}