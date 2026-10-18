<template>
  <div class="pantalla">
    <header class="cabecera">
      <div>
        <strong>Salón</strong>
        <span v-if="persona" class="persona">{{ persona }}</span>
      </div>
      <nav>
        <NuxtLink to="/cocina">Cocina</NuxtLink>
        <NuxtLink to="/admin/productos">Administración</NuxtLink>
        <a href="/api/auth/salir">Salir</a>
      </nav>
    </header>

    <main class="contenido">
      <p v-if="cargando">Cargando el salón…</p>
      <p v-else-if="error" class="error">{{ error }}</p>

      <section v-for="zona in zonas" :key="zona.id" class="zona">
        <h2>{{ zona.nombre }}</h2>

        <div class="mesas">
          <article
            v-for="mesa in zona.mesas"
            :key="mesa.id"
            class="mesa"
            :class="`estado-${mesa.estado}`"
          >
            <h3>Mesa {{ mesa.nombre }}</h3>
            <p class="capacidad">{{ mesa.capacidad }} lugares</p>

            <!--
              La mesa combinada se dibuja como una sola, con la comanda unica.
              Es la consecuencia de que la comanda cuelgue del grupo y no de la
              mesa: el salon no tiene que juntar dos tarjetas.
            -->
            <p v-if="mesa.estado === 'libre'" class="estado-mesa">Libre</p>

            <div v-else-if="mesa.comanda" class="comanda">
              <p>
                Comanda {{ mesa.comanda.folio }}
                <span v-if="mesa.comanda.estado === 'por_cobrar'" class="alerta">
                  por cobrar
                </span>
              </p>
              <p>{{ mesa.comanda.comensales }} comensales</p>
              <p class="detalle">
                <span v-if="mesa.comanda.pendientes">
                  {{ mesa.comanda.pendientes }} por enviar
                </span>
                <span v-if="mesa.comanda.en_cocina">
                  {{ mesa.comanda.en_cocina }} en cocina
                </span>
              </p>
              <NuxtLink :to="`/comandas/${mesa.comanda.id}`" class="abrir">
                Abrir comanda
              </NuxtLink>
            </div>
          </article>
        </div>
      </section>
    </main>
  </div>
</template>

<script setup lang="ts">
/**
 * Mapa del salon.
 *
 * Es la pantalla que el mesero tiene abierta todo el turno, asi que esta
 * pensada para recargarse seguido. Solo trae zonas, mesas y el resumen de la
 * comanda de cada una: nada mas. El detalle vive en la pantalla de comanda.
 */
const { salon } = useApi();
const { data: sesion } = await useFetch('/api/sesion', { server: true });

const zonas = ref<ZonaSalon[]>([]);
const cargando = ref(true);
const error = ref('');
const persona = computed(() => sesion.value?.nombre || '');

async function cargar() {
  cargando.value = true;
  error.value = '';

  try {
    const respuesta = await salon();
    zonas.value = respuesta.zonas;
  } catch (e) {
    error.value = (e as ErrorDeApi).mensaje;
  } finally {
    cargando.value = false;
  }
}

onMounted(cargar);

// El salon cambia todo el tiempo: cada 10 segundos basta para que el mesero
// vea que una mesa se libero sin que tenga que recargar a mano.
let temporizador: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
  temporizador = setInterval(cargar, 10000);
});

onUnmounted(() => {
  if (temporizador) clearInterval(temporizador);
});
</script>

<style scoped>
.pantalla { font-family: system-ui, sans-serif; }
.cabecera {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.75rem 1rem; border-bottom: 2px solid #333;
}
.persona { margin-left: 0.75rem; color: #666; font-weight: normal; }
nav a { margin-left: 1rem; }
.contenido { padding: 1rem; }
.zona { margin-bottom: 2rem; }
.mesas { display: flex; flex-wrap: wrap; gap: 0.75rem; }
.mesa {
  border: 2px solid #999; border-radius: 8px; padding: 0.75rem;
  min-width: 180px;
}
.estado-libre { background: #f2f7f2; border-color: #4a7; }
.estado-ocupada { background: #fff8e6; border-color: #c93; }
.estado-por_cerrar { background: #eef2ff; border-color: #56c; }
.capacidad, .detalle { color: #555; font-size: 0.85rem; margin: 0.25rem 0; }
.alerta { color: #c93; font-weight: 700; }
.abrir { display: inline-block; margin-top: 0.5rem; }
.error { color: #c93; }
</style>