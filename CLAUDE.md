# Updater from Drive

Plugin de WordPress que actualiza los plugins instalados en una web desde paquetes ZIP
guardados en una carpeta de Google Drive que el propio usuario controla.

Distribución fuera de WordPress.org, desde `https://potencia.pro/own-plugins/`.

---

## Estado actual

*(27/10/2026)*

**Terminado y en producción. No hay trabajo pendiente que bloquee nada.**

| | |
|---|---|
| Versión | **1.0.3** |
| Repositorio | `github.com/materron/updater-from-drive` — **público** |
| Último commit | `29958e2`, etiquetado `v1.0.3`. Sin cambios locales |
| Publicado en | `https://potencia.pro/own-plugins/updater-from-drive-1.0.3.zip` (34.457 bytes, 30/07/2026) |
| Funcionando en | `miguelon.es` y `test-saiyanet.enteratec.com` (activo, 1.0.3) |

**Qué está probado de verdad**, contra Google Drive real y no en simulación: listar la
carpeta, emparejar por nombre, descargar, verificar la cabecera, instalar, y **no** repetir
la instalación en la siguiente pasada. También el camino de rechazo (un ZIP cuyo contenido
no coincide con su nombre no se instala) y el autoactualizado desde `own-plugins`.

**Lo que queda, sin prisa y sin bloquear nada:**

1. **Las actualizaciones de Drive no salen en Escritorio → Actualizaciones.** Viven solo en
   la pantalla del plugin. Inyectarlas en el transient estándar las pondría donde el usuario
   ya mira, y permitiría las actualizaciones automáticas de WordPress. Consecuencia a
   valorar: en plugins con actualizador propio (Astra Pro, por ejemplo) habría **dos**
   ofertas para el mismo plugin y hay que decidir cuál gana.
2. **«Compatibilidad con WordPress X: No probada»** bajo el propio plugin en la pantalla de
   actualizaciones. Es cosmético: el campo `tested` va vacío al inyectar la actualización en
   `UFDRIVE_Self_Updater::inject_update()`. Se arregla leyendo el `Tested up to` del readme.

---

## Cómo publicar una versión nueva

1. Subir la versión en **tres sitios a la vez**: cabecera de `updater-from-drive.php`,
   constante `UFDRIVE_VERSION` y `Stable tag` del `readme.txt`. Añadir entrada al changelog.
2. Construir el ZIP **excluyendo la basura de macOS y el repositorio**:

```bash
cd /tmp && rm -rf rel && mkdir rel && cd rel
cp -R "/Volumes/Storage/Proyectos/Google Drive Updater/updater-from-drive" .
rm -rf updater-from-drive/.git updater-from-drive/.gitignore
find updater-from-drive -name '.DS_Store' -delete
zip -rqX updater-from-drive-X.Y.Z.zip updater-from-drive
```

3. Subirlo y **borrar el anterior** (el plugin coge siempre la versión más alta, pero dejar
   versiones viejas en una carpeta pública es ofrecer fallos ya corregidos):

```bash
rsync -a updater-from-drive-X.Y.Z.zip u89861@91.204.209.215:~/domains/potencia.pro/public_html/own-plugins/
```

4. Commit, etiqueta `vX.Y.Z` y push a GitHub.

> ⚠️ **El 16/09/2026 apareció en la carpeta del proyecto un `updater-from-drive-1.0.3.zip`
> de 192 KB con 292 ficheros**, de los cuales 271 eran el directorio `.git` entero y los
> `__MACOSX`. No llegó a publicarse, y se borró el 27/10/2026. Lo preocupante es que la
> verificación de cabecera **no lo habría detenido**: comprueba que el paquete contenga el
> plugin correcto, no que esté limpio. De ahí el `zip -rqX` con exclusiones de arriba.

---

## Cómo está hecho

Prefijo `UFDRIVE_` / `ufdrive_`, text domain `updater-from-drive`.

