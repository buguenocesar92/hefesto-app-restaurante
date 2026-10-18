import { useSessionSesion } from '../../utils/sesion';

/** Cierre de sesion: se borra la sesion del servidor y se vuelve al inicio. */
export default defineEventHandler(async (event) => {
  const sesion = await useSessionSesion(event);

  await sesion.clear();

  deleteCookie(event, 'restaurante_oauth_state', { path: '/' });

  return sendRedirect(event, '/api/auth/entrar');
});