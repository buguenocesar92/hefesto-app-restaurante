<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agrupamiento de mesas al que cuelga una comanda.
 *
 * UNA MESA SOLA ES UN GRUPO DE UN MIEMBRO. Una mesa combinada es un grupo de
 * dos o mas. La comanda siempre apunta aqui, nunca directamente a una mesa, y
 * por eso la combinacion no necesita ningun caso especial en el codigo.
 *
 * Ver la migracion 2026_10_17_000004 para el razonamiento completo.
 */
class GrupoMesas extends ModeloRestaurante
{
    protected $table = 'grupos_mesas';

    protected $fillable = ['nombre', 'es_combinacion'];

    protected function casts(): array
    {
        return [
            'es_combinacion' => 'boolean',
        ];
    }

    public function mesas(): BelongsToMany
    {
        return $this->belongsToMany(Mesa::class, 'grupo_mesas_mesas');
    }

    public function comandas(): HasMany
    {
        return $this->hasMany(Comanda::class);
    }

    public function comandaAbierta(): HasMany
    {
        return $this->hasMany(Comanda::class)
            ->whereIn('estado', ['abierta', 'por_cobrar'])
            ->orderByDesc('abierta_at');
    }

    /** La comanda viva del grupo, que es la que el salon ve. */
    public function comandaVigente(): ?Comanda
    {
        return $this->comandaAbierta()->first();
    }

    public function capacidad(): int
    {
        return (int) $this->mesas()->sum('capacidad');
    }
}