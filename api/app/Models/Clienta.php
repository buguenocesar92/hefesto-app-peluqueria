<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Clienta: la ficha de identificación.
 *
 * QUÉ ES Y QUÉ NO ES
 * ------------------
 * Es el nombre, el RUT, el teléfono: lo que permite llamar a alguien y saber
 * quién es. NO es el historial. Lo que se le hizo está en `ventas` y se abre en
 * su propia pantalla, con su propio permiso.
 *
 * Separar las dos cosas es lo que permite que recepción agende sin abrir la
 * historia de la clienta. Ver docs/03.
 *
 * `nombreCompleto()` junta nombre y apellido porque en la agenda cabe el
 * nombre entero y en el desglose de comisión cabe en una línea angosta.
 */
class Clienta extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'clientas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'apellido',
        'rut',
        'telefono',
        'correo',
        'fecha_nacimiento',
        'notas',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<AntecedenteSalud, $this> */
    public function antecedentes(): HasMany
    {
        return $this->hasMany(AntecedenteSalud::class, 'clienta_id');
    }

    /** @return HasMany<Cita, $this> */
    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'clienta_id');
    }

    /** @return HasMany<Venta, $this> */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'clienta_id');
    }

    /**
     * Antecedentes activos, que son los que avisan en la agenda.
     *
     * @return HasMany<AntecedenteSalud, $this>
     */
    public function antecedentesVigentes(): HasMany
    {
        return $this->antecedentes()->where('activo', true);
    }

    /** Nombre y apellido juntos, o solo el nombre si no hay apellido. */
    public function nombreCompleto(): string
    {
        $apellido = trim((string) $this->apellido);

        return $apellido === ''
            ? (string) $this->nombre
            : trim($this->nombre . ' ' . $apellido);
    }
}