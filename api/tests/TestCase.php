<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\RolSalon;
use App\Models\Clienta;
use App\Models\Profesional;
use App\Models\Producto;
use App\Models\Salon;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoSalon;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de las pruebas de Peluqueria.
 *
 * POR QUÉ TODAS LAS PRUEBAS NECESITAN `dentroDe`
 * ----------------------------------------------
 * Las tablas de negocio tienen Row Level Security FORZADO, para que las
 * políticas apliquen también al dueño de la tabla (que es el usuario con el que
 * se conecta Laravel). Consecuencia: si una prueba crea filas sin declarar el
 * salón al que pertenecen, la base las rechaza.
 *
 * Eso no es un estorbo para probar, es parte de lo que se prueba. `dentroDe` es
 * el mismo camino que usa la petición real: una transacción con el contexto
 * fijado y alcance de transacción.
 *
 * POSTGRESQL Y NO SQLITE
 * ----------------------
 * En SQLite estas pruebas no comprobarían nada, porque no hay RLS: pasarían en
 * verde sobre un sistema sin aislamiento. Por eso `phpunit.xml` fija
 * `DB_CONNECTION=pgsql` y una base de pruebas PostgreSQL. Ver docs/10.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Ejecuta una operación dentro del contexto de un salón.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    protected function dentroDe(Usuario|Salon $contexto, Closure $operacion): mixed
    {
        $salonId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return app(ContextoSalon::class)->ejecutarAislado($salonId, $operacion);
    }

    /**
     * Crea un salón.
     *
     * `salones` no tiene RLS a propósito (se consulta antes de que exista
     * `app.tenant_id`), así que se puede crear sin contexto.
     */
    protected function crearSalon(string $slug = 'salon-de-prueba'): Salon
    {
        return Salon::query()->create([
            'slug' => $slug,
            'nombre' => 'Salón de prueba',
            'nombre_corto' => 'Prueba',
            'activo' => true,
        ]);
    }

    /**
     * Crea una cuenta. Sin ficha de profesional salvo que se pida.
     */
    protected function crearUsuario(
        Usuario|Salon $contexto,
        RolSalon $rol = RolSalon::Recepcion,
        ?string $profesionalId = null,
    ): Usuario {
        $salonId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Usuario => Usuario::query()->create([
            'tenant_id' => $salonId,
            'nombre' => 'Persona de prueba',
            'correo' => 'persona' . substr(md5($salonId . $rol->value), 0, 8) . '@ejemplo.cl',
            'rol' => $rol->value,
            'profesional_id' => $profesionalId,
            'activo' => true,
        ]));
    }

    /**
     * Crea una profesional y su cuenta con rol `profesional`.
     *
     * La cuenta va CON ficha de profesional: una profesional sin cuenta puede
     * atender, pero no puede cerrar su corte ni ver su liquidación. Ver
     * CapacidadesDeUsuario.
     *
     * @return array{0: Usuario, 1: Profesional}
     */
    protected function crearProfesional(Usuario|Salon $contexto, string $nombre = 'Profesional de prueba'): array
    {
        $salonId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, function () use ($salonId, $nombre): array {
            $profesional = Profesional::query()->create([
                'tenant_id' => $salonId,
                'nombre' => $nombre,
                'especialidad' => 'Peluquería',
                'silla' => 'S1',
            ]);

            $usuario = Usuario::query()->create([
                'tenant_id' => $salonId,
                'nombre' => $nombre,
                'correo' => 'profesional' . substr(md5($salonId . $nombre), 0, 8) . '@ejemplo.cl',
                'rol' => RolSalon::Profesional->value,
                'profesional_id' => $profesional->id,
                'activo' => true,
            ]);

            return [$usuario, $profesional];
        });
    }

    protected function crearClienta(Usuario|Salon $contexto, string $nombre = 'Clienta'): Clienta
    {
        $salonId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Clienta => Clienta::query()->create([
            'tenant_id' => $salonId,
            'nombre' => $nombre,
            'apellido' => 'De Prueba',
            'telefono' => '+56 9 0000 0000',
            'activo' => true,
        ]));
    }

    /**
     * Crea un servicio y lo habilita para una profesional.
     *
     * La duración y el precio NO tienen valor por omisión en el software: se
     * declaran en el catálogo. Acá se pasan como argumentos porque una prueba
     * necesita cifras concretas para poder comprobar una cuenta.
     */
    protected function crearServicio(
        Usuario|Salon $contexto,
        string $nombre = 'Corte de pelo',
        int $duracionMinutos = 30,
        int $precio = 15000,
        ?Profesional $profesional = null,
    ): Servicio {
        $salonId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, function () use ($salonId, $nombre, $duracionMinutos, $precio, $profesional): Servicio {
            $servicio = Servicio::query()->create([
                'tenant_id' => $salonId,
                'nombre' => $nombre,
                'duracion_minutos' => $duracionMinutos,
                'precio_base' => $precio,
                'activo' => true,
            ]);

            if ($profesional !== null) {
                $profesional->servicios()->attach($servicio->id, [
                    'tenant_id' => $salonId,
                    'duracion_minutos' => null,
                    'precio' => null,
                    'activo' => true,
                ]);
            }

            return $servicio;
        });
    }

    protected function crearProducto(Usuario|Salon $contexto, string $nombre = 'Shampoo', int $precio = 12000): Producto
    {
        $salonId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Producto => Producto::query()->create([
            'tenant_id' => $salonId,
            'nombre' => $nombre,
            'precio_venta' => $precio,
            'activo' => true,
        ]));
    }
}