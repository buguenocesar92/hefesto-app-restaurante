<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modificadores aplicados a un item de la comanda.
 *
 * Aqui se COPIA nombre, tipo, precio e ingrediente del catalogo. Es la unica
 * forma de que el historico sea fiel: si manana suben el extra queso de 1.500
 * a 1.800, la comanda de hoy tiene que seguir cuadrando con los 1.500 que se
 * cobraron, y el nombre tiene que seguir diciendo "Extra queso" aunque en el
 * catalogo ahora se llame "Extra queso mozzarella".
 *
 * La validacion de que el modificador tiene sentido para ese producto ocurre
 * en App\Services\Comandas\CalculadoraDePrecios, no aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comanda_item_modificadores', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('comanda_item_id')->constrained('comanda_items')->cascadeOnDelete();
            $tabla->foreignId('modificadores_id')->nullable()->constrained('modificadores')->nullOnDelete();

            // Copias del catalogo, para el historico.
            $tabla->string('nombre', 100);
            $tabla->string('tipo', 24);
            $tabla->integer('precio');

            $tabla->string('ingrediente', 100)->nullable();

            $tabla->unsignedInteger('orden')->default(0);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();

            // El mismo modificador no se aplica dos veces al mismo item.
            $tabla->unique(['comanda_item_id', 'modificadores_id']);
            $tabla->index(['tenant_id', 'comanda_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comanda_item_modificadores');
    }
};