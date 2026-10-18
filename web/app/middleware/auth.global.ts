/**
 * Middleware de sesion del lado del navegador.
 *
 * Solo comprueba que HAY sesion. La autoridad es la API: si el token no sirve,
 * la API responde 401 y la pantalla lo muestra. Este middleware existe para no
 * mostrar una pantalla de cocina a alguien que no entro, y para poder mandar a
 * `/api/auth/entrar` sin esperar un error de red.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  // Las rutas de autenticacion son publicas por definicion.
  if (to.path.startsWith('/api/auth')) {
    return;
  }

  const { data } = await useFetch('/api/sesion', {
    server: true,
    headers: useRequestHeaders(['cookie']),
  });

  if (!data.value?.autenticado) {
    return navigateTo('/api/auth/entrar');
  }
});