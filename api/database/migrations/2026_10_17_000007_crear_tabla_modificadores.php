<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredientes, recetas y modificadores.
 *
 * ESTA MIGRACION RESPONDE A "SIN CEBOLLA, EXTRA QUESO": ESO NO ES UN TEXTO.
 *
 * Un texto libre con "sin cebolla" guardado en un campo de notas no sirve
 * para nada: no se puede cobrar el extra queso, no se puede saber cuantas
 * porciones de carne se pidio, y no se puede consultar que platos se pueden
 * preparar sin acetaminopheno. El modificador es un registro con precio,
 * con tipo y con ingrediente asociado.
 *
 * El modelo tiene cuatro piezas:
 *
 * 1. `ingredientes`: el inventario de lo que se usa. Lleva el marcado de
 *    alergenos, que en Chile es una obligation legal de informacion (Ley
 *    20.606). No se implementa la rotulacionNutricional: solo queda el dato.
 *
 * 2. `receta_ingredientes`: que lleva cada producto. Sirve para validar que
 *    "sin cebolla" tenga sentido para ese plato. Si las cebolla no esta en la
 *    receta, quitar la cebolla no es una modificacion valida y el sistema lo
 *    rechaza en vez de aceptarlo en silencio.
 *
 * 3. `grupos_modificadores`: las cajas donde el comensal elige. Distinguen
 *    entre eleccion unica (termino de la carne: al punto o cocido) y multiple
 *    (preferencias: sin cebolla, sin tomate).
 *
 * 4. `modificadores`: las opciones, con precio y tipo.
 *
 * Tipos de modificador:
 *   - agregado: suma precio ("extra queso", 1.500)
 *   - sin_costo: no cambia el precio ("sin hielo")
 *   - remocion: quita un ingrediente que esta en la receta ("sin cebolla")
 *   - cambio: cambia por otro ("vino tinto en vez de blanco")
 *
 * Por que el precio se COPIA a `comanda_item_modificadores` y no se lee del
 * catalogo al cobrar: el dia que suben el precio del extra queso, las comandas
 * de ayer tienen que seguir cuadrando con lo que se cobro ayer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredientes', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->string('nombre', 100);
            $tabla->string('unidad', 24)->default('gramo');

            // Marcado de alergenos segun la Ley 20.606. Se guardan como
            // lista de texto porque la ley no define una tabla cerrada que
            // podamos copiar sin verificarla.
            $tabla->jsonb('alergenos')->default('[]');

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->unique(['tenant_id', 'nombre']);
        });

        Schema::create('receta_ingredientes', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $tabla->foreignId('ingrediente_id')->constrained('ingredientes')->restrictOnDelete();

            $tabla->decimal('cantidad', 10, 3);
            $tabla->string('unidad', 24)->default('gramo');
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->unique(['producto_id', 'ingrediente_id']);
            $tabla->index(['tenant_id', 'ingrediente_id']);
        });

        Schema::create('grupos_modificadores', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->string('nombre', 100);

            // unica: el comensal elige una sola opcion.
            // multiple: puede marcar varias.
            $tabla->string('seleccion', 16)->default('multiple');

            $tabla->boolean('obligatorio')->default(false);
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'orden']);
        });

        Schema::create('modificadores', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('grupos_modificadores_id')->constrained('grupos_modificadores')->cascadeOnDelete();
            $tabla->string('nombre', 100);

            // agregado | sin_costo | remocion | cambio
            $tabla->string('tipo', 24)->default('agregado');

            $tabla->integer('precio')->default(0);

            // Para tipo remocion: que ingrediente se quita. Para tipo cambio:
            // por que se cambia. Nulo en agregado y sin_costo.
            $tabla->foreignId('ingrediente_id')->nullable()->constrained('ingredientes')->nullOnDelete();

            $tabla->unsignedInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'grupos_modificadores_id']);
        });

        Schema::create('producto_grupos_modificadores', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $tabla->foreignId('grupos_modificadores_id')->constrained('grupos_modificadores')->cascadeOnDelete();

            // Permite que un grupo sea obligatorio para un producto y opcional
            // para otro, sin duplicar el grupo.
            $tabla->boolean('obligatorio')->nullable();
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->unique(['producto_id', 'grupos_modificadores_id']);
            $tabla->index(['tenant_id', 'grupos_modificadores_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_grupos_modificadores');
        Schema::dropIfExists('modificadores');
        Schema::dropIfExists('grupos_modificadores');
        Schema::dropIfExists('receta_ingredientes');
        Schema::dropIfExists('ingredientes');
    }
};