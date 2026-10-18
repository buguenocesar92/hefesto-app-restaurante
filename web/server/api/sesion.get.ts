import { useSessionSesion } from '../utils/sesion';

/**
 * Estado de la sesion, para el middleware de navegacion.
 *
 * Devuelve lo minimo para pintar la interfaz. NO devuelve el token de acceso ni
 * el token CSRF: si se los entrega al navegador, un script de otra pagina los
 * puede leer y la proteccion CSRF deja de servir de algo.
 */
export default defineEventHandler(async (event) => {
  const sesion = await useSessionSesion(event);

  return {
    autenticado: Boolean(sesion.data.accessToken),
    nombre: sesion.data.nombre || null,
    correo: sesion.data.correo || null,
  };
});