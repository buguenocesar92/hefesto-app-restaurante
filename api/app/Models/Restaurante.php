<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\UUID;

/**
 * Un restaurante. Es el tenant del sistema.
 *
 * No lleva RLS: se consulta en el inicio de sesion. Ver la migracion
 * 2026_10_17_000014 para la vista publica que compensa eso.
 */
class Restaurante extends ModeloRestaurante
{
    protected $table = 'restaurantes';

    protected $keyType = 'string';

    public $incrementing = false;

    use UUID;

    protected $fillable = [
        'nombre',
        'slug',
        'zona_geografica',
        'correo_contacto',
        'activo',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'tenant_id', 'id');
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(Turno::class, 'tenant_id', 'id');
    }

    public function zonas(): HasMany
    {
        return $this->hasMany(Zona::class, 'tenant_id', 'id');
    }

    public function mesas(): HasMany
    {
        return $this->hasMany(Mesa::class, 'tenant_id', 'id');
    }

    public function estaciones(): HasMany
    {
        return $this->hasMany(Estacion::class, 'tenant_id', 'id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'tenant_id', 'id');
    }

    public function comandas(): HasMany
    {
        return $this->hasMany(Comanda::class, 'tenant_id', 'id');
    }
}