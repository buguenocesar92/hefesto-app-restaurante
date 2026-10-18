<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoLinea;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto del menú.
 *
 * Tres cosas que este modelo distingue a proposito y que un POS de venta rapida
 * suele mezclar:
 *
 *  - `tipo_linea` (que se vende) y `estacion_id` (quien lo prepara). Son cosas
 *    distintas y el menu las separa.
 *  - `precio` en pesos enteros. Las cuentas se dividen en partes enteras, y
 *    con decimales el reparto produce tres pesos de diferencia que el mesero
 *    tiene que absorber de su bolsillo.
 *  - `tiempo_objetivo_segundos`: el umbral de "atrasado" de este plato
 *    cuando se sale del promedio de su estacion.
 */
class Producto extends ModeloRestaurante
{
    protected $table = 'productos';

    protected $fillable = [
        'categoria_productos_id',
        'codigo',
        'nombre',
        'descripcion',
        'precio',
        'unidad',
        'tipo_linea',
        'estacion_id',
        'tiempo_objetivo_segundos',
        'acepta_modificadores',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'integer',
            'tipo_linea' => TipoLinea::class,
            'tiempo_objetivo_segundos' => 'integer',
            'acepta_modificadores' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_productos_id');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /** Grupos de modificadores que el comensal puede elegir para este producto. */
    public function gruposModificadores(): HasMany
    {
        return $this->hasMany(ProductoGrupoModificadores::class);
    }

    /** Ingredientes que lleva, segun la receta. */
    public function ingredientes(): HasMany
    {
        return $this->hasMany(RecetaIngrediente::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComandaItem::class);
    }

    /**
     * Objetivo de tiempo del producto: el propio si esta informado, si no el
     * de la estacion. Se resuelve al crear el item y se copia a la fila, para
     * que cambiar el objetivo no reescriba el historico.
     */
    public function objetivoSegundos(): int
    {
        return $this->tiempo_objetivo_segundos
            ?? (int) ($this->estacion?->tiempo_objetivo_segundos ?? 900);
    }
}