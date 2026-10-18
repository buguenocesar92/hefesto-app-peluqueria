<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BaseCalculo;
use App\Enums\RolSalon;
use App\Models\PoliticaComision;
use App\Models\Producto;
use App\Models\Profesional;
use App\Models\Salon;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoSalon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Un salón de ejemplo, para probar la instalación sin meter datos a mano.
 *
 * QUÉ CREA
 * --------
 * Un salón, una administradora, dos profesionales, un catálogo de servicios, dos
 * productos y UNA política de comisión de ejemplo.
 *
 * LA COMISIÓN ES UN VALOR DE EJEMPLO, NO UN VALOR POR OMISIÓN
 * -------------------------------------------------------
 * El seeder escribe porcentajes concretos para que se pueda ver cómo se ve una
 * liquidación. Eso NO significa que un salón pague eso: la política se declara por
 * salón y el cálculo falla si no hay ninguna. Ver docs/06.
 *
 * NADA DE ESTO ES DATO REAL
 * ------------------------
 * Los nombres, los precios y los porcentajes de este seeder son inventados para
 * poder probar. Un salón real declara los suyos. Ver docs/10.
 */
class SalonDeEjemploSeeder extends Seeder
{
    public function run(ContextoSalon $contexto): void
    {
        $salon = Salon::query()->create([
            'slug' => 'peluqueria-de-ejemplo',
            'nombre' => 'Salón de Ejemplo',
            'nombre_corto' => 'Ejemplo',
            'direccion' => 'Dirección de ejemplo',
            'telefono' => '+56 2 0000 0000',
            'correo' => 'contacto@ejemplo.cl',
            'activo' => true,
        ]);

        $contexto->ejecutarAislado((string) $salon->id, function () use ($salon): void {
            Usuario::query()->create([
                'tenant_id' => $salon->id,
                'nombre' => 'Administradora de Ejemplo',
                'correo' => 'admin@ejemplo.cl',
                'rol' => RolSalon::Administrador->value,
                'activo' => true,
            ]);

            $primera = Profesional::query()->create([
                'tenant_id' => $salon->id,
                'nombre' => 'Profesional de Ejemplo Uno',
                'especialidad' => 'Color',
                'silla' => 'S1',
                'registro_profesional' => null,
                'registro_verificado' => false,
                'color_agenda' => '#2f6f6f',
                'activo' => true,
            ]);

            $segunda = Profesional::query()->create([
                'tenant_id' => $salon->id,
                'nombre' => 'Profesional de Ejemplo Dos',
                'especialidad' => 'Corte',
                'silla' => 'S2',
                'registro_profesional' => null,
                'registro_verificado' => false,
                'color_agenda' => '#8a4f6f',
                'activo' => true,
            ]);

            $corte = Servicio::query()->create([
                'tenant_id' => $salon->id,
                'nombre' => 'Corte de pelo',
                'duracion_minutos' => 30,
                'precio_base' => 15000,
                'categoria' => 'corte',
                'es_reposicion' => false,
                'activo' => true,
            ]);

            $color = Servicio::query()->create([
                'tenant_id' => $salon->id,
                'nombre' => 'Color completo',
                'duracion_minutos' => 90,
                'precio_base' => 45000,
                'categoria' => 'color',
                // Deja el pelo en un estado del que hay que esperar antes de
                // aplicar otro proceso. Los días que exige NO están en el
                // software porque no se pudieron verificar. Ver docs/02.
                'es_reposicion' => true,
                'activo' => true,
            ]);

            foreach ([$primera, $segunda] as $profesional) {
                $profesional->servicios()->attach([$corte->id, $color->id], [
                    'tenant_id' => $salon->id,
                    'duracion_minutos' => null,
                    'precio' => null,
                    'activo' => true,
                ]);
            }

            Producto::query()->create([
                'tenant_id' => $salon->id,
                'nombre' => 'Shampoo de ejemplo',
                'precio_venta' => 12000,
                'stock' => null,
                'unidad' => 'unidad',
                'activo' => true,
            ]);

            Producto::query()->create([
                'tenant_id' => $salon->id,
                'nombre' => 'Tratamiento de ejemplo',
                'precio_venta' => 25000,
                'stock' => null,
                'unidad' => 'unidad',
                'activo' => true,
            ]);

            /*
             * Política DE EJEMPLO. Los porcentajes son inventados para poder ver
             * una liquidación funcionando; no son una recomendación ni un valor
             * por omisión del producto.
             */
            PoliticaComision::query()->create([
                'tenant_id' => $salon->id,
                'profesional_id' => null,
                'porcentaje_servicios' => 40.00,
                'porcentaje_productos' => 10.00,
                'incluye_productos' => true,
                'base_calculo' => BaseCalculo::Ambos->value,
                'vigente_desde' => Carbon::now()->startOfYear()->toDateString(),
                'vigente_hasta' => null,
                'notas' => 'Política de ejemplo del seeder. Cámbiala por la que acuerde el salón.',
            ]);
        });
    }
}