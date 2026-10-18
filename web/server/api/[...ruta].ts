/**
 * Proxy del BFF hacia la API de Laravel.
 *
 * ESTA RUTA ES LA BARRA DE LA API PARA EL NAVEGADOR
 * -------------------------------------------------
 * El navegador llama a `/api/...` en este mismo servidor, y esta ruta reenvia
 * la peticion a Laravel. Tres cosas pasan aqui y ninguna puede olvidarse:
 *
 *  1. La COOKIE DE SESION se reenvia. Sin ella, la API ve una peticion
 *     anonima y responde 401 aunque el usuario haya iniciado sesion.
 *  2. El TOKEN CSRF viaja como cabecera `X-XSRF-TOKEN`. Laravel lo espera con
 *     ese nombre en las escrituras. Sin reenviarlo, cualquier POST, PUT, PATCH
 *     o DELETE falla con 419.
 *  3. `redirect: 'manual'`: el backend no debe seguir redirecciones. Si una
 *     respuesta 3xx llegara al navegador, el CORS y las cookies se rompen de
 *     formas que son muy caras de diagnosticar.
 *
 * La API nunca se expone publicamente. Ver deploy/docker-compose.prod.yml.
 */
export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event);
  const metodo = event.method.toUpperCase();

  // Rutas que no van a la API: el inicio y fin de sesion los resuelve este
  // servidor contra Authentik, no Laravel.
  const ruta = getRequestURL(event).pathname.replace(/^\/api/, '');

  if (ruta.startsWith('/auth')) {
    throw createError({ statusCode: 404, statusMessage: 'Ruta no encontrada' });
  }

  const esEscritura = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(metodo);

  const cabeceras: Record<string, string> = {
    Accept: 'application/json',
  };

  // El token CSRF se guarda en la sesion del servidor y se reenvia aqui. El
  // navegador nunca lo ve: por eso un script de otra pagina no puede leerlo.
  const sesion = await useSessionSesion(event);

  if (sesion.data.csrfToken) {
    cabeceras['X-XSRF-TOKEN'] = sesion.data.csrfToken;
  }

  const cookie = getCookie(event, 'restaurante_sesion');

  if (cookie) {
    cabeceras.Cookie = `restaurante_sesion=${cookie}`;
  }

  const cuerpo = esEscritura
    ? JSON.stringify(await readBody(event).catch(() => ({})))
    : undefined;

  if (cuerpo !== undefined) {
    cabeceras['Content-Type'] = 'application/json';
  }

  try {
    return await $fetch(`${config.apiBase}/api${ruta}`, {
      method: metodo,
      headers: cabeceras,
      body: cuerpo,
      redirect: 'manual',
    });
  } catch (error: unknown) {
    const err = error as { statusCode?: number; statusMessage?: string; data?: { mensaje?: string } };

    // El mensaje que devuelve la API esta escrito para la persona que lo ve.
    // Se reenvia tal cual en vez de reemplazarlo por un error generico.
    throw createError({
      statusCode: err.statusCode || 500,
      statusMessage: err.data?.mensaje || 'Ocurrio un error en el sistema.',
    });
  }
});