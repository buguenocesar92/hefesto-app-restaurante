<template>
  <div class="pantalla">
    <header class="cabecera">
      <div>
        <NuxtLink to="/">← Volver al salón</NuxtLink>
        <h1 v-if="comanda">{{ comanda.etiqueta }}</h1>
      </div>
      <p v-if="comanda" class="estado" :class="`estado-${comanda.estado}`">
        {{ comanda.estado }}
      </p>
    </header>

    <main v-if="comanda">
      <p v-if="error" class="error">{{ error }}</p>

      <section class="datos">
        <span>{{ comanda.comensales }} comensales</span>
        <span v-if="comanda.combinacion" class="combinacion">
          Mesas combinadas: {{ comanda.mesas.map(m => m.nombre).join(' + ') }}
        </span>
        <span v-if="comanda.notas">{{ comanda.notas }}</span>
      </section>

      <section class="lista">
        <h2>Pedidos</h2>

        <p v-if="comanda.items.length === 0" class="vacio">
          Todavía no hay pedidos en esta comanda.
        </p>

        <article
          v-for="item in comanda.items"
          :key="item.id"
          class="item"
          :class="`estado-${item.estado}`"
        >
          <div class="fila">
            <span class="cantidad">{{ item.cantidad }}</span>
            <div class="nombre">
              <strong>{{ item.nombre }}</strong>
              <ul v-if="item.modificadores.length" class="modificadores">
                <li v-for="mod in item.modificadores" :key="mod.id">
                  {{ mod.nombre }}
                  <small v-if="mod.precio">+ ${{ mod.precio }}</small>
                </li>
              </ul>
              <p v-if="item.notas_preparacion" class="nota">
                {{ item.notas_preparacion }}
              </p>
              <p v-if="item.motivo_anulacion" class="anulado">
                Anulado: {{ item.motivo_anulacion }}
              </p>
            </div>
            <span class="estado-item">{{ item.estado_label }}</span>
            <span class="precio">${{ item.subtotal }}</span>
          </div>

          <!--
            El boton de anular aparece solo si el estado lo permite. Ocultarlo
            no es la proteccion: la proteccion esta en el servidor, en la
            Policy y en la maquina de estados. Esto es solo para que el mesero
            no intente lo que el sistema le va a rechazar.
          -->
          <button
            v-if="puedeAnular(item)"
            class="anular"
            @click="anular(item)"
          >
            Anular
          </button>
        </article>
      </section>

      <footer class="acciones">
        <NuxtLink v-if="comanda.admite_items" to="/admin/productos" class="boton">
          Agregar productos
        </NuxtLink>

        <button
          v-if="comanda.estado === 'abierta'"
          class="boton"
          @click="pedirCuenta"
        >
          Pedir la cuenta
        </button>

        <button
          v-if="comanda.estado === 'por_cobrar'"
          class="boton"
          @click="reabrir"
        >
          Reabrir comanda
        </button>

        <button
          v-if="comanda.estado === 'cobrada'"
          class="boton informativo"
          disabled
        >
          Cobrada: la reversión se hace en el punto de venta
        </button>
      </footer>
    </main>

    <p v-else>Cargando comanda…</p>
  </div>
</template>

<script setup lang="ts">
/**
 * Comanda de una mesa.
 *
 * La pantalla muestra el estado de cada pedido por separado, y no un estado
 * unico de la comanda, porque eso es lo que cocina necesita: un plato que ya
 * salio no es lo mismo que uno que sigue en preparacion, y el comensal espera
 * por el segundo.
 */
const route = useRoute();
const { comanda: cargarComanda, pedir } = useApi();

const comanda = ref<Comanda | null>(null);
const error = ref('');

const id = Number(route.params.id);

async function cargar() {
  error.value = '';

  try {
    const respuesta = await cargarComanda(id);
    comanda.value = respuesta.data;
  } catch (e) {
    error.value = (e as ErrorDeApi).mensaje;
  }
}

function puedeAnular(item: ItemComanda): boolean {
  if (!comanda.value?.admite_anulacion) return false;

  return !['entregado', 'anulado', 'listo'].includes(item.estado);
}

async function anular(item: ItemComanda) {
  const motivo = window.prompt('Motivo de la anulación:');

  if (!motivo) return;

  error.value = '';

  try {
    await pedir(`/comandas/${id}/items/${item.id}/anular`, {
      method: 'POST',
      body: { motivo },
    });

    await cargar();
  } catch (e) {
    error.value = (e as ErrorDeApi).mensaje;
  }
}

async function pedirCuenta() {
  error.value = '';

  try {
    await pedir(`/comandas/${id}/pedir-cuenta`, { method: 'POST' });
    await cargar();
  } catch (e) {
    error.value = (e as ErrorDeApi).mensaje;
  }
}

async function reabrir() {
  error.value = '';

  try {
    await pedir(`/comandas/${id}/reabrir`, { method: 'POST' });
    await cargar();
  } catch (e) {
    error.value = (e as ErrorDeApi).mensaje;
  }
}

await cargar();
</script>

<style scoped>
.pantalla { font-family: system-ui, sans-serif; }
.cabecera {
  display: flex; justify-content: space-between;
  padding: 1rem; border-bottom: 2px solid #333;
}
.item { border-bottom: 1px solid #ddd; padding: 0.6rem 0; }
.item.estado-anulado { opacity: 0.55; }
.fila { display: flex; gap: 0.75rem; align-items: flex-start; }
.cantidad { font-weight: 700; font-size: 1.2rem; min-width: 1.5rem; }
.nombre { flex: 1; }
.modificadores { margin: 0.25rem 0; padding-left: 1.1rem; color: #555; }
.nota { color: #a60; font-style: italic; margin: 0.2rem 0; }
.anulado { color: #c93; margin: 0.2rem 0; }
.estado-item { color: #777; font-size: 0.85rem; }
.precio { min-width: 5rem; text-align: right; }
.anular { margin-top: 0.4rem; }
.datos { display: flex; gap: 1rem; padding: 0.75rem 1rem; color: #555; }
.combinacion { color: #56c; }
.acciones { display: flex; gap: 0.75rem; padding: 1rem; }
.boton {
  padding: 0.6rem 1rem; cursor: pointer; text-decoration: none;
  border: 1px solid #333; background: #fff; color: #333;
}
.informativo { background: #eee; color: #666; }
.error { color: #c93; padding: 0 1rem; }
.vacio { color: #999; }
</style>