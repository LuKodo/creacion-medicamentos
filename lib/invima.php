<?php
/**
 * INVIMA AUTOMÁTICO (fase 3.7 / 3.8) — descarga de datos abiertos (datos.gov.co, Socrata) y consolidado.
 *
 * Corrige el script "Extraer invima - Angie.R":
 *  - Paginación por :id (no por $offset sin orden): no se pierden ni se repiten registros.
 *  - $select: solo las columnas necesarias (menos datos por la red).
 *  - Si ninguna fuente cambió desde la última descarga (rowsUpdatedAt), no se descarga de nuevo.
 *  - Se guarda la base COMPLETA (no solo lo que ya está en SIESA): sirve para validar productos nuevos.
 *  - Un mismo CUM/registro en varias fuentes se resuelve con prioridad de estado, no "el primero".
 *  - La versión anterior sigue vigente mientras descarga; al final se intercambian.
 *  - RS/NSO se descarga completo, todos los grupos (decisión del usuario, P4).
 */
require_once __DIR__ . '/datos.php';

const INVIMA_PAGINA = 50000;

/** Fuentes: parámetro INVIMA_DATASETS = "VIGENTE:i7cb-raxc|VENCIDO:vwwf-4ftk|..." */
function invima_fuentes(): array {
    $f = [];
    foreach (explode('|', parametro('INVIMA_DATASETS', '')) as $par) {
        [$nombre, $id] = array_map('trim', explode(':', $par) + [1 => '']);
        if ($nombre !== '' && preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}$/', $id)) $f[$nombre] = $id;
    }
    if (!$f) throw new RuntimeException('Parámetro INVIMA_DATASETS vacío o inválido.');
    return $f;
}

/** Prioridad del estado (1 = mejor). Orden configurable: INVIMA_PRIORIDAD_ESTADOS = "VIGENTE|EN TRAMITE|OTROS|VENCIDO". */
function invima_prioridad(?string $estado): int {
    static $orden = null;
    $orden ??= array_flip(array_map('trim', explode('|', parametro('INVIMA_PRIORIDAD_ESTADOS', 'VIGENTE|EN TRAMITE|OTROS|VENCIDO'))));
    $e = strtr(mb_strtoupper(trim((string) $estado)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    if ($e === '') $cat = 'OTROS';
    elseif (str_contains($e, 'VIGENTE') || $e === 'ACTIVO') $cat = 'VIGENTE';
    elseif (str_contains($e, 'TRAMITE')) $cat = 'EN TRAMITE';
    elseif (preg_match('/VENCID|CANCELAD|PERDIDA|INACTIV|SUSPENDID|NEGAD|DESIST|ARCHIV|ANULAD/', $e)) $cat = 'VENCIDO';
    else $cat = 'OTROS';
    return ($orden[$cat] ?? 3) + 1;
}

function invima_http(string $url, int $intentos = 4): array {
    for ($i = 1; ; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_ENCODING => '', // acepta gzip: mucho menos tráfico
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'Pharmaser-CreacionMedicamentos/' . APP_VERSION,
        ]);
        // En Windows se usa el almacén de certificados del sistema (la verificación SSL NO se desactiva).
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        $cuerpo = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($cuerpo !== false && $codigo === 200) {
            $json = json_decode($cuerpo, true);
            if (is_array($json)) return $json;
            $error = 'respuesta no es JSON';
        }
        if ($i >= $intentos) throw new RuntimeException("datos.gov.co no respondió ($codigo $error) tras $intentos intentos: $url");
        sleep(5 * $i); // espera creciente antes de reintentar
    }
}

/** Fecha de última modificación de cada fuente (para no descargar si nada cambió). */
function invima_versiones_remotas(array $fuentes): array {
    $v = [];
    foreach ($fuentes as $nombre => $id) {
        $meta = invima_http("https://www.datos.gov.co/api/views/$id.json");
        $v[$nombre] = (int) ($meta['rowsUpdatedAt'] ?? 0);
    }
    return $v;
}

