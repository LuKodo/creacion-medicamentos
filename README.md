# Aplicativo de Creación de Medicamentos — Pharmaser

Reemplaza el proceso Excel **"CREAR ITEMS - CSALDARRIAGA 2025.xlsx"** + script R
**"SCRIPT CREAR MX - LINAA.R"**, conservando sus reglas de negocio y sus 6 archivos de
cargue (SIESA y Medicar), y agregando validaciones automáticas, control de duplicados,
perfiles de usuario y trazabilidad.

Mismo stack y mismo esquema de pruebas que el aplicativo de **Traslados**:
PHP 8 + PDO, `index.html` + `api.php`, MySQL en producción y SQLite en prueba local.

## Estado por fases
Ver **[PLAN_DE_TRABAJO.md](PLAN_DE_TRABAJO.md)** (fase 4 — Maestra Tarifaria — cerrada el 2026-10-03; fase 5 — Solicitudes y motor de validación — en desarrollo).

## Actualizar la estructura sin perder datos
`iniciar_local.bat` ejecuta `actualizar_estructura.php` antes de iniciar: crea tablas/columnas
nuevas y aplica migraciones de datos pendientes (registradas en `sis_migracion`), sin tocar
usuarios, contraseñas ni cargas. En el servidor se usa el mismo comando.

## Archivos
```
index.html, assets/        interfaz (sin dependencias externas)
api.php                    API: sesión, permisos y operaciones (valida todo en el servidor)
config.php                 conexión (lee config.local.php o variables CI_DB_*); SIN credenciales
config.local.example.php   plantilla para TIC -> copiar como config.local.php (no se comparte)
lib/schema.php             esquema único MySQL/SQLite + migración idempotente
lib/semillas.php           perfiles, permisos, reglas de validación, parámetros, subplanes
lib/importar_catalogos.php carga de COD GEN, LAB, Presentacion, LISTAS, RIPS, IUM, campos técnicos
lib/xlsx_reader.php        lector de Excel por streaming (sin librerías externas)
lib/auth.php               sesión, CSRF, bloqueo por intentos, permisos
setup_local_test_db.php    SOLO desarrollo: crea data/local_test.sqlite y activa el modo prueba
setup_db.php               instalación en MySQL VACÍA (solo si no se trasladan los datos de prueba)
trasladar_a_mysql.php      traslado de la base SQLite (primera versión) a MySQL, con verificación tabla por tabla
iniciar_local.bat          inicia en http://localhost:8095 con el PHP portable de Traslados
router.php / .htaccess     bloquean el acceso web a data/, lib/, config*, setup*
```

## Prueba local
```
iniciar_local.bat setup     (crea/reinicia la base de prueba e importa los catálogos del Excel)
iniciar_local.bat           (inicia el servidor)  ->  http://localhost:8095
```
La base de prueba se guarda **fuera de OneDrive**, en `%USERPROFILE%\CreacionMedicamentos_prueba\local_test.sqlite`
(cambiar con `CI_SQLITE_PATH`). No se usa `AppData`: Windows redirige allí la escritura de apps
empaquetadas y la tarea programada no vería la misma base. Solo aplica al modo prueba; producción usa MySQL.
La clave temporal del usuario `admin` queda en `data/CLAVE_INICIAL_ADMIN.txt`: el sistema
obliga a cambiarla en el primer ingreso. **Borre ese archivo después.**
Para volver a MySQL: `php setup_local_test_db.php --off`.

## Bases externas (fase 3)
- **Manuales** (Maestro SIESA, Maestra MEDICAR, Listado Cápita, Listado EVENTO SAS, Contrato
  Evento 22382): el Administrador carga el archivo y se **lee a su tabla en la misma operación**.
  Si faltan columnas obligatorias, se rechaza y la versión anterior sigue vigente.
  Definición de columnas y llaves: `lib/lectores_bases.php`.
- **Fase 4 (bases)**: **Precios regulados** (circular; toma la columna «PRECIO UNITARIO» del año más reciente) y
  **Maestra Mutual Ser** automática (`php actualizar_mutual.php`, reemplaza MaestraMutualser.R): descarga el Excel
  del enlace del parámetro `MUTUAL_MAESTRA_URL` y lee la hoja «Consulta de medicamentos» (CUM ↔ ID MUTUAL).
  El lector de Excel lee por partes: sirve para hojas de cientos de MB.

