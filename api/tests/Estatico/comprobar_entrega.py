#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Verificacion estatica de la entrega de la aplicacion de restaurante.

POR QUE EXISTE ESTE script
--------------------------
En el entorno donde se escribio esta aplicacion no hay PHP, ni Composer, ni
Docker. Las pruebas de PHPUnit NO se pudieron ejecutar y no se presentan como
ejecutadas. Este script comprueba lo que SI se puede comprobar sin PHP:

  1. Que esten todos los archivos y directorios que el encargo declara.
  2. Que los JSON sean JSON valido (composer.json, package.json).
  3. Que todos los archivos sean UTF-8 valido, sin marcador de orden de bytes.
  4. Que NO queden caracteres CJK ni cirilicos en ningun archivo.
  5. Que las migraciones declaren RLS para toda tabla de negocio con
     `tenant_id`, y que `restaurantes` quede fuera a proposito.
  6. Que `.env.example` no traiga valores reales.

Lo que este script NO hace, y es importante decirlo: no ejecuta las pruebas de
Laravel, no valida el SQL contra PostgreSQL y no comprueba que la aplicacion
arranque. Un OK aqui significa "la entrega esta completa y limpia", no "el
software funciona".

Uso:
    python api/tests/Estatico/comprobar_entrega.py
"""

from __future__ import annotations

import json
import re
import sys
import unicodedata
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[3]

FALLOS: list[str] = []
ADVERTENCIAS: list[str] = []


def fallar(mensaje: str) -> None:
    FALLOS.append(mensaje)


def advertir(mensaje: str) -> None:
    ADVERTENCIAS.append(mensaje)


# --------------------------------------------------------------------------
# Rangos de caracteres que no deben aparecer.
#
# CJK: ideogramas, hiragana, katakana, hangul y los signos de puntuacion CJK.
# Cirilico: alfabeto ruso y supplemento.
# Se incluyen a proposito los rangos de puntuacion CJK: un parentesis chino en
# un comentario es tan invisible al lector como un ideograma.
# --------------------------------------------------------------------------
CJK_RANGOS = (
    (0x2E80, 0x2EFF),    # Radicales CJK
    (0x3000, 0x303F),    # Simbolos y puntuacion CJK
    (0x3040, 0x309F),    # Hiragana
    (0x30A0, 0x30FF),    # Katakana
    (0x3100, 0x312F),    # Bopomofo
    (0x3130, 0x318F),    # Hangul compatible con JIS
    (0x31F0, 0x31FF),    # Katakana phonetic extensions
    (0x3400, 0x4DBF),    # Extension A
    (0x4E00, 0x9FFF),    # Ideogramas unificados
    (0xA000, 0xA4CF),    # Yi
    (0xAC00, 0xD7AF),    # Hangul sílabas
    (0xF900, 0xFAFF),    # Compatibilidad CJK
    (0x20000, 0x2A6DF),  # Extension B
)

CIRILICO_RANGOS = (
    (0x0400, 0x04FF),    # Cirilico
    (0x0500, 0x052F),    # Cirilico supplementario
)

ARCHIVOS_IGNORADOS = {".git", "node_modules", "vendor", ".nuxt", ".output"}


def es_ignorable(ruta: Path) -> bool:
    return any(parte in ARCHIVOS_IGNORADOS for parte in ruta.parts)


def archivos_entrega() -> list[Path]:
    encontrados = [
        ruta
        for ruta in RAIZ.rglob("*")
        if ruta.is_file() and not es_ignorable(ruta)
    ]
    return sorted(encontrados)


# --------------------------------------------------------------------------
# 1. Archivos y directorios obligatorios
# --------------------------------------------------------------------------
RUTAS_OBLIGATORIAS = [
    "README.md",
    ".env.example",
    "docs/01-ARQUITECTURA.md",
    "docs/02-MODELO-DE-DATOS.md",
    "docs/03-MULTITENANCY-Y-ROLES.md",
    "docs/04-INSTALACION.md",
    "docs/05-CONFIGURACION.md",
    "docs/06-PRIMER-TURNO.md",
    "docs/07-SEGURIDAD.md",
    "docs/08-RESPALDOS.md",
    "docs/09-API.md",
    "docs/10-PRUEBAS-Y-ESTADO.md",
    "api/composer.json",
    "api/phpunit.xml",
    "api/bootstrap/app.php",
    "api/artisan",
    "api/database/seeders/DatabaseSeeder.php",
    "api/tests/TestCase.php",
    "web/package.json",
    "web/nuxt.config.ts",
    "deploy/docker-compose.dev.yml",
    "deploy/docker-compose.prod.yml",
]

TABLAS_CON_TENANT = [
    "usuarios", "turnos", "turno_asignaciones", "zonas", "mesas",
    "grupos_mesas", "grupo_mesas_mesas", "estaciones", "categorias_productos",
    "productos", "ingredientes", "receta_ingredientes", "grupos_modificadores",
    "modificadores", "producto_grupos_modificadores", "comandas",
    "contadores_folio", "comanda_items", "comanda_item_modificadores",
    "comanda_eventos", "cuentas", "cuenta_divisiones", "pagos",
]


def revisar_estructura() -> None:
    for relativa in RUTAS_OBLIGATORIAS:
        if not (RAIZ / relativa).is_file():
            fallar(f"Falta el archivo obligatorio: {relativa}")

    migraciones = sorted((RAIZ / "api/database/migrations").glob("*.php"))
    if len(migraciones) < 14:
        fallar(
            f"Se esperaban al menos 14 migraciones y hay {len(migraciones)}. "
            "Revisar el modelo de datos."
        )

    for relativa in ("docs", "api", "web", "deploy"):
        if not (RAIZ / relativa).is_dir():
            fallar(f"Falta el directorio obligatorio: {relativa}")


# --------------------------------------------------------------------------
# 2. JSON valido
# --------------------------------------------------------------------------
def revisar_json() -> None:
    for relativa in ("api/composer.json", "web/package.json"):
        ruta = RAIZ / relativa
        if not ruta.is_file():
            continue
        try:
            json.loads(ruta.read_text(encoding="utf-8"))
        except (json.JSONDecodeError, UnicodeDecodeError) as error:
            fallar(f"{relativa} no es JSON valido: {error}")


# --------------------------------------------------------------------------
# 3 y 4. UTF-8, sin BOM, sin CJK y sin cirilico
# --------------------------------------------------------------------------
def revisar_caracteres() -> int:
    revisados = 0

    for ruta in archivos_entrega():
        crudo = ruta.read_bytes()

        if crudo.startswith(b"\xef\xbb\xbf"):
            fallar(f"{ruta.relative_to(RAIZ)} tiene marcador de orden de bytes (BOM).")

        try:
            texto = crudo.decode("utf-8")
        except UnicodeDecodeError as error:
            fallar(f"{ruta.relative_to(RAIZ)} no es UTF-8 valido: {error}")
            continue

        revisados += 1

        for numero_linea, linea in enumerate(texto.splitlines(), 1):
            for columna, caracter in enumerate(linea, 1):
                if caracter == "\ufffd":
                    fallar(
                        f"{ruta.relative_to(RAIZ)}:{numero_linea}:{columna} "
                        "caracter de reemplazo Unicode (texto roto)."
                    )
                    continue

                punto = ord(caracter)

                if any(desde <= punto <= hasta for desde, hasta in CJK_RANGOS):
                    fallar(
                        f"{ruta.relative_to(RAIZ)}:{numero_linea}:{columna} "
                        f"caracter CJK no permitido: {unicodedata.name(caracter, 'desconocido')} "
                        f"(U+{punto:04X})."
                    )

                if any(desde <= punto <= hasta for desde, hasta in CIRILICO_RANGOS):
                    fallar(
                        f"{ruta.relative_to(RAIZ)}:{numero_linea}:{columna} "
                        f"caracter cirilico no permitido: {unicodedata.name(caracter, 'desconocido')} "
                        f"(U+{punto:04X})."
                    )

    return revisados


# --------------------------------------------------------------------------
# 5. RLS
# --------------------------------------------------------------------------
def revisar_rls() -> None:
    ruta = RAIZ / "api/database/migrations/2026_10_17_000013_activar_row_level_security.php"
    if not ruta.is_file():
        fallar("No se encontro la migracion que activa Row Level Security.")
        return

    texto = ruta.read_text(encoding="utf-8")

    for tabla in TABLAS_CON_TENANT:
        if f"'{tabla}'" not in texto:
            fallar(f"La tabla '{tabla}' no aparece en la lista de tablas protegidas con RLS.")

    for requerido in (
        "ENABLE ROW LEVEL SECURITY",
        "FORCE ROW LEVEL SECURITY",
        "USING (tenant_id = public.app_tenant_id())",
        "WITH CHECK (tenant_id = public.app_tenant_id())",
        "set_config",
    ):
        if requerido not in texto:
            fallar(f"La migracion de RLS no contiene el fragmento requerido: {requerido}")

    if "'restaurantes'," in texto.split("private array")[1].split("];")[0]:
        fallar("`restaurantes` no debe estar en la lista de RLS: se consulta en el inicio de sesion.")

    # La funcion de contexto tiene que fallar cerrado, no devolver NULL.
    if "RAISE EXCEPTION" not in texto:
        fallar("La funcion app_tenant_id() debe lanzar excepcion cuando no hay contexto.")


# --------------------------------------------------------------------------
# 6. .env.example sin valores reales
# --------------------------------------------------------------------------
# --------------------------------------------------------------------------
# 6. .env.example sin valores reales
#
# OJO con el patron: se usan clases `[ \t]` y NO `\s`. Con `\s`, que incluye el
# salto de linea, una linea como `APP_KEY=` (vacia) hace que el espacio en
# blanco se coma el salto y la coincidencia continue en la linea siguiente,
# reportando como "valor" el texto de un comentario. Se comprobo: el patron con
# `\s` daba nueve falsos positivos en un archivo que estaba bien.
# --------------------------------------------------------------------------
PATRONES_SECRETOS = re.compile(
    r"^([ \t]*[A-Z0-9_]*(PASSWORD|SECRET|TOKEN|KEY|CLIENT_SECRET)[A-Z0-9_]*[ \t]*=[ \t]*)(\S.*)?$",
    re.MULTILINE | re.IGNORECASE,
)

VALOR_POR_DEFECTO_ACEPTABLE = re.compile(r"^(\$|\{\{|changeme|cambiar|generar|pendiente)", re.IGNORECASE)


def revisar_entorno() -> None:
    ruta = RAIZ / ".env.example"
    if not ruta.is_file():
        fallar("Falta .env.example")
        return

    texto = ruta.read_text(encoding="utf-8")

    for coincidencia in PATRONES_SECRETOS.finditer(texto):
        valor = (coincidencia.group(3) or "").strip()

        # Vacio es exactamente lo que tiene que estar.
        if not valor:
            continue

        if VALOR_POR_DEFECTO_ACEPTABLE.match(valor):
            continue

        fallar(
            f".env.example trae un valor en {coincidencia.group(1).strip()}. "
            "Debe quedar vacio o con un marcador."
        )


def revisar_yaml() -> None:
    """Valida que los compose sean YAML correcto.

    No reemplaza a `docker compose config`, que tambien resuelve variables de
    entorno. Comprueba solo que el archivo se pueda leer y tenga servicios.
    """
    try:
        import yaml  # noqa: PLC0415
    except ImportError:
        advertir("PyYAML no esta instalado: no se comprobo la sintaxis de los compose.")
        return

    esperados = {
        "deploy/docker-compose.dev.yml": {"postgres", "redis", "api", "web"},
        "deploy/docker-compose.prod.yml": {"postgres", "redis", "api", "web", "proxy"},
    }

    for relativa, requeridos in esperados.items():
        ruta = RAIZ / relativa

        if not ruta.is_file():
            fallar(f"Falta {relativa}")
            continue

        try:
            documento = yaml.safe_load(ruta.read_text(encoding="utf-8"))
        except yaml.YAMLError as error:
            fallar(f"{relativa} no es YAML valido: {error}")
            continue

        servicios = set((documento or {}).get("services", {}).keys())
        faltan = requeridos - servicios

        if faltan:
            fallar(f"{relativa} no declara los servicios: {', '.join(sorted(faltan))}")

        # En produccion solo Caddy debe publicar puertos.
        if "prod" in relativa:
            publicando = [
                nombre
                for nombre, servicio in (documento or {}).get("services", {}).items()
                if servicio.get("ports")
            ]

            if publicando != ["proxy"]:
                fallar(
                    "En produccion solo el servicio proxy debe publicar puertos. "
                    f"Publican: {', '.join(publicando) or 'ninguno'}"
                )


def revisar_php() -> None:
    """Comprobaciones estaticas sobre los archivos PHP.

    NO es un analizador de PHP: no encuentra errores de sintaxis reales ni
    comprueba tipos. Comprueba tres cosas que se rompen en silencio y que un
    revisor a ojo no ve:

      1. Que namespace y ruta calcen con PSR-4. Si no calzan, la clase no se
         encuentra al cargarla y el fallo aparece en produccion.
      2. Que el nombre de la clase sea el del archivo, por la misma razon.
      3. Que las llaves, parentesis y corchetes esten balanceados, ignorando
         cadenas y comentarios. Un desbalance casi siempre es una llave que se
         perdio al escribir un bloque.
    """
    api = RAIZ / "api"
    pares = (("{", "}", "llaves"), ("(", ")", "parentesis"), ("[", "]", "corchetes"))

    for ruta in sorted(api.rglob("*.php")):
        texto = ruta.read_text(encoding="utf-8")

        if not texto.lstrip().startswith("<?php"):
            fallar(f"{ruta.relative_to(RAIZ)} no empieza con la etiqueta de apertura <?php")

        # 1 y 2: PSR-4. El namespace son SOLO los directorios: el nombre de la
        # clase es el del archivo, y las dos mitades se comprueban por separado.
        if ruta.is_relative_to(api / "app"):
            namespace = re.search(r"^namespace\s+([^;]+);", texto, re.MULTILINE)
            carpetas = ruta.relative_to(api / "app").parent.parts
            esperado = "\\".join(["App", *carpetas]) if carpetas else "App"

            if namespace is None:
                fallar(f"{ruta.relative_to(RAIZ)} no declara namespace.")
            elif namespace.group(1).strip() != esperado:
                fallar(
                    f"{ruta.relative_to(RAIZ)} declara el namespace "
                    f"'{namespace.group(1).strip()}' y por su ruta deberia ser '{esperado}'."
                )

        declarado = re.search(r"^(?:final\s+|abstract\s+)?(?:class|trait|interface|enum)\s+(\w+)", texto, re.MULTILINE)

        if declarado and declarado.group(1) != ruta.stem:
            fallar(
                f"{ruta.relative_to(RAIZ)} declara '{declarado.group(1)}' y por convencion PSR-4 "
                f"debería llamarse '{ruta.stem}.php'."
            )

        # 3: balance.
        limpio = quitar_cadenas_y_comentarios(texto)

        for apertura, cierre, nombre in pares:
            if limpio.count(apertura) != limpio.count(cierre):
                fallar(
                    f"{ruta.relative_to(RAIZ)} tiene {nombre} desbalanceados: "
                    f"{limpio.count(apertura)} de '{apertura}' contra {limpio.count(cierre)} de '{cierre}'."
                )


def quitar_cadenas_y_comentarios(texto: str) -> str:
    """Deja solo el codigo: fuera cadenas, comentarios y bloques heredoc."""
    salida: list[str] = []
    indice = 0
    largo = len(texto)

    while indice < largo:
        caracter = texto[indice]

        if caracter == "/" and texto[indice : indice + 2] == "//":
            while indice < largo and texto[indice] != "\n":
                indice += 1
        elif caracter == "#" and (indice == 0 or texto[indice - 1] == "\n"):
            while indice < largo and texto[indice] != "\n":
                indice += 1
        elif texto[indice : indice + 2] == "/*":
            indice += 2
            while indice + 1 < largo and texto[indice : indice + 2] != "*/":
                indice += 1
            indice += 2
        elif caracter == "'":
            indice += 1
            while indice < largo and texto[indice] != "'":
                indice += 2 if texto[indice] == "\\" else 1
            indice += 1
        elif caracter == '"':
            indice += 1
            while indice < largo and texto[indice] != '"':
                indice += 2 if texto[indice] == "\\" else 1
            indice += 1
        elif texto[indice : indice + 3] == "<<<":
            # Heredoc de PHP. El terminador puede ir indentado (casi siempre
            # lo esta) y puede llevar punto y coma, asi que se busca una LINEA
            # que sea la etiqueta sola o la etiqueta con ";". Buscar
            # "\n" + etiqueta a secas solo funciona si el terminador esta en la
            # columna cero y sin punto y coma: cuando no, el parser se come el
            # resto del archivo y reporta un falso "desbalance".
            fin_linea = texto.find("\n", indice)
            etiqueta = texto[indice + 3 : fin_linea].strip().strip("'").strip('"')

            posicion = -1
            busqueda = (fin_linea + 1) if fin_linea >= 0 else indice

            while busqueda < largo:
                fin_de_linea = texto.find("\n", busqueda)
                linea = texto[busqueda:fin_de_linea if fin_de_linea >= 0 else largo]
                limpia = linea.strip()

                if limpia == etiqueta or limpia == etiqueta + ";":
                    posicion = busqueda
                    break

                if fin_de_linea < 0:
                    break

                busqueda = fin_de_linea + 1

            if posicion >= 0:
                # Se salta hasta despues del terminador y del salto siguiente.
                salto = texto.find("\n", posicion)
                indice = (salto + 1) if salto >= 0 else largo
            else:
                indice = largo
        else:
            salida.append(caracter)
            indice += 1

    return "".join(salida)


def main() -> int:
    revisar_estructura()
    revisar_json()
    revisar_entorno()
    revisar_rls()
    revisar_yaml()
    revisar_php()
    revisados = revisar_caracteres()

    print(f"Archivos revisados: {revisados}")
    print(f"Rutas obligatorias comprobadas: {len(RUTAS_OBLIGATORIAS)}")
    print(f"Tablas con RLS comprobadas: {len(TABLAS_CON_TENANT)}")

    for aviso in ADVERTENCIAS:
        print(f"AVISO  {aviso}")

    if FALLOS:
        print("")
        for fallo in FALLOS:
            print(f"FALLA  {fallo}")
        print("")
        print(f"RESULTADO: {len(FALLOS)} falla(s).")
        return 1

    print("")
    print("RESULTADO: la entrega esta completa y limpia segun las comprobaciones estaticas.")
    print("OJO: esto NO significa que el software funcione. Las pruebas de Laravel,")
    print("el SQL contra PostgreSQL y el arranque de los contenedores NO se ejecutaron.")
    return 0


if __name__ == "__main__":
    sys.exit(main())