<?php

declare(strict_types=1);

namespace Tests\Feature\Agenda;

use App\Enums\EstadoCita;
use App\Models\Cita;
use App\Models\Salon;
use App\Services\Agenda\ServicioAgenda;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La agenda se llena por TIEMPO DE SERVICIO.
 *
 * POR QUÉ ESTA SUITE EXISTE APARTE
 * -------------------------------
 * Porque es la regla que hace que un salón funcione: una cita dura lo que dura
 * el servicio, y el servicio depende de quién lo hace.
 *
 * LO QUE NO SE EJECUTÓ
 * --------------------
 * Estas pruebas nunca se corrieron: en el equipo donde se escribió este código
 * no hay PHP, ni Composer, ni PostgreSQL. Ver docs/10.
 */
class AgendaPorDuracionTest extends TestCase
{
    private ServicioAgenda $agenda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agenda = app(ServicioAgenda::class);
    }

    #[Test]
    public function la_cita_termina_cuando_termina_el_servicio(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        $this->assertSame(
            '2026-03-02 10:30:00',
            $cita->fecha_fin->format('Y-m-d H:i:s'),
            'Una cita de treinta minutos tiene que terminar a las 10:30.',
        );

        $this->assertSame(30, $cita->duracion_minutos);
    }

    #[Test]
    public function dos_servicios_distintos_duran_distinto(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);

        $corte = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);
        $balayage = $this->crearServicio($salon, 'Balayage', 150, 85000, $profesional);

        $citaCorte = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $corte,
            'fecha_inicio' => '2026-03-02 09:00:00',
        ]));

        $citaBalayage = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $balayage,
            'fecha_inicio' => '2026-03-02 11:00:00',
        ]));

        $this->assertSame(30, $citaCorte->duracion_minutos);
        $this->assertSame(150, $citaBalayage->duracion_minutos);
        $this->assertSame('2026-03-02 13:30:00', $citaBalayage->fecha_fin->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function la_duracion_de_la_profesional_manda_sobre_la_del_catalogo(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        // Esta profesional se toma el doble: el catálogo dice 30, ella tarda 60.
        $this->dentroDe($salon, static function () use ($profesional, $servicio): void {
            $profesional->servicios()->updateExistingPivot($servicio->id, [
                'duracion_minutos' => 60,
            ]);
        });

        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        $this->assertSame(60, $cita->duracion_minutos);
        $this->assertSame('2026-03-02 11:00:00', $cita->fecha_fin->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function dos_citas_de_la_misma_profesional_no_se_solapan(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 60, 15000, $profesional);

        $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        // La segunda empieza treinta minutos después de que empezó la primera, y
        // la primera dura una hora: se pisan.
        $this->esperarChoque($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:30:00',
        ]));
    }

    #[Test]
    public function una_cita_que_empieza_al_terminar_otra_no_se_pisa(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 60, 15000, $profesional);

        $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        // Esta empieza exactamente a las 11:00, cuando termina la otra. El
        // intervalo es semiabierto, así que no se pisan.
        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 11:00:00',
        ]));

        $this->assertSame('2026-03-02 12:00:00', $cita->fecha_fin->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function una_cita_anulada_libera_el_horario(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 60, 15000, $profesional);

        $primera = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        $this->dentroDe($salon, static function () use ($primera): void {
            $primera->estado = EstadoCita::Anulada;
            $primera->save();
        });

        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        $this->assertNotSame($primera->id, $cita->id);
    }

    #[Test]
    public function una_profesional_no_puede_agendar_un_servicio_que_no_hace(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);

        // El servicio existe en el catálogo, pero no se lo habilitó a esta
        // profesional.
        $servicio = $this->crearServicio($salon, 'Extensiones', 180, 120000);

        $this->esperarChoque($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));
    }

    #[Test]
    public function el_retraso_se_calcula_contra_la_hora_agendada(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        // Terminó veinte minutos después de lo previsto. En un salón eso es lo
        // normal, y el dueño necesita verlo.
        $this->dentroDe($salon, static function () use ($cita): void {
            $cita->registrarRealidad(
                Carbon::parse('2026-03-02 10:05:00'),
                Carbon::parse('2026-03-02 10:50:00'),
            );

            $cita->save();
        });

        $this->assertSame(20, $cita->minutos_retraso);
    }

    #[Test]
    public function terminar_antes_de_lo_previsto_no_es_retraso(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 60, 15000, $profesional);

        $cita = $this->dentroDe($salon, fn (): Cita => $this->agenda->agendar([
            'profesional' => $profesional,
            'clienta' => $clienta,
            'servicio' => $servicio,
            'fecha_inicio' => '2026-03-02 10:00:00',
        ]));

        $this->dentroDe($salon, static function () use ($cita): void {
            $cita->registrarRealidad(
                Carbon::parse('2026-03-02 10:00:00'),
                Carbon::parse('2026-03-02 10:45:00'),
            );

            $cita->save();
        });

        $this->assertSame(0, $cita->minutos_retraso);
    }

    /**
     * Comprueba que la operación falla con un motivo que se puede leer.
     *
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
        $this->assertNotSame('', $fallo->getMessage(), 'El motivo del conflicto no puede estar vacío.');
    }
}