- **INVIMA automático**: `php actualizar_invima.php` descarga las 5 fuentes de datos.gov.co
  (≈ 1,8 millones de registros) y consolida un estado por CUM y por registro sanitario
  (prioridad Vigente > En trámite > Otros > Vencido). Si ninguna fuente cambió, no descarga.
  Desde el aplicativo: Bases de referencia → INVIMA → «Actualizar ahora» (corre en segundo plano).
  **Programado cada 7 días** en el equipo local: tarea de Windows «CreacionMedicamentos - INVIMA» →
  `actualizar_invima_programado.bat` (resultado en `data/invima_programado.log`).
  - Servidor (TIC, Linux): `0 5 * * 1 php /ruta/Aplicativo/actualizar_invima.php >> /var/log/invima.log 2>&1`
- La fecha de actualización de cada base se muestra en verde o, si supera su límite de días
  (configurable por el Administrador), en rojo como «Desactualizada».

## Maestra Tarifaria (fase 4)
Se mantiene en el aplicativo (pantalla **Maestra Tarifaria**, tabla `nt_item`) y se exporta a Excel con la estructura
de la hoja EVENTO 2025. Solo se guarda lo digitado; lo que en el Excel eran fórmulas lo recalcula
`lib/nota_tecnica.php` contra las bases vigentes (reglas NT01–NT16). El contrato y el precio se validan con el
**COD MUTUAL**. Administrador: crear/editar, importar desde Excel (vista previa → aplicar; celda vacía = no
cambia) y **calculadora de precio** (cápita, o compra + `NT_PORCENTAJE_PRECIO` % con tope en el regulado).

## Verificación del entorno
`php verificar_entorno.php` (solo lectura): estructura, integridad, seguridad (permisos exclusivos,
usuarios de prueba, credenciales), consistencia de catálogos y estado de las bases. Sale con código 1
si hay errores. Ejecutarlo después de cada despliegue.

## Paso a producción (TIC)
1. Crear la base `creacion_items` (utf8mb4, `utf8mb4_unicode_ci`) y un usuario MySQL con permisos **solo** sobre ella:
   SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES (no usar la cuenta `admin`).
2. Copiar `config.local.example.php` como `config.local.php` con esos datos (o usar variables CI_DB_*).
3. Subir la carpeta del aplicativo **con** `data/bases/` (archivos de las versiones cargadas) y **sin**
   `data/.local_test`, `setup_local_test_db.php`, `router.php`, `iniciar_local.bat`, los `.bat` ni `config.local.php` de desarrollo.
4. Trasladar la primera versión (base SQLite entregada con el paquete), primero en revisión y luego confirmado:
   `php trasladar_a_mysql.php --origen=RUTA/local_test.sqlite` y después el mismo comando con `--confirmar`.
   La base destino debe estar vacía; el script crea la estructura y compara filas y huella (sha256) de cada tabla.
   Luego `php actualizar_estructura.php` y `php verificar_entorno.php`.
   (Probado el 2026-10-19 en MySQL 8.4.6: 40 tablas iguales, ~860 mil filas, ~45 s.)
   Instalación sin datos (alternativa): `php setup_db.php` (seguro de re-ejecutar).
5. **php.ini** (no se puede cambiar desde el aplicativo): `upload_max_filesize = 200M`, `post_max_size = 210M`
   (el cargue de bases acepta hasta 200 MB). `memory_limit` y el tiempo los ajusta `api.php` (1024M, 300 s).
6. **«Actualizar ahora» (INVIMA y Mutual)** lanza un PHP de consola en segundo plano: si el PHP de la web no es
   el de consola (php-fpm, mod_php), defina `CI_PHP_CLI` (p.ej. `/usr/bin/php`) en `config.local.php`.
7. Apache 2.4: `.htaccess` deja ver solo `index.html`, `api.php` y `assets/`; `data/`, `lib/` y `salidas/` tienen
   además su propio `.htaccess` (Require all denied). Con Nginx, bloquear esas carpetas en la configuración.
