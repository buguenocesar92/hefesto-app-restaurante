<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoEstacion;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estación de trabajo: cocina caliente, barra, postrería.
 *
 * Que exista esta tabla, y no solo un "tipo de plato", es lo que hace que los
 * tiempos en pantalla signifiquen algo. Ver la migracion 2026_10_17_000005.
 */
class Estacion extends ModeloRestaurante
{
    protected $table = 'estaciones';

    protected $fillable = [
        'nombre',
        'tipo',
        'tiempo_objetivo_segundos',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEstacion::class,
            'tiempo_objetivo_segundos' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComandaItem::class);
    }

    public function objetivoMinutos(): int
    {
        return (int) round($this->tiempo_objetivo_segundos / 60);
    }
}