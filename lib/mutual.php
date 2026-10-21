<?php
/**
 * MAESTRA MUTUAL SER AUTOMÁTICA (fase 4) — reemplaza el script "MaestraMutualser.R".
 *
 * Igual que el script: descarga el Excel compartido por Mutual Ser (enlace de SharePoint del parámetro
 * MUTUAL_MAESTRA_URL, con "&download=1") y lee la hoja "Consulta de medicamentos". Además:
 *  - si el archivo no cambió desde la última descarga (misma huella), no se vuelve a leer;
 *  - la versión anterior sigue vigente mientras se lee; si algo falla, se conserva y queda el error;
 *  - si el enlace deja de entregar el Excel (permisos o enlace vencido), se informa con claridad.
 * Es la relación CUM <-> ID MUTUAL que usa la Maestra Tarifaria (validación "CUM y código").
 */
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/bases.php';
require_once __DIR__ . '/invima.php'; // _proceso(), proceso_en_curso(), proceso_lanzar()

const MUTUAL_HOJA = 'Consulta de medicamentos';

/** Descarga el Excel a $destino. Conserva las cookies entre redirecciones (SharePoint las exige). */
function mutual_descargar(string $url, string $destino, int $intentos = 3): void {
    if (!preg_match('#^https://[a-z0-9.-]+\.sharepoint\.com/#i', $url)) {
        throw new RuntimeException('El parámetro MUTUAL_MAESTRA_URL debe ser un enlace https de SharePoint.');
    }
    $url .= (str_contains($url, '?') ? '&' : '?') . 'download=1';
    $ultimo = '';
    for ($i = 1; $i <= $intentos; $i++) {
        $fh = fopen($destino, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10,
            CURLOPT_COOKIEFILE => '', CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 900]);
        if (PHP_OS_FAMILY === 'Windows') curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        $ok = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tipo = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $ultimo = curl_error($ch);
        fclose($fh);
        if ($ok && $codigo === 200 && str_contains($tipo, 'spreadsheetml')) return;
        if ($ok && $codigo === 200) {
            throw new RuntimeException('El enlace de Mutual Ser no entregó el Excel (respondió una página web). Verifique que el enlace siga compartido y vigente.');
        }
        $ultimo = $ultimo ?: "HTTP $codigo";
        sleep(3 * $i);
    }
    throw new RuntimeException("No fue posible descargar la maestra de Mutual Ser ($ultimo).");
}

