<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoComanda;
use App\Models\Comanda;
use App\Models\GrupoMesas;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Comanda> */
class ComandaFactory extends Factory
{
    protected $model = Comanda::class;

    public function definition(): array
    {
        return [
            'grupos_mesas_id' => GrupoMesas::factory(),
            'folio' => fake()->unique()->numberBetween(1, 100000),
            'estado' => EstadoComanda::Abierta->value,
            'comensales' => 2,
            'abierta_at' => now()->subMinutes(10),
        ];
    }

    public function abierta(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoComanda::Abierta->value]);
    }

    public function porCobrar(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoComanda::PorCobrar->value]);
    }

    /**
     * Comanda ya cobrada. Es el estado que dispara la regla que hay que
     * comprobar: nadie la anula desde este sistema.
     */
    public function cobrada(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoComanda::Cobrada->value,
            'cobrada_at' => now()->subMinutes(2),
            'cerrada_at' => now()->subMinutes(3),
        ]);
    }
}