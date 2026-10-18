<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Rol;
use App\Models\Restaurante;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Usuario> */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Restaurante::factory(),
            'authentik_id' => (string) Str::uuid(),
            'nombre' => $this->faker->firstName(),
            'apellido' => $this->faker->lastName(),
            'correo' => $this->faker->unique()->safeEmail(),
            'rol' => Rol::Mesero->value,
            'activo' => true,
        ];
    }

    public function de(Restaurante $restaurante): static
    {
        return $this->state(fn (): array => ['tenant_id' => $restaurante->id]);
    }

    public function mesero(): static
    {
        return $this->state(fn (): array => ['rol' => Rol::Mesero->value]);
    }

    public function cajero(): static
    {
        return $this->state(fn (): array => ['rol' => Rol::Cajero->value]);
    }

    public function cocinero(): static
    {
        return $this->state(fn (): array => ['rol' => Rol::Cocinero->value]);
    }

    public function jefeCocina(): static
    {
        return $this->state(fn (): array => ['rol' => Rol::JefeCocina->value]);
    }

    public function administrador(): static
    {
        return $this->state(fn (): array => ['rol' => Rol::Administrador->value]);
    }
}