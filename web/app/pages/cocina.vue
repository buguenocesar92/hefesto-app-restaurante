<template>
  <div class="pantalla">
    <header class="cabecera">
      <strong>Cocina</strong>
      <span v-if="estacionActual" class="estacion">{{ estacionActual.nombre }}</span>
      <button @click="cargar">Actualizar</button>
    </header>

    <main>
      <p v-if="error" class="error">{{ error }}</p>

      <!--
        Orden por antiguedad, no por orden de llegada. La API ya devuelve los
        pedidos ordenados asi usando el reloj de Redis; esta pantalla no ordena
        nada, solo los muestra.
      -->
      <div class="columnas">
        <section v-for="panel in paneles" :key="panel.estacion.id" class="estacion-columna">
          <h2>
            {{ panel.estacion.nombre }}
            <small>
              objetivo {{ Math.round(panel.estacion.objetivo_segundos / 60) }} min
            </small>
          </h2>

          <p v-if="panel.pedidos.length === 0" class="vacio">
            Sin pedidos.
          </p>

          <article
            v-for="pedido in panel.pedidos"
            :key="pedido.item_id"
            class="pedido"
            :class="{ atrasado: pedido.atrasado, listo: pedido.estado === 'listo' }"
          >
            <p class="tiempo">
              {{ formatearTiempo(pedido.segundos) }}
              <span v-if="pedido.atrasado" class="alerta">atrasado</span>
              <span v-if="pedido.estado === 'listo'" class="listo">listo, esperando</span>
            </p>

            <p class="detalle">
              {{ pedido.cantidad || 1 }} x {{ pedido.nombre }}
            </p>

            <ul v-if="pedido.modificadores.length" class="modificadores">
              <li v-for="mod in pedido.modificadores" :key="mod">+ {{ mod }}</li>
            </ul>

            <p v-if="pedido.notas" class="notas">{{ pedido.notas }}</p>

            <p class="mesa">Comanda {{ pedido.comanda_id }} · {{ pedido.comensales }} comensales</p>

            <div class="acciones">
              <button
                v-if="pedido.estado === 'enviado'"
                @click="cambiar(pedido.item_id, 'en_preparacion')"
              >
                Tomar
              </button>
              <button
                v-if="pedido.estado !== 'listo'"
                @click="cambiar(pedido.item_id, 'listo')"
              >
                Listo
              </button>
              <button
                v-if="pedido.estado === 'listo'"
                @click="cambiar(pedido.item_id, 'entregado')"
              >
                Entregado
              </button>
            </div>
          </article>
        </section>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
/**
 * Pantalla de cocina.
 *
 * EL TIEMPO NO SE CALCULA AQUI.
 *
 * Cada pedido trae `segundos` ya calculado por la API contra el reloj de Redis.
 * Recalcularlo en el navegador con `Date.now()` menos una marca seria copiar la
 * logica del servidor y abrir la posibilidad de que las dos digan cosas
 * distintas: el telefono del cocinero puede tener la hora corrida.
 *
 * Esta pantalla sondea la API cada 3 segundos. Lo que se busca es que el plato
 * mas viejo quede arriba y que un plato listo que nadie reclama siga contando
 * tiempo, que es el problema de gestion que motiva todo el sistema.
 */
const { panelCocina, pedir } = useApi();
const config = useRuntimeConfig();

const paneles = ref<PanelEstacion[]>([]);
const error = ref('');
const estacionActual = computed(() => paneles.value[0]?.estacion || null);

async function cargar() {
  error.value = '';

  try {
    const respuesta = await panelCocina();
    paneles.value = respuesta.estaciones;
  } catch (e) {
    error.value = (e as ErrorDeApi).mensaje;
  }
}

async function cambiar(itemId: number, estado: string) {
  error.value = '';

  try {
    await pedir(`/cocina/items/${itemId}/estado`, {
      method: 'POST',
      body: { estado },
    });

    await cargar();
  } catch (e) {
    // El mensaje de la API se muestra tal cual. Si alguien intenta una
    // transicion prohibida, la explicacion de por que esta prohibida es mas
    // util que un error generico.
    error.value = (e as ErrorDeApi).mensaje;
  }
}

function formatearTiempo(segundos: number): string {
  const minutos = Math.floor(segundos / 60);
  const resto = segundos % 60;

  return `${minutos}:${String(resto).padStart(2, '0')}`;
}

let temporizador: ReturnType<typeof setInterval> | undefined;

onMounted(async () => {
  await cargar();

  temporizador = setInterval(cargar, Number(config.public.intervaloCocina) || 3000);
});

onUnmounted(() => {
  if (temporizador) clearInterval(temporizador);
});
</script>

<style scoped>
.pantalla { font-family: system-ui, sans-serif; }
.cabecera {
  display: flex; gap: 1rem; align-items: center;
  padding: 0.75rem 1rem; background: #1a1a1a; color: #fff;
}
.estacion { color: #bbb; font-weight: normal; }
.columnas { display: flex; gap: 1rem; padding: 1rem; overflow-x: auto; }
.estacion-columna { min-width: 300px; flex: 1; }
.pedido {
  border: 2px solid #333; border-radius: 8px;
  padding: 0.75rem; margin-bottom: 0.75rem; background: #fff;
}
.pedido.atrasado { border-color: #c93; background: #fff5f5; }
.pedido.listo { border-color: #2a7; background: #f2fbf5; }
.tiempo { font-size: 1.6rem; font-weight: 700; margin: 0; }
.alerta { color: #c93; font-size: 0.9rem; text-transform: uppercase; }
.listo { color: #2a7; font-size: 0.9rem; text-transform: uppercase; }
.detalle { font-size: 1.1rem; margin: 0.35rem 0; }
.modificadores { margin: 0.25rem 0; padding-left: 1.2rem; color: #555; }
.notas { color: #a60; font-style: italic; }
.mesa { color: #777; font-size: 0.85rem; }
.acciones { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
.acciones button {
  padding: 0.5rem 1rem; font-size: 1rem; cursor: pointer;
}
.vacio { color: #999; }
.error { color: #c93; padding: 0 1rem; }
</style>