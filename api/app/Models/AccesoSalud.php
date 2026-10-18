<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acceso a los antecedentes de salud: quién abrió qué y con qué resultado.
 *
 * EL DENEGADO TAMBIÉN SE ANOTA
 * ---------------------------
 * Un intento que falla es información, y en un salón con datos sensibles es la
 * más importante: muestra que alguien intentó abrir algo que no podía.
 */
class AccesoSalud extends Model
{
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'accesos_salud';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'clienta_id',
        'usuario_id',
        'resultado',
        'accion',
        'ip_address',
        'ruta',
        'detalle',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'creado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clienta, $this> */
    public function clienta(): BelongsTo
    {
        return $this->belongsTo(Clienta::class, 'clienta_id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}