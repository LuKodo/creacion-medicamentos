<?php
/** Instalación común (MySQL o SQLite): tablas, semillas, catálogos y administrador inicial. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/semillas.php';
require_once __DIR__ . '/importar_catalogos.php';

function instalar(bool $importar = true): void {
    $pdo = db();
    $out = static fn(string $m) => print($m . PHP_EOL);

    $out('Motor: ' . db_driver() . (modo_prueba_local() ? ' (MODO PRUEBA LOCAL)' : ''));
    $n = count(migrar($pdo));
    $out("Estructura verificada ($n tablas e índices).");

    semilla_seguridad($pdo);
    semilla_parametros($pdo);
    semilla_catalogos_fijos($pdo);
    semilla_reglas($pdo);
    semilla_bases($pdo);
    semilla_listas($pdo);
    $out('Seguridad, parámetros, subplanes, grupos impositivos, reglas y bases externas: OK');

    // Desde que el Administrador edita los catálogos en el aplicativo, este es la fuente oficial:
    // el Excel solo se importa en una base vacía, para no sobrescribir cambios hechos en pantalla.
    if ($importar && (int) $pdo->query('SELECT COUNT(*) FROM cat_molecula')->fetchColumn() > 0) {
        $out('Los catálogos ya existen: NO se reimportan desde el Excel (se conservan los cambios del Administrador).');
        $importar = false;
    }
    if ($importar && !is_file(app_config()['excel_catalogos'])) {
        $out('No se encontró el Excel de catálogos (' . basename(app_config()['excel_catalogos']) . '): no se importan. En el servidor los catálogos se trasladan desde la base de prueba (paso 11.6) o se cargan por Excel en Catálogos.');
        $importar = false;
    }
    if ($importar) {
        $c = app_config();
        $out('Importando catálogos desde: ' . basename($c['excel_catalogos']) . ' ...');
        $ini = microtime(true);
        foreach (importar_catalogos($pdo, $c['excel_catalogos'], $c['excel_campos_tecnicos']) as $tabla => $r) {
            $out(sprintf('  %-20s %6d filas%s', $tabla, $r['cargadas'], $r['omitidas'] ? '  (' . count($r['omitidas']) . ' omitidas, ver Catálogos en el aplicativo)' : ''));
        }
        $out(sprintf('  Importación en %.1f s', microtime(true) - $ini));
    }

    // Después de importar: algunas migraciones de datos trabajan sobre los catálogos cargados.
    foreach (migraciones_datos($pdo) as $m) $out("Migración de datos aplicada: $m");

    $clave = semilla_admin($pdo);
    if ($clave !== null) {
        // La clave temporal NO se imprime en pantalla: se deja en un archivo local que se debe
        // borrar después del primer ingreso (el sistema obliga a cambiarla).
        $archivo = __DIR__ . '/../data/CLAVE_INICIAL_ADMIN.txt';
        file_put_contents($archivo, "Usuario: admin\nClave temporal: $clave\n\nSe pedirá cambiarla en el primer ingreso. Borre este archivo después.\n");
        $out('Usuario administrador creado. La clave temporal quedó en data/CLAVE_INICIAL_ADMIN.txt');
    } else {
        $out('Ya existe un administrador: no se creó uno nuevo.');
    }
}