function mutual_actualizar(PDO $pdo, int $procesoId, ?int $usuarioId, bool $forzar = false): array {
    $url = trim((string) parametro('MUTUAL_MAESTRA_URL', ''));
    if ($url === '') throw new RuntimeException('Falta el enlace de la maestra de Mutual Ser (parámetro MUTUAL_MAESTRA_URL).');
    $ini = microtime(true);
    _proceso($pdo, $procesoId, ['mensaje' => 'Descargando la maestra de Mutual Ser…', 'avance' => 5]);
    $tmp = tempnam(sys_get_temp_dir(), 'mutual');
    try {
        mutual_descargar($url, $tmp);
        $hash = hash_file('sha256', $tmp);
        $tam = filesize($tmp);
        try { $hojas = (new XlsxReader($tmp))->hojas(); } catch (Throwable) { throw new RuntimeException('El archivo descargado no abre como Excel.'); }
        if (!in_array(MUTUAL_HOJA, $hojas, true)) throw new RuntimeException('El archivo de Mutual Ser ya no trae la hoja «' . MUTUAL_HOJA . '». Hojas: ' . implode(', ', $hojas));

        $st = $pdo->prepare("SELECT id, hash_sha256, archivo FROM ref_carga WHERE tipo_base = 'MEDICAMENTOS_MUTUAL' AND vigente = 1 ORDER BY id DESC LIMIT 1");
        $st->execute();
        $vigente = $st->fetch() ?: null;
        $st->closeCursor();
        if (!$forzar && $vigente && $vigente['hash_sha256'] === $hash) {
            // Mismo archivo: se registra la verificación como actualización (la base está al día).
            $pdo->prepare('UPDATE ref_carga SET creado_en = ?, leido_en = ? WHERE id = ?')->execute([ahora(), ahora(), $vigente['id']]);
            auditar($usuarioId, 'MUTUAL_SIN_CAMBIOS', 'ref_carga', $vigente['id'], null, ['hash' => $hash]);
            _proceso($pdo, $procesoId, ['estado' => 'SIN_CAMBIOS', 'avance' => 100, 'terminado_en' => ahora(),
                'mensaje' => 'La maestra de Mutual Ser no cambió desde la última descarga: la base está al día.']);
            return ['sin_cambios' => true];
        }

        _proceso($pdo, $procesoId, ['mensaje' => sprintf('Leyendo la hoja «%s» (%.0f MB)…', MUTUAL_HOJA, $tam / 1048576), 'avance' => 30]);
        $st = $pdo->prepare("SELECT COALESCE(MAX(version), 0) FROM ref_carga WHERE tipo_base = 'MEDICAMENTOS_MUTUAL'");
        $st->execute();
        $version = (int) $st->fetchColumn() + 1;
        $st->closeCursor(); $st = null; // SQLite no compacta (VACUUM) con consultas abiertas
        $dir = DIR_BASES . '/MEDICAMENTOS_MUTUAL';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("No se pudo crear $dir");
        $destino = sprintf('%s/v%03d_%s.xlsx', $dir, $version, date('Ymd_His'));
        if (!copy($tmp, $destino)) throw new RuntimeException('No se pudo guardar el archivo descargado.');
        $relativo = 'data/bases/MEDICAMENTOS_MUTUAL/' . basename($destino);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE ref_carga SET estado = 'REEMPLAZADA', archivo = NULL WHERE tipo_base = 'MEDICAMENTOS_MUTUAL' AND vigente = 1")->execute();
            $pdo->prepare("UPDATE ref_carga SET vigente = 0 WHERE tipo_base = 'MEDICAMENTOS_MUTUAL'")->execute();
            $cargaId = insertar($pdo, 'ref_carga', ['tipo_base' => 'MEDICAMENTOS_MUTUAL', 'origen' => 'Mutual Ser (SharePoint)', 'hash_sha256' => $hash,
                'filas' => 0, 'estado' => 'RECIBIDA', 'vigente' => 1, 'version' => $version, 'archivo' => $relativo, 'tamano_bytes' => $tam,
                'detalle' => json_encode(['hojas' => $hojas], JSON_UNESCAPED_UNICODE), 'usuario_id' => $usuarioId, 'creado_en' => ahora()]);
            $lectura = _registrar_lectura($pdo, $cargaId, 'MEDICAMENTOS_MUTUAL', $destino, 'xlsx', false, $hojas);
            $st = null;
            auditar($usuarioId, 'MUTUAL_ACTUALIZAR', 'ref_carga', $cargaId, null, ['version' => $version, 'hash' => $hash, 'filas' => $lectura['filas']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            @unlink($destino);
            throw $e;
        }
        // Archivo anterior: se elimina del disco (queda el registro en auditoría).
        if ($vigente && $vigente['archivo'] && is_file(__DIR__ . '/../' . $vigente['archivo'])) @unlink(__DIR__ . '/../' . $vigente['archivo']);
        if (db_driver() === 'sqlite') {
            _proceso($pdo, $procesoId, ['mensaje' => 'Compactando la base local…', 'avance' => 95]);
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $pdo->exec('VACUUM');
        }
        $seg = (int) round(microtime(true) - $ini);
        _proceso($pdo, $procesoId, ['estado' => 'OK', 'avance' => 100, 'terminado_en' => ahora(),
            'detalle' => json_encode(['filas' => $lectura['filas'], 'omitidas' => $lectura['omitidas'], 'segundos' => $seg], JSON_UNESCAPED_UNICODE),
            'mensaje' => sprintf('Maestra Mutual Ser actualizada: %s filas (%d s).', number_format($lectura['filas'], 0, ',', '.'), $seg)]);
        return ['filas' => $lectura['filas'], 'omitidas' => $lectura['omitidas'], 'segundos' => $seg];
    } finally {
        @unlink($tmp);
    }
}
