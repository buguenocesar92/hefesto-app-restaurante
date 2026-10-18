import { canjearCodigo, verificarIdToken } from '../../../utils/authentik';
import { useSessionSesion } from '../../../utils/sesion';

/**
 * Retorno de Authentik tras el inicio de sesion.
 *
 * ORDEN DE LAS COMPROBACIONES
 * ---------------------------
 *  1. Viene `code` y `state`. Si falta alguno, no hay nada que canjear.
 *  2. El `state` coincide con el que se guardo. Es lo que impide que un sitio
 *     tercero inyecte una sesion con su propia cuenta.
 *  3. Se canjea el codigo por tokens, enviando el verifier de PKCE.
 *  4. Se VERIFICA LA FIRMA del id_token contra la llave publica de Authentik.
 *     Este paso no es opcional: sin el, cualquiera que knows `client_id` puede
 *     inventar un id_token de administrador.
 *  5. Solo entonces se guarda la sesion.
 */
export default defineEventHandler(async (event) => {
  const consulta = getQuery(event);
  const codigo = String(consulta.code || '');
  const estado = String(consulta.state || '');
  const estadoEsperado = getCookie(event, 'restaurante_oauth_state') || '';

  if (!codigo || !estado) {
    throw createError({ statusCode: 400, statusMessage: 'El proveedor no devolvio un codigo valido.' });
  }

  if (!estadoEsperado || estado !== estadoEsperado) {
    throw createError({
      statusCode: 400,
      statusMessage: 'El estado de la sesion no coincide. Se descarta el intento de inicio de sesion.',
    });
  }

  const sesion = await useSessionSesion(event);
  const verifier = sesion.data.pkceVerifier;

  if (!verifier) {
    throw createError({
      statusCode: 400,
      statusMessage: 'No hay verificador PKCE en la sesion. Vuelve a intentar el inicio de sesion.',
    });
  }

  const tokens = await canjearCodigo(codigo, verifier);
  const persona = await verificarIdToken(tokens.id_token);

  await sesion.update({
    accessToken: tokens.access_token,
    csrfToken: generarTokenCsrf(),
    nombre: persona.nombre,
    correo: persona.correo,
    grupos: persona.grupos,
  });

  // El estado ya se uso: se borra para que no sirva para un segundo intento.
  deleteCookie(event, 'restaurante_oauth_state', { path: '/' });

  return sendRedirect(event, '/');
});