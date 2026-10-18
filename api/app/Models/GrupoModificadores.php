<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Caja de modificadores donde el comensal elige.
 *
 * `seleccion` distingue lo que un menu corriente mezcla: "termino de la
 * carne" es de eleccion UNICA (al punto, tres cuartos, cocido) y
 * "preferencias" admite VARIAS (sin cebolla, sin tomate). Si el modelo no
 * distinguiera las dos cosas, el sistema no podria rechazar "al punto y
 * cocido" a la vez, que es un error de captura y no una preferencia.
 */
class GrupoModificadores extends ModeloRestaurante
{
    protected $table = 'grupos_modificadores';

    protected $fillable = ['nombre', 'seleccion', 'obligatorio', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function modificadores(): HasMany
    {
        return $this->hasMany(Modificador::class);
    }

    public function esDeEleccionUnica(): bool
    {
        return $this->seleccion === 'unica';
    }
}