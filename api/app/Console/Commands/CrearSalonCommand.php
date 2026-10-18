<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RolSalon;
use App\Models\Salon;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoSalon;
use Illuminate\Console\Command;

/**
 * Crea un salón con su primer administrador.
 *
 * POR QUÉ HACE FALTA UN COMANDO
 * ----------------------------
 * `salones` es la única tabla SIN Row Level Security, porque hay que poder
 * leerla antes de que exista un `app.tenant_id` en la sesión. Eso significa que
 * el alta del primer salón no se puede hacer desde la aplicación: sería un
 * agujero en el aislamiento.
 *
 * La excepción controlada es esta, y por eso tiene que ser un comando de
 * consola, con una lista de pasos explícitos y no un endpoint. Ver docs/03.
 *
 *     php artisan peluqueria:crear-salon \
 *         --slug=centro --nombre="Peluquería Centro" \
 *         --nombre-corto=Centro \
 *         --administrador="Nombre Apellido" --correo-admin=persona@ejemplo.cl
 *
 * LO QUE NO HACE
 * --------------
 * No crea la identidad: eso es Authentik. La cuenta que crea acá es la copia de
 * trabajo, y solo va a servir cuando en Authentik exista una persona con el mismo
 * correo en el equipo `salon-<slug>`. Ver docs/05.
 */
class CrearSalonCommand extends Command
{
    protected $signature = 'peluqueria:crear-salon
        {--slug= : Identificador corto y único. Es lo que va en el grupo de Authentik.}
        {--nombre= : Nombre completo del salón.}
        {--nombre-corto= : Nombre abreviado, para la cabecera de la interfaz.}
        {--direccion= : Dirección, opcional.}
        {--telefono= : Teléfono, opcional.}
        {--correo= : Correo del salón, opcional.}
        {--administrador= : Nombre de la primera persona administradora.}
        {--correo-admin= : Correo de esa persona. Debe coincidir con el de Authentik.}';

    protected $description = 'Crea un salón con su primer administrador. No crea la identidad en Authentik.';

    public function handle(ContextoSalon $contexto): int
    {
        $slug = (string) $this->option('slug');
        $nombre = (string) $this->option('nombre');
        $nombreCorto = (string) $this->option('nombre-corto');
        $administrador = (string) $this->option('administrador');
        $correoAdmin = (string) $this->option('correo-admin');

        $faltantes = [];

        foreach ([
            'slug' => $slug,
            'nombre' => $nombre,
            'nombre-corto' => $nombreCorto,
            'administrador' => $administrador,
            'correo-admin' => $correoAdmin,
        ] as $clave => $valor) {
            if ($valor === '') {
                $faltantes[] = $clave;
            }
        }

        if ($faltantes !== []) {
            $this->components->error('Faltan opciones obligatorias: ' . implode(', ', $faltantes));

            return self::FAILURE;
        }

        if (Salon::query()->where('slug', $slug)->exists()) {
            $this->components->error("Ya existe un salón con el identificador {$slug}.");

            return self::FAILURE;
        }

        if (! filter_var($correoAdmin, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('El correo del administrador no tiene un formato válido.');

            return self::FAILURE;
        }

        // `salones` no tiene Row Level Security: se puede escribir sin contexto.
        // Es la excepción controlada del patrón, y por eso este paso va antes
        // de fijar el salón.
        $salon = Salon::query()->create([
            'slug' => $slug,
            'nombre' => $nombre,
            'nombre_corto' => $nombreCorto,
            'direccion' => $this->option('direccion'),
            'telefono' => $this->option('telefono'),
            'correo' => $this->option('correo'),
            'activo' => true,
        ]);

        // A partir de acá sí: todo lo que se escribe va con `tenant_id`
        // explícito y dentro del contexto, que es la misma regla que sigue la
        // aplicación en cada petición.
        $usuario = $contexto->ejecutarAislado((string) $salon->id, fn (): Usuario => Usuario::query()->create([
            'tenant_id' => (string) $salon->id,
            'nombre' => $administrador,
            'correo' => $correoAdmin,
            'rol' => RolSalon::Administrador->value,
            'activo' => true,
        ]));

        $this->components->info('Salón creado.');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Identificador', $salon->slug],
                ['Nombre', $salon->nombre],
                ['Identificador del salón', (string) $salon->id],
                ['Administrador', $usuario->nombre],
                ['Correo', $usuario->correo],
                ['Rol', $usuario->rol->etiqueta()],
            ],
        );

        $this->components->warn('La IDENTIDAD todavía no existe.');
        $this->components->warn("En Authentik hay que crear el equipo 'salon-{$slug}' "
            . "y agregar a esa persona a 'Administradores de Peluqueria'.");
        $this->components->warn('Hasta que eso exista, el ingreso va a fallar con un mensaje claro.');
        $this->components->warn('Después, corra: php artisan peluqueria:verificar-aislamiento');
        $this->components->warn('Ver docs/05.');

        return self::SUCCESS;
    }
}