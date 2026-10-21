<?php
/**
 * trasladar_a_mysql.php — paso 11.6: copia la base de prueba (SQLite) al MySQL del aplicativo.
 *
 * El destino es el MySQL de config.local.php / variables CI_DB_* (usuario restringido a la base). La base destino debe estar
 * VACÍA: se crea la estructura, se copian todas las tablas conservando los id y se comparan, tabla por tabla, el número de filas
 * y una huella del contenido (si algo no coincide, se informa).
 *
 * Uso:  php trasladar_a_mysql.php --origen=RUTA\local_test.sqlite            -> solo revisa: copia y deshace (no deja datos)
 *       php trasladar_a_mysql.php --origen=RUTA\local_test.sqlite --confirmar -> copia y deja los datos
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/datos.php';
require_once __DIR__ . '/lib/schema.php';

$opt = getopt('', ['origen:', 'confirmar']);
$origen = (string) ($opt['origen'] ?? '');
$confirmar = isset($opt['confirmar']);
$out = static fn(string $m) => print($m . PHP_EOL);
if ($origen === '' || !is_file($origen)) exit("Indique la base de prueba: --origen=RUTA\\local_test.sqlite" . PHP_EOL);

$src = new PDO('sqlite:' . $origen, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
// destino: siempre el MySQL configurado (aunque exista el marcador del modo de prueba local)
$c = app_config();
if ($c['db_host'] === '' || $c['db_user'] === '') exit('Configure el MySQL destino en config.local.php o con CI_DB_*.' . PHP_EOL);
$dst = new PDO("mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$dst->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$out("Origen:  $origen");
$out("Destino: MySQL {$c['db_host']}:{$c['db_port']} / {$c['db_name']} (" . $dst->query('SELECT VERSION()')->fetchColumn() . ')');
$out($confirmar ? 'Modo: COPIA (deja los datos)' : 'Modo: REVISIÓN (copia y deshace; use --confirmar para dejar los datos)');

// 1. estructura (crea lo que falte; no borra nada)
migrar($dst);
$tablas = array_keys(esquema_tablas());
$conDatos = array_filter($tablas, fn($t) => (int) $dst->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0);
if ($conDatos) exit('La base destino no está vacía (' . implode(', ', $conDatos) . '): el traslado se hace sobre una base nueva.' . PHP_EOL);

// valor comparable entre motores (DECIMAL de MySQL «12.50» = REAL de SQLite «12.5»; fechas iguales)
$norm = static function ($v): string {
    if ($v === null) return "\0";
    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v) && !preg_match('/^0\d/', $v) && !str_contains($v, 'e') && !str_contains($v, 'E'))) return (string) round((float) $v, 4);
    return (string) $v;
};
$huella = static function (PDO $pdo, string $t, array $cols, string $orden) use ($norm): string {
    $h = hash_init('sha256');
    foreach ($pdo->query('SELECT ' . implode(', ', $cols) . " FROM $t ORDER BY $orden") as $r) hash_update($h, implode("\x1f", array_map($norm, $r)) . "\x1e");
    return hash_final($h);
};

// 2. copia tabla por tabla (llaves foráneas desactivadas mientras tanto; se conservan los id)
$dst->exec('SET FOREIGN_KEY_CHECKS = 0');
$dst->beginTransaction();
$errores = []; $resumen = [];
try {
    foreach ($tablas as $t) {
        $colsSrc = array_column($src->query("PRAGMA table_info($t)")->fetchAll(), 'name');
        if (!$colsSrc) { $resumen[$t] = [0, 0, 'no está en el origen']; continue; }
        $colsDst = $dst->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$t'")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_values(array_intersect($colsSrc, $colsDst));
        $sobran = array_diff($colsSrc, $colsDst);
        if ($sobran) $errores[] = "$t: columnas del origen que no existen en el esquema: " . implode(', ', $sobran);
        $orden = in_array('id', $cols, true) ? 'id' : $cols[0];
        $n = (int) $src->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        $copiadas = 0; $bloque = [];
        $insertar = function (array $filas) use ($dst, $t, $cols, &$copiadas, &$errores) {
            if (!$filas) return;
            $ph = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            try {
                $dst->prepare("INSERT INTO $t (" . implode(', ', $cols) . ') VALUES ' . implode(', ', array_fill(0, count($filas), $ph)))
                    ->execute(array_merge(...array_map('array_values', $filas)));
                $copiadas += count($filas);
            } catch (PDOException $e) {
                // fila por fila para decir cuál no cabe (largo, tipo, duplicado por intercalación…)
                foreach ($filas as $f) {
                    try { $dst->prepare("INSERT INTO $t (" . implode(', ', $cols) . ") VALUES $ph")->execute(array_values($f)); $copiadas++; }
                    catch (PDOException $e2) { if (count($errores) < 200) $errores[] = "$t id " . ($f['id'] ?? reset($f)) . ': ' . $e2->errorInfo[2]; }
                }
            }
        };
        foreach ($src->query('SELECT ' . implode(', ', $cols) . " FROM $t ORDER BY $orden") as $r) {
            $bloque[] = $r;
            if (count($bloque) >= max(1, intdiv(8000, count($cols)))) { $insertar($bloque); $bloque = []; }
        }
        $insertar($bloque);
        $igual = $copiadas === $n && $huella($src, $t, $cols, $orden) === $huella($dst, $t, $cols, $orden);
        $resumen[$t] = [$n, $copiadas, $igual ? 'igual' : 'DIFERENTE'];
    }
    if ($confirmar && !$errores) $dst->commit(); else $dst->rollBack();
} catch (Throwable $e) {
    if ($dst->inTransaction()) $dst->rollBack();
    throw $e;
} finally {
    $dst->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$out('');
$out(sprintf('%-28s %10s %10s  %s', 'Tabla', 'Origen', 'MySQL', 'Contenido'));
foreach ($resumen as $t => [$n, $k, $e]) if ($n || $k || $e !== 'igual') $out(sprintf('%-28s %10s %10s  %s', $t, number_format($n, 0, ',', '.'), number_format($k, 0, ',', '.'), $e));
$dif = array_filter($resumen, fn($r) => $r[2] !== 'igual');
$out('');
if ($errores) { $out('Problemas (' . count($errores) . '):'); foreach ($errores as $x) $out("  - $x"); }
if (!$errores && !$dif) $out($confirmar ? 'TRASLADO COMPLETO: todas las tablas iguales al origen.' : 'REVISIÓN OK: todo cabe y queda igual. Ejecute con --confirmar para dejar los datos.');
else $out($confirmar ? 'No se dejó ningún dato: corrija lo indicado y repita.' : 'Revisión con diferencias: corrija lo indicado antes de --confirmar.');
exit($errores || $dif ? 1 : 0);