function _proceso(PDO $pdo, int $id, array $campos): void {
    $campos['actualizado_en'] = ahora();
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
    $pdo->prepare("UPDATE sis_proceso SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
}

function _cum(?string $exp, ?string $cons): ?string {
    $exp = trim((string) $exp); $cons = trim((string) $cons);
    return ($exp !== '' && $cons !== '') ? "$exp-$cons" : null;
}
/** 'AAAA-MM-DD' o null. La fuente usa 1900-01-01 como relleno cuando no hay fecha. */
function _fecha_valida(?string $f): ?string {
    $f = substr(trim((string) $f), 0, 10);
    return (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && $f > '1901-01-01') ? $f : null;
}
function _registro(?string $r): ?string {
    $r = trim(preg_replace('/^INVIMA\s+/i', '', (string) $r));
    return $r === '' ? null : mb_substr($r, 0, 60);
}

/**
 * Descarga y consolida. $procesoId: fila de sis_proceso donde se informa el avance.
 * Devuelve el resumen (o ['sin_cambios' => true]).
 */
function invima_actualizar(PDO $pdo, int $procesoId, ?int $usuarioId, bool $forzar = false): array {
    $fuentes = invima_fuentes();
    _proceso($pdo, $procesoId, ['mensaje' => 'Consultando si las fuentes cambiaron…', 'avance' => 1]);
    $remotas = invima_versiones_remotas($fuentes);

    $st = $pdo->prepare("SELECT id, detalle FROM ref_carga WHERE tipo_base = 'INVIMA' AND vigente = 1 ORDER BY id DESC LIMIT 1");
    $st->execute();
    $vigente = $st->fetch() ?: null;
    $st->closeCursor();
    $previas = $vigente ? (json_decode((string) $vigente['detalle'], true)['versiones_fuente'] ?? []) : [];
    if (!$forzar && $vigente && $previas == $remotas) {
        // Nada cambió: se registra la verificación como actualización (la base está al día).
        $pdo->prepare('UPDATE ref_carga SET creado_en = ?, leido_en = ? WHERE id = ?')->execute([ahora(), ahora(), $vigente['id']]);
        auditar($usuarioId, 'INVIMA_SIN_CAMBIOS', 'ref_carga', $vigente['id'], null, ['versiones_fuente' => $remotas]);
        _proceso($pdo, $procesoId, ['estado' => 'SIN_CAMBIOS', 'avance' => 100, 'terminado_en' => ahora(),
            'mensaje' => 'Las fuentes de INVIMA no cambiaron desde la última descarga: la base está al día.']);
        return ['sin_cambios' => true];
    }

    // Conteo total para la barra de avance
    $totales = [];
    foreach ($fuentes as $nombre => $id) $totales[$nombre] = (int) (invima_http("https://www.datos.gov.co/resource/$id.json?\$select=count(*)")[0]['count'] ?? 0);
    $granTotal = max(1, array_sum($totales));

    $cargaId = insertar($pdo, 'ref_carga', [
        'tipo_base' => 'INVIMA', 'origen' => 'datos.gov.co (' . implode(', ', array_keys($fuentes)) . ')', 'filas' => 0,
        'estado' => 'DESCARGANDO', 'vigente' => 0, 'version' => null, 'usuario_id' => $usuarioId, 'creado_en' => ahora(),
        'detalle' => json_encode(['versiones_fuente' => $remotas, 'totales_fuente' => $totales], JSON_UNESCAPED_UNICODE),
    ]);
    $ini = microtime(true);
    $leidos = 0; $porFuente = [];
    $cols = ['carga_id', 'registro_sanitario', 'estado_registro', 'cum', 'estado_cum', 'producto', 'titular', 'fecha_vencimiento', 'grupo', 'fuente', 'prioridad_cum', 'prioridad_reg'];

    try {
        foreach ($fuentes as $nombre => $id) {
            $esRs = $nombre === 'RSNSO';
            $select = $esRs ? ':id,rsynso,estadoregistro,expediente,producto,titular,fechavencimiento,grupo'
                            : ':id,registrosanitario,estadoregistro,expedientecum,consecutivocum,estadocum,producto,titular';
            $ultimo = null; $n = 0;
            do {
                $url = "https://www.datos.gov.co/resource/$id.json?\$select=" . rawurlencode($select)
                     . '&$order=' . rawurlencode(':id') . '&$limit=' . INVIMA_PAGINA
                     . ($ultimo !== null ? '&$where=' . rawurlencode(":id > '$ultimo'") : '');
                $pagina = invima_http($url);
                $filas = [];
                foreach ($pagina as $r) {
                    $reg = _registro($esRs ? ($r['rsynso'] ?? null) : ($r['registrosanitario'] ?? null));
                    $cum = $esRs ? null : _cum($r['expedientecum'] ?? null, $r['consecutivocum'] ?? null);
                    if ($reg === null && $cum === null) continue;
                    $estReg = mb_substr(trim((string) ($r['estadoregistro'] ?? '')), 0, 60) ?: null;
                    $estCum = $esRs ? null : (mb_substr(trim((string) ($r['estadocum'] ?? '')), 0, 30) ?: null);
                    $filas[] = [$cargaId, $reg, $estReg, $cum, $estCum,
                        mb_substr(trim((string) ($r['producto'] ?? '')), 0, 500) ?: null, mb_substr(trim((string) ($r['titular'] ?? '')), 0, 300) ?: null,
                        $esRs ? _fecha_valida($r['fechavencimiento'] ?? null) : null,
                        $esRs ? (mb_substr((string) ($r['grupo'] ?? ''), 0, 100) ?: null) : null,
                        $nombre, $cum ? invima_prioridad($estCum) : null, invima_prioridad($estReg)];
                }
                if ($filas) {
                    $pdo->beginTransaction();
                    insertar_masivo($pdo, 'ref_invima', $cols, $filas, 400);
                    $pdo->commit();
                }
                $n += count($pagina); $leidos += count($pagina);
                $ultimo = $pagina ? end($pagina)[':id'] : null;
                _proceso($pdo, $procesoId, ['avance' => min(95, 2 + (int) (90 * $leidos / $granTotal)),
                    'mensaje' => sprintf('Descargando %s: %s de %s registros', $nombre, number_format($n, 0, ',', '.'), number_format($totales[$nombre], 0, ',', '.'))]);
            } while (count($pagina) === INVIMA_PAGINA);
            $porFuente[$nombre] = $n;
        }

        // ---- Consolidado (3.8): un estado por CUM y por registro, el de mejor prioridad.
        _proceso($pdo, $procesoId, ['avance' => 96, 'mensaje' => 'Consolidando estados por CUM y por registro sanitario…']);
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO ref_invima_cum (carga_id, cum, estado_cum, registro_sanitario, estado_registro, producto, titular, fuente)
            SELECT carga_id, cum, estado_cum, registro_sanitario, estado_registro, producto, titular, fuente FROM (
              SELECT r.*, ROW_NUMBER() OVER (PARTITION BY cum ORDER BY prioridad_cum, prioridad_reg, id) AS rn
                FROM ref_invima r WHERE carga_id = ? AND cum IS NOT NULL) t WHERE rn = 1")->execute([$cargaId]);
        $pdo->prepare("INSERT INTO ref_invima_registro (carga_id, registro_sanitario, estado_registro, fecha_vencimiento, producto, titular, grupo, fuente)
            SELECT carga_id, registro_sanitario, estado_registro, fecha_vencimiento, producto, titular, grupo, fuente FROM (
              SELECT r.*, ROW_NUMBER() OVER (PARTITION BY registro_sanitario ORDER BY prioridad_reg, CASE WHEN fuente = 'RSNSO' THEN 0 ELSE 1 END, id) AS rn
                FROM ref_invima r WHERE carga_id = ? AND registro_sanitario IS NOT NULL) t WHERE rn = 1")->execute([$cargaId]);
        $nCum = (int) $pdo->query("SELECT COUNT(*) FROM ref_invima_cum WHERE carga_id = $cargaId")->fetchColumn();
        $nReg = (int) $pdo->query("SELECT COUNT(*) FROM ref_invima_registro WHERE carga_id = $cargaId")->fetchColumn();

        // Intercambio: la nueva versión queda vigente; la anterior deja de estarlo.
        $version = (int) $pdo->query("SELECT COALESCE(MAX(version), 0) + 1 FROM ref_carga WHERE tipo_base = 'INVIMA'")->fetchColumn();
        $pdo->exec("UPDATE ref_carga SET vigente = 0 WHERE tipo_base = 'INVIMA' AND id <> $cargaId");
        $resumen = ['versiones_fuente' => $remotas, 'totales_fuente' => $totales, 'descargados' => $porFuente,
                    'cum_consolidados' => $nCum, 'registros_consolidados' => $nReg, 'segundos' => (int) (microtime(true) - $ini),
                    'llave' => 'CUM (expediente-consecutivo) / registro sanitario'];
        $pdo->prepare("UPDATE ref_carga SET estado = 'LEIDA', vigente = 1, version = ?, filas = ?, leido_en = ?, detalle = ? WHERE id = ?")
            ->execute([$version, $nCum + $nReg, ahora(), json_encode($resumen, JSON_UNESCAPED_UNICODE), $cargaId]);
        auditar($usuarioId, 'INVIMA_ACTUALIZAR', 'ref_carga', $cargaId, null, $resumen);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare("UPDATE ref_carga SET estado = 'ERROR', detalle = ? WHERE id = ?")
            ->execute([json_encode(['error' => $e->getMessage(), 'descargados' => $leidos], JSON_UNESCAPED_UNICODE), $cargaId]);
        $pdo->prepare('DELETE FROM ref_invima WHERE carga_id = ?')->execute([$cargaId]);
        throw $e;
    }

    // Limpieza fuera de la transacción: área temporal y versiones anteriores del consolidado.
    _proceso($pdo, $procesoId, ['avance' => 99, 'mensaje' => 'Liberando espacio de versiones anteriores…']);
    $pdo->exec('DELETE FROM ref_invima');
    $pdo->exec("DELETE FROM ref_invima_cum WHERE carga_id <> $cargaId");
    $pdo->exec("DELETE FROM ref_invima_registro WHERE carga_id <> $cargaId");
    if (db_driver() === 'sqlite') {
        // SQLite no devuelve el espacio de lo borrado: se compacta (en MySQL/InnoDB no hace falta).
        _proceso($pdo, $procesoId, ['avance' => 99, 'mensaje' => 'Compactando la base local…']);
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $pdo->exec('VACUUM');
    }
    _proceso($pdo, $procesoId, ['estado' => 'OK', 'avance' => 100, 'terminado_en' => ahora(),
        'detalle' => json_encode($resumen, JSON_UNESCAPED_UNICODE),
        'mensaje' => sprintf('INVIMA actualizado: %s CUM y %s registros sanitarios (%d min).',
            number_format($nCum, 0, ',', '.'), number_format($nReg, 0, ',', '.'), max(1, (int) round($resumen['segundos'] / 60)))]);
    return $resumen;
}

/** Proceso en curso (evita dos descargas a la vez). Un proceso sin avance en 15 min se da por caído. */
function proceso_en_curso(PDO $pdo, string $tipo): ?array {
    $st = $pdo->prepare("SELECT * FROM sis_proceso WHERE tipo = ? AND estado = 'EN_CURSO' ORDER BY id DESC LIMIT 1");
    $st->execute([$tipo]);
    $p = $st->fetch() ?: null;
    if ($p && strtotime($p['actualizado_en']) < time() - 900) {
        _proceso($pdo, (int) $p['id'], ['estado' => 'ERROR', 'terminado_en' => ahora(), 'mensaje' => 'El proceso dejó de responder (sin avance en 15 minutos).']);
        return null;
    }
    return $p;
}


/**
 * Lanza un script de actualización (actualizar_*.php) en segundo plano (Windows o Linux) con su
 * registro en sis_proceso, y devuelve el id del proceso. Evita dos ejecuciones a la vez del mismo tipo.
 */
function proceso_lanzar(PDO $pdo, string $tipo, string $script, int $usuarioId, bool $forzar): int {
    if ($p = proceso_en_curso($pdo, $tipo)) throw new ErrorApi('Ya hay una actualización en curso (inició ' . $p['iniciado_en'] . ').', 409);
    $id = insertar($pdo, 'sis_proceso', ['tipo' => $tipo, 'estado' => 'EN_CURSO', 'avance' => 0, 'mensaje' => 'Iniciando…',
        'usuario_id' => $usuarioId, 'iniciado_en' => ahora(), 'actualizado_en' => ahora()]);
    $php = app_config()['php_cli'] ?: (PHP_SAPI === 'cli' || preg_match('/^php(\d[\d.]*)?(\.exe)?$/i', basename(PHP_BINARY)) ? PHP_BINARY : 'php');
    $opts = '-d memory_limit=1024M -d max_execution_time=0';
    // PHP portátil de Windows (prueba local): las extensiones se cargan por línea de comandos
    if (PHP_OS_FAMILY === 'Windows') $opts = sprintf('-d extension_dir="%s" -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=curl -d extension=openssl -d extension=mbstring -d extension=zip ', ini_get('extension_dir')) . $opts;
    $ruta = realpath(__DIR__ . '/../' . $script);
    $args = sprintf('--proceso=%d --usuario=%d%s', $id, $usuarioId, $forzar ? ' --forzar' : '');
    $log = realpath(__DIR__ . '/../data') . DIRECTORY_SEPARATOR . strtolower($tipo) . '_ultimo.log';
    // El proceso hijo hereda el entorno (p.ej. CI_SQLITE_PATH en pruebas).
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen(sprintf('start "" /B "%s" %s "%s" %s > "%s" 2>&1', $php, $opts, $ruta, $args, $log), 'r'));
    } else {
        exec(sprintf('nohup %s %s %s %s > %s 2>&1 &', escapeshellarg($php), $opts, escapeshellarg($ruta), $args, escapeshellarg($log)));
    }
    auditar($usuarioId, $tipo . '_LANZAR', 'sis_proceso', $id, null, ['forzar' => $forzar]);
    return $id;
}
