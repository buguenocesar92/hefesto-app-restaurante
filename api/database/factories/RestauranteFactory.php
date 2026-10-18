<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fábrica de restaurantes.
 *
 * Existe sobre todo para las pruebas de aislamiento: una segunda PyME se crea
 * aca en dos lineas y el cruce se prueba con identificadores reales, no con
 * valores inventados en la prueba.
 *
 * @extends Factory<Restaurante>
 */
class RestauranteFactory extends Factory
{
    protected $model = Restaurante::class;

    public function definition(): array
    {
        $nombre = $this->faker->company() . ' ' . $this->faker->randomElement(['del Puerto', 'La Esquina', 'del Barrio']);

        return [
            'id' => (string) Str::uuid(),
            'nombre' => $nombre,
            'slug' => Str::slug($nombre) . '-' . Str::lower(Str::random(5)),
            'zona_geografica' => $this->faker->randomElement(['Santiago', 'Valparaiso', 'Concepcion', 'Temuco']),
            'correo_contacto' => $this->faker->unique()->safeEmail(),
            'activo' => true,
            'timezone' => 'America/Santiago',
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}