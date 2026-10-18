<?php

declare(strict_types=1);

namespace Tests\Feature\Agenda;

use App\Enums\EstadoCita;
use App\Models\AntecedenteSalud;
use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Profesional;
use App\Models\Salon;
use App\Services\Agenda\RevisorDeAntecedentes;
use App\Services\Agenda\ServicioAgenda;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Los bloqueos de agenda no se pisan.
 *
 * POR QUÉ ESTA PRUEBA ES DIFERENTE DE LA DEL SOLAPAMIENTO
 * -------------------------------------------------------
 * Porque el solapamiento ENTRE CITAS lo impide la propia base, con una
 * restricción `EXCLUDE USING gist` que no se puede esquivar. Esa parte está
 * garantizada por PostgreSQL.
 *
 * El solapamiento entre una CITA y un BLOQUEO no lo puede garantizar la base:
 * viven en tablas distintas, y PostgreSQL no escribe exclusiones entre dos
 * tablas. Esa parte la sostiene el software, y por eso necesita una prueba que la
 * revise. Ver `VerificadorDeDisponibilidad`.
 *
 * LO QUE NO SE EJECUTÓ
 * --------------------
 * Nunca se corrieron. Ver docs/10.
 */
class BloqueosNoSePisanTest extends TestCase
{
    private ServicioAgenda $agenda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agenda = app(ServicioAgenda::class);
    }

    #[Test]
    public function una_cita_no_puede_caer_dentro_de_un_bloqueo_de_la_profesional(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->crearBloqueo($salon, $profesional, '2026-03-02 13:00:00', '2026-03-02 14:00:00', 'colacion');

        // La cita cae de 13:30 a 14:00: empieza dentro del bloqueo y termina
        // justo cuando termina.
        $this->esperarChoque($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 13:30:00',
        ]));
    }

    #[Test]
    public function una_cita_que_termina_al_terminar_el_bloqueo_si_puede_agendarse(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->crearBloqueo($salon, $profesional, '2026-03-02 13:00:00', '2026-03-02 14:00:00', 'colacion');

        // De 12:30 a 13:00: termina justo cuando empieza el bloqueo.
        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 12:30:00',
        ]));

        $this->assertSame('2026-03-02 13:00:00', $cita->fecha_fin->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function un_bloqueo_de_salon_para_a_todas_las_profesionales(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        // Bloqueo sin profesional: un feriado, un corte de luz.
        $this->crearBloqueo($salon, null, '2026-03-02 09:00:00', '2026-03-02 18:00:00', 'feriado');

        $this->esperarChoque($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));
    }

    #[Test]
    public function un_bloqueo_de_otra_profesional_no_afecta_a_esta(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        [, $otra] = $this->crearProfesional($salon, 'Otra profesional');
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->crearBloqueo($salon, $otra, '2026-03-02 09:00:00', '2026-03-02 18:00:00', 'capacitacion');

        // La otra está en capacitación todo el día; esta puede trabajar.
        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        $this->assertNotNull($cita->id);
    }

    #[Test]
    public function el_motivo_del_bloqueo_aparece_en_el_mensaje_del_conflicto(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->crearBloqueo($salon, $profesional, '2026-03-02 13:00:00', '2026-03-02 14:00:00', 'colacion');

        $mensaje = null;

        $this->dentroDe($salon, static function () use ($profesional, $clienta, $servicio, &$mensaje): void {
            try {
                $this->agenda->agendar([
                    'profesional' => $profesional,
                    'clienta' => $clienta,
                    'servicio' => $servicio,
                    'fecha_inicio' => '2026-03-02 13:30:00',
                ]);
            } catch (\DomainException $e) {
                $mensaje = $e->getMessage();
            }
        });

        $this->assertNotNull($mensaje);
        $this->assertStringContainsString('colacion', $mensaje);
    }

    #[Test]
    public function un_antecedente_bloqueante_impide_agendar(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Aplicacion de tinte', 90, 45000, $profesional);

        $this->dentroDe($salon, static function () use ($salon, $clienta, $profesional): void {
            AntecedenteSalud::query()->create([
                'tenant_id' => $salon->id,
                'clienta_id' => $clienta->id,
                'tipo' => 'alergia',
                'descripcion' => 'Alergia a la amoníaco',
                'severidad' => 'grave',
                'bloquea_servicios' => true,
                'registrado_por' => $profesional->id,
                'activo' => true,
            ]);
        });

        $mensaje = null;

        $this->dentroDe($salon, static function () use ($profesional, $clienta, $servicio, &$mensaje): void {
            try {
                $this->agenda->agendar([
                    'profesional' => $profesional,
                    'clienta' => $clienta,
                    'servicio' => $servicio,
                    'fecha_inicio' => '2026-03-02 10:00:00',
                ]);
            } catch (\DomainException $e) {
                $mensaje = $e->getMessage();
            }
        });

        $this->assertNotNull($mensaje, 'Un antecedente que bloquea tiene que impedir el agendamiento.');
        $this->assertStringContainsString('amoníaco', $mensaje);
    }

    #[Test]
    public function un_antecedente_que_no_bloquea_solo_avisa(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->dentroDe($salon, static function () use ($salon, $clienta, $profesional): void {
            AntecedenteSalud::query()->create([
                'tenant_id' => $salon->id,
                'clienta_id' => $clienta->id,
                'tipo' => 'sensibilidad',
                'descripcion' => 'Cuero cabling sensible',
                'severidad' => 'leve',
                'bloquea_servicios' => false,
                'registrado_por' => $profesional->id,
                'activo' => true,
            ]);
        });

        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        $this->assertNotNull($cita->id);

        $avisos = $this->dentroDe($salon, fn (): array => $this->agenda->avisosDe($clienta, $servicio));

        $this->assertCount(1, $avisos);
        $this->assertSame('Cuero cabling sensible', $avisos[0]['descripcion']);
    }

    #[Test]
    public function la_espera_entre_procesos_queda_declarada_como_no_comprobada(): void
    {
        // No hay regla implementada porque no se pudo verificar el dato. La
        // prueba deja constancia de que eso es lo que hay, y no un olvido.
        $revisor = app(RevisorDeAntecedentes::class);

        $this->assertFalse($revisor->puedeComprobarEsperaEntreProcesos());
        $this->assertStringContainsString('no está verificado', $revisor->avisoDeEsperaNoImplementada());
    }

    private function crearBloqueo(
        Salon $salon,
        ?Profesional $profesional,
        string $desde,
        string $hasta,
        string $motivo,
    ): BloqueoAgenda {
        return $this->dentroDe($salon, fn (): BloqueoAgenda => BloqueoAgenda::query()->create([
            'tenant_id' => $salon->id,
            'profesional_id' => $profesional?->id,
            'fecha_inicio' => Carbon::parse($desde),
            'fecha_fin' => Carbon::parse($hasta),
            'motivo' => $motivo,
        ]));
    }

    /**
     * @param  \Closure(): mixed  $operacion
     */
    private function esperarChoque(Salon $salon, \Closure $operacion): void
    {
        $fallo = null;

        $this->dentroDe($salon, static function () use ($operacion, &$fallo): void {
            try {
                $operacion();
            } catch (\DomainException $e) {
                $fallo = $e;
            }
        });

        $this->assertNotNull($fallo, 'Se esperaba un DomainException y la operación pasó sin problema.');
    }
}