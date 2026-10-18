<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rol;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persona que trabaja en el restaurante.
 *
 * La identidad viene de Authentik: esta tabla no tiene contrasena y nunca se
 * crea un usuario aqui sin que exista antes en el proveedor.
 */
class Usuario extends ModeloRestaurante
{
    protected $fillable = [
        'authentik_id',
        'nombre',
        'apellido',
        'correo',
        'rol',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'rol' => Rol::class,
            'activo' => 'boolean',
        ];
    }

    public function turnos(): BelongsToMany
    {
        return $this->belongsToMany(Turno::class, 'turno_asignaciones');
    }

    public function comandasAbiertas(): HasMany
    {
        return $this->hasMany(Comanda::class, 'abierta_por_usuario_id');
    }

    public function es(Rol ...$roles): bool
    {
        return in_array($this->rol, $roles, true);
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombre . ' ' . ($this->apellido ?? ''));
    }
}