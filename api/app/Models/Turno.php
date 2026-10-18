<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ventana horaria en la que el local opera.
 *
 * `contiene($momento)` es la unica fuente de verdad de "estamos dentro del
 * turno", y resuelve el caso que hace inutil un modelo con hora_inicio y
 * hora_fin: el turno que cruza la medianoche.
 */
class Turno extends ModeloRestaurante
{
    protected $table = 'turnos';

    protected $fillable = [
        'nombre',
        'hora_inicio',
        'hora_fin',
        'cruza_medianoche',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'hora_inicio' => 'string',
            'hora_fin' => 'string',
            'cruza_medianoche' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'turno_asignaciones');
    }

    public function comandas(): HasMany
    {
        return $this->hasMany(Comanda::class, 'abierta_en_turno_id');
    }

    /**
     * Si el instante cae dentro de este turno.
     *
     * Para un turno que cruza la medianoche (20:00 a 03:00), un martes a la
     * 01:00 pertenece a la noche del lunes. La comparacion simple
     * `hora >= inicio && hora <= fin` lo declararia fuera de turno y dejaria
     * al mesero de madrugada sin poder cobrar, que es el peor momento posible
     * para fallar.
     */
    public function contiene(CarbonImmutable $momento): bool
    {
        if (! $this->activo) {
            return false;
        }

        $hora = $momento->format('H:i:s');

        if ($this->cruza_medianoche) {
            return $hora >= $this->hora_inicio || $hora <= $this->hora_fin;
        }

        return $hora >= $this->hora_inicio && $hora <= $this->hora_fin;
    }
}