8. El modo de prueba local (`data/.local_test`) solo funciona por consola o en localhost: si se copia al servidor
   por error, no activa SQLite.

## Perfiles (USUARIOS → PERFILES → PERMISOS → MÓDULOS)
Matriz inicial = punto 13.5 de la especificación. Editable por el Administrador en
**Perfiles y permisos**. El cargue de bases externas (`BASE.ACTUALIZAR`) es solo del Administrador;
los demás perfiles consultan su estado.
Barreras: el Administrador no puede quitarse su perfil ni desactivarse, siempre queda al
menos un Administrador activo, y el perfil Administrador no puede perder la gestión de usuarios.

## Catálogos: el aplicativo es la fuente oficial
Desde la fase 2 el Administrador crea y edita laboratorios, presentaciones y COD GEN en
**Catálogos** (permiso `CATALOGO.EDITAR`, exclusivo del Administrador). Reglas:
- Los códigos no se editan después de creados (hacen parte del código de producto).
- No se borra: se desactiva. Todo cambio queda en auditoría (antes / después).
- Nombres en mayúsculas, sin espacios repetidos y sin duplicados.
- Código Medicar numérico; POS/NO POS y cadena de frío con listas cerradas.
- El Excel solo se importa en una base vacía: reinstalar no sobrescribe los cambios hechos en pantalla.

**Listas desplegables** (Tipo de medicamento, Estado INVIMA, Envase de presentación y las que cree el
Administrador con «+ Nuevo catálogo»): cada una es un catálogo independiente (`cat_lista` +
`cat_lista_valor`) que alimentará las listas del formulario de creación. Los valores se crean,
ordenan y activan/desactivan; no se renombran (los productos guardan el texto). Categoría PBS no es
lista: se toma de COD GEN (POS / NO POS).

**Actualización masiva por Excel** (Catálogos → «Actualizar … desde Excel»): descargar plantilla
(catálogo actual) → corregir → previsualizar → aplicar. Celda vacía = no cambia. Solo se validan
las celdas que cambian; los valores irregulares que ya existían se listan como «datos a corregir»
sin bloquear. Con un solo error no se aplica nada.
En COD GEN, una fila con código genérico inexistente se cruza también por **Cód. Medicar**: si ese
Medicar pertenece a un registro cuyo código ya no está en el archivo, es una **corrección de código**
de ese registro (no un nuevo); si hay varios candidatos, es error por ambigüedad. Sin versiones; cada registro modificado queda
en auditoría (CATALOGO_EXCEL_EDITAR / _CREAR / _CARGUE).

Permisos exclusivos del Administrador (no asignables a otros perfiles): BASE.ACTUALIZAR,
CATALOGO.EDITAR, USUARIO.GESTIONAR, PERFIL.ASIGNAR, PARAMETRO.ADMINISTRAR, PRECIO.ASIGNAR,
PRECIO.APROBAR_EXCEPCION, NT.GESTIONAR.

## Cambios frente al proceso actual (documentados)
| # | Proceso actual | Aplicativo | Origen |
|---|---|---|---|
| R1 | Tipo de inventario según IVA (script) | Según tipo de medicamento; IVA independiente | Usuario |
| R4 | Precio por COD AUF (primera coincidencia) | CUM → ID MUTUAL → precio de la misma fila | Usuario |
| R5 | "Validar" → precio 1 | Precio 1 = ERROR salvo excepción aprobada por Administrador | Usuario |
| R6 | PRECIO UM VENTA vacío | = precio unitario; PRECIO UN COMPLETA = unitario × presentación | Usuario |
| R9 | 5 subplanes fijos en código | Catálogo 1-7 y 9, administrable | Usuario |
| R11 | Lote/fechas fijos en código | Parámetros (PH001, 2030) | Usuario |
| C1 | Código de presentación buscado por unidad de orden (fórmula AM): "CAJA SELLADA X 300" recibía el código de "CAJA X 300" | Se busca por presentación agrupada | Hallazgo fase 2 |
| C2 | Laboratorios con dos códigos (6 casos) | Se conserva el primero, tal como estaba en el Excel | Usuario (fase 2) |
| C3 | Código RIPS extraído tras el PRIMER guion | Se guarda el código directamente del catálogo | Hallazgo fase 2 |
