<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Categoría del menú. Solo agrupa y ordena. */
class CategoriaProducto extends ModeloRestaurante
{
    protected $table = 'categorias_productos';

    protected $fillable = ['nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }
}