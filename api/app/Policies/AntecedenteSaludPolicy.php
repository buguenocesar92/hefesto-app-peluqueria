<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AntecedenteSalud;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Antecedentes de salud: dato SENSIBLE bajo la Ley 19.628.
 *
 * QUIÉN PUEDE ABRIR
 * -----------------
 * Los tres roles. Es una decisión consciente y va a parecerse a un error si se
 * lee rápido: si recepción no puede ver una alergia al agendar, el sistema es
 * PEOR que una hoja de papel, porque la hoja al menos se mira.
 *
 * La protección real no es esta Policy: es que cada apertura queda AUDITADA, con
 * quién y con qué resultado. Ver App\Http\Middleware\AuditarSalud y docs/07.
 *
 * QUIÉN PUEDE PONER LA SEVERIDAD
 * ------------------------------
 * La profesional que atiende, y administración. Es el dato que decide si algo
 * bloquea, y lo pone quien va a ver el pelo todos los días. Recepción puede
 * registrar lo que la clienta informa, pero no decide cuánto pesa.
 */
class AntecedenteSaludPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'ver_antecedentes');
    }

    public function view(Usuario $usuario, AntecedenteSalud $antecedente): bool
    {
        return $this->esDelMismoSalon($usuario, $antecedente) && $this->puede($usuario, 'ver_antecedentes');
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'registrar_antecedentes');
    }

    public function update(Usuario $usuario, AntecedenteSalud $antecedente): bool
    {
        if (! $this->esDelMismoSalon($usuario, $antecedente)) {
            return false;
        }

        if ($this->puede($usuario, 'gestionar_clientes')) {
            return true;
        }

        // Una profesional puede corregir lo que registró sobre una clienta suya.
        return $usuario->profesional_id !== null
            && (string) $usuario->profesional_id === (string) $antecedente->registrado_por;
    }

    /**
     * Cerrar un antecedente: se marca `activo = false` y deja de avisar.
     *
     * Un antecedente no se borra. Puede haber pasado (una alergia temporal, por
     * ejemplo) y el registro dice que existió.
     */
    public function delete(Usuario $usuario, AntecedenteSalud $antecedente): bool
    {
        return $this->update($usuario, $antecedente);
    }
}