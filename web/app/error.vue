<template>
  <NuxtErrorBoundary>
    <div>
      <h1>{{ error?.statusCode || 500 }}</h1>
      <p>{{ mensaje }}</p>
      <NuxtLink to="/">Volver al salón</NuxtLink>
    </div>
  </NuxtErrorBoundary>
</template>

<script setup lang="ts">
import type { NuxtError } from '#app';

const props = defineProps<{ error: NuxtError }>();

// El mensaje del servidor se muestra tal cual cuando viene: esta aplicacion
// escribe sus errores en espanol y explican que hacer. Un error generico tipo
// "Something went wrong" no le sirve de nada a un mesero a las nueve de la noche.
const mensaje = computed(() => {
  const delServidor = (props.error?.data as { mensaje?: string })?.mensaje;

  return delServidor || props.error?.statusMessage || 'Ocurrió un error inesperado.';
});
</script>

<style scoped>
div { font-family: system-ui, sans-serif; padding: 2rem; }
h1 { font-size: 3rem; margin: 0; }
</style>