| Clase | Qué hace |
|---|---|
| `UFDRIVE_Settings` | Ajustes. La clave puede venir de `UFDRIVE_API_KEY` en `wp-config.php` |
| `UFDRIVE_Api_Key_Credentials` | Implementa `UFDRIVE_Credentials`. La interfaz existe para que añadir OAuth sea una clase nueva, no una reescritura |
| `UFDRIVE_Drive_Client` | Drive v3. Traduce los errores de Google a algo accionable |
| `UFDRIVE_Package` | Lee el nombre del fichero y la cabecera de dentro del ZIP |
| `UFDRIVE_Matcher` | Emparejado en tres capas (ver abajo) |
| `UFDRIVE_Updater` | Compara, descarga, verifica e instala |
| `UFDRIVE_Self_Updater` | Lee `own-plugins/` y se inyecta en el transient de actualizaciones |
| `UFDRIVE_Upgrader_Skin` | **Se carga solo dentro de `apply()`**, nunca en el arranque |

### Las cuatro decisiones que importan

**El nombre del fichero es una afirmación, no un hecho.** Comparar cuesta cero (solo el
listado), así que se empareja por nombre. Pero antes de instalar se abre el ZIP y se lee la
cabecera, que entonces es gratis porque el fichero ya está descargado. Si no coincide, no se
instala y se dice por qué.

**La versión se comprueba contra lo instalado, no contra el nombre.** Es lo que hace
imposible el bucle infinito de actualizaciones: si instalar el paquete no puede hacer subir
la versión, no se instala. Una discrepancia entre nombre y cabecera que aun así avanza se
usa y se avisa.

**El emparejado va en tres capas, de más barata a más cara.** Exacta; luego ignorando
puntuación y terminaciones decorativas (`-plugin`, `-wp`…), que resuelve gratis
`astra-addon-plugin` → `astra-addon` y `gravity-forms-quiz` → `gravityformsquiz`; y por
último abrir candidatos fuertes y leer la cabecera, con tope por pasada y recordando el
resultado para siempre. Un nombre de una sola palabra nunca dispara la tercera capa:
«woocommerce» es prefijo de decenas de extensiones.

**Lo que no se puede adivinar se enseña.** Ningún algoritmo convierte `woothemes-sensei` en
`woocommerce-paid-courses`, así que los plugins sin pareja salen en una tabla de los ajustes
con un desplegable para emparejarlos a mano. Se filtran los que WordPress.org ya actualiza,
o el panel sería todo ruido.

---

## Probar

Web de pruebas: `test-saiyanet.enteratec.com` (ver el CLAUDE.md global para el acceso).
WP-CLI está como `~/bin/wp` y como `~/bin/wp-cli.phar`, ambos 2.12.0.

```bash
rsync -a --delete --exclude='.DS_Store' --exclude='.git' \
  "/Volumes/Storage/Proyectos/Google Drive Updater/updater-from-drive/" \
  materron@10.10.100.70:~/web/test-saiyanet.enteratec.com/public_html/wp-content/plugins/updater-from-drive/
```

⚠️ **Probar activando de verdad, no solo leyendo el código.** Los cuatro fallos reales de
este plugin salieron todos al ejecutarlo, ninguno a la vista: una clase de core cargada
demasiado pronto que reventaba el frontend, un saneado no idempotente que corrompía la
lista de plugins en silencio, una carpeta no compartida que devolvía lista vacía **sin
error**, y las unidades compartidas de Drive invisibles sin `supportsAllDrives`.

---

## `plugin-check` da ERROR, y es correcto

```
plugin_updater_detected — No están permitidos en plugins alojados en WordPress.org
```

**Esperado y no arreglable.** WordPress.org prohíbe los actualizadores propios, y su
directriz 8 prohíbe además instalar plugins desde servidores ajenos al repositorio. Las dos
cosas son justo lo que hace este plugin. Fue una decisión consciente: se distribuye desde
potencia.pro. **No intentar «arreglar» ese error.**

El repositorio es **público** por petición expresa, excepción a la norma global de crear
todo privado.

---

## Qué NO es este proyecto

**Potencier Updater** (`/Volumes/Storage/Proyectos/Potencier Updater/`) es un producto
distinto: catálogo de Potencia Pro servido por Download Monitor a suscriptores de pago, con
tokens por sitio y cuotas diarias. Reutiliza ideas y algo de código de aquí, pero son cosas
separadas. **No mezclar.**
