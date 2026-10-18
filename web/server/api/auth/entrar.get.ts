import { generarPkce, generarTokenCsrf } from '../../utils/sesion';
import { urlDeAutorizacion } from '../../utils/authentik';

/**
 * Inicio de sesion contra Authentik.
 *
 * El flujo es authorization code con PKCE:
 *  1. Se genera un `verifier` aleatorio y su `challenge` (SHA-256).
 *  2. Se guarda el verifier en la sesion del servidor y se redirige al
 *     proveedor con el challenge.
 *  3. El proveedor devuelve a `/api/auth/callback` con un codigo.
 *  4. Alli se canjea el codigo enviando el verifier: si alguien robo el codigo,
 *     no puede canjearlo sin el verifier que nunca salio del servidor.
 *
 * El `state` es un valor aleatorio que vuelve en el retorno y se compara. Sin
 * el, un sitio tercero podria iniciar sesion con la cuenta de la victima.
 */
export default defineEventHandler(async (event) => {
  const { verifier, challenge } = generarPkce();
  const estado = generarTokenCsrf();

  const sesion = await useSessionSesion(event);

  await sesion.update({
    pkceVerifier: verifier,
  });

  const destino = await urlDeAutorizacion(challenge, estado);

  // El estado tambien va en la sesion, para comparar al volver.
  setCookie(event, 'restaurante_oauth_state', estado, {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: '/',
    maxAge: 600,
  });

  return sendRedirect(event, destino);
});