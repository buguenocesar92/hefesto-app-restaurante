<template>
  <div class="pantalla">
    <h1>Productos</h1>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="cargando">Cargando el menú…</p>

    <section v-for="tipo in tipos" :key="tipo" class="tipo">
      <h2>{{ tipo }}</h2>

      <article v-for="producto en productosDe(tipo)" :key="producto.id" class="producto">
        <div class="encabezado">
          <strong>{{ producto.nombre }}</strong>
          <span>${{ producto.precio }}</span>
        </div>

        <p v-if="producto.descripcion" class="descripcion">{{ producto.descripcion }}</p>

        <p class="meta">
          {{ producto.estacion || 'sin estacion' }}
          · objetivo {{ Math.round(producto.tiempo_objetivo_segundos / 60) }} min
        </p>

        <!--
          Los grupos de modificadores se muestran con su tipo de eleccion para
          que el mesero sepa cuanto puede marcar: en un grupo de eleccion unica
          marcar dos es un error y el servidor lo rechaza.
        -->
        <div v-for="grupo in producto.grupos_modificadores" :key="grupo.grupo_id" class="grupo">
          <p class="grupo-nombre">
            {{ grupo.nombre }}
            <small>
              {{ grupo.seleccion === 'unica' ? 'una sola opcion' : 'varias' }}
              <span v-if="grupo.obligatorio" class="obligatorio">obligatorio</span>
            </small>
          </p>

          <ul>
            <li v-for="mod in grupo.modificadores" :key="mod.id">
              {{ mod.nombre }}
              <small>{{ tipoModificador(mod.tipo) }}<span v-if="mod.precio"> ${{ mod.precio }}</span></small>
            </li>
          </ul>
        </div>
      </article>
    </section>
  </div>
</template>

<script setup lang="ts">
/**
 * Catalogo de productos, con sus grupos de modificadores.
 *
 * Esta pantalla existe para que el mesero vea EXACTAMENTE lo que la cocina
 * recibe. Un modificador que aqui no aparece con su precio y su tipo es un
 * modificador que se pierde en el camino.
 */
const { pedir } = useApi();

interface ProductoMenu {
  id: number;
  nombre: string;
  descripcion: string | null;
  precio: number;
  tipo_linea: string;
  estacion: string | null;
  tiempo_objetivo_segundos: number;
  grupos_modificadores: {
    grupo_id: number;
    nombre: string;
    seleccion: string;
    obligatorio: boolean;
    modificadores: { id: number; nombre: string; tipo: string; precio: number }[];
  }[];
}

const productos = ref<ProductoMenu[]>([]);
const cargando = ref(true);
const error = ref('');

const tipos = ['comida', 'bebida', 'postre'];

function productosDe(tipo: string) {
  return productos.value.filter((p) => p.tipo_linea === tipo);
}

function tipoModificador(tipo: string): string {
  const nombres: Record<string, string> = {
    agregado: 'Agregado',
    sin_costo: 'Sin costo',
    remocion: 'Remoción',
    cambio: 'Cambio',
  };

  return nombres[tipo] || tipo;
}

onMounted(async () => {
  try {
    const respuesta = await pedir<{ data: ProductoMenu[] }>('/admin/productos');
    productos.value = respuesta.data;
  } catch (e) {
    error.value = (e as ErrorDeApi).mensaje;
  } finally {
    cargando.value = false;
  }
});
</script>

<style scoped>
.pantalla { font-family: system-ui, sans-serif; padding: 1rem; }
.producto { border-bottom: 1px solid #ddd; padding: 0.75rem 0; }
.encabezado { display: flex; justify-content: space-between; }
.descripcion, .meta { color: #666; font-size: 0.9rem; margin: 0.25rem 0; }
.grupo { margin: 0.5rem 0 0.5rem 1rem; }
.grupo-nombre { font-weight: 600; margin: 0.4rem 0 0.2rem; }
.grupo-nombre small { font-weight: normal; color: #666; }
.obligatorio { color: #c93; text-transform: uppercase; font-size: 0.75rem; }
.grupo ul { margin: 0; padding-left: 1.2rem; color: #444; }
.grupo li small { color: #888; }
.error { color: #c93; }
</style>