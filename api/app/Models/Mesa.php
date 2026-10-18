<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoMesa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Mesa fisica del salon.
 *
 * `estado` esta desnormalizado: se actualiza dentro de la transaccion que
 * cambia la comanda del grupo. La fuente de verdad es la existencia de una
 * comanda abierta, pero el mapa del salon dibuja muchas mesas y no puede
 * pagar una consulta al grupo por cada una.
 */
class Mesa extends ModeloRestaurante
{
    protected $table = 'mesas';

    protected $fillable = [
        'zona_id',
        'nombre',
        'capacidad',
        'estado',
        'posicion_x',
        'posicion_y',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'capacidad' => 'integer',
            'estado' => EstadoMesa::class,
            'posicion_x' => 'integer',
            'posicion_y' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(GrupoMesas::class, 'grupo_mesas_mesas');
    }

    /** La comanda abierta de esta mesa, si la hay. Una mesa esta en un grupo. */
    public function comandaAbierta(): HasOne
    {
        return $this->hasOneThrough(
            Comanda::class,
            GrupoMesas::class,
            'id',
            'grupos_mesas_id',
            'id',
            'grupos_mesas_id'
        )->whereIn('estado', ['abierta', 'por_cobrar']);
    }

    public function estaLibre(): bool
    {
        return $this->estado === EstadoMesa::Libre;
    }
}