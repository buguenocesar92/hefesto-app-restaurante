<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Zona del local: terraza, salon principal, barra. Solo ordena el mapa. */
class Zona extends ModeloRestaurante
{
    protected $table = 'zonas';

    protected $fillable = ['nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function mesas(): HasMany
    {
        return $this->hasMany(Mesa::class);
    }
}