<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Base de todos los modelos que pertenecen a un restaurante.
 *
 * No aplica un filtro global de tenant a proposito. El aislamiento ya lo hace
 * PostgreSQL con Row Level Security, y duplicarlo en PHP seria una segunda
 * barrera que puede fallar en silencio. La unica garantia que da esta clase es
 * que `tenant_id` se rellena al crear, para no escribir filas huerfanas.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md para el criterio completo.
 */
abstract class ModeloRestaurante extends Model
{
    /** La RLS exige que toda escritura traiga el tenant de la peticion. */
    protected static function booted(): void
    {
        static::creating(function (self $modela): void {
            if ($modela->tenant_id === null && app()->bound('tenant.actual')) {
                $modela->tenant_id = app('tenant.actual');
            }
        });
    }

    public function scopeDelRestaurante($consulta, string $restauranteId)
    {
        return $consulta->where('tenant_id', $restauranteId);
    }
}