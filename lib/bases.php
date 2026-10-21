<?php
/**
 * BASES EXTERNAS — estado y cargue manual con control de versiones.
 *
 * Fase 2: el archivo se recibe, se verifica (tipo, tamaño, que abra como Excel, que no esté
 * repetido) y se guarda como versión con trazabilidad. El mapeo de columnas a tablas
 * (ref_maestro_siesa, ref_listado_capita, ...) se implementa en la fase 3 por cada base.
 */
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/lectores_bases.php';
require_once __DIR__ . '/xlsx_writer.php'; // plantillas de cargue y exportaciones

const DIR_BASES = __DIR__ . '/../data/bases';
/** Bases que el aplicativo descarga solo (botón «Actualizar ahora» / tarea programada) y su script. */
const BASES_DESCARGA_AUTOMATICA = ['INVIMA', 'MEDICAMENTOS_MUTUAL'];
const SCRIPTS_DESCARGA = ['INVIMA' => 'actualizar_invima.php', 'MEDICAMENTOS_MUTUAL' => 'actualizar_mutual.php'];

/** Estado de todas las bases activas con su versión vigente (una consulta por conjunto). */
/** Bases que traen precios o tarifas: sin el permiso PRECIO.VER no se consultan sus datos ni se descargan. */
const BASES_CON_PRECIOS = ['LISTADO_CAPITA', 'CONTRATO_EVENTO_22382', 'MEDICAMENTOS_MUTUAL', 'PRECIOS_REGULADOS', 'LISTADO_CONSORCIO'];

function bases_estado(PDO $pdo): array {
    $bases = $pdo->query('SELECT * FROM ref_base WHERE activo = 1 ORDER BY orden')->fetchAll();
    $vigentes = $pdo->query(
        "SELECT c.tipo_base, c.id, c.version, c.origen, c.nombre_version, COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)) AS vigente_desde,
                c.filas, c.omitidas, c.estado, c.creado_en, c.leido_en, c.tamano_bytes, c.detalle, u.nombre AS usuario
           FROM ref_carga c LEFT JOIN seg_usuario u ON u.id = c.usuario_id
          WHERE c.vigente = 1")->fetchAll();
    $porBase = [];
    foreach ($vigentes as $v) {
        $d = json_decode($v['detalle'] ?? '', true) ?: [];
        $v['hojas'] = $d['hojas'] ?? [];
        $v['duplicados'] = (int) ($d['duplicados'] ?? 0);
        $v['error'] = $d['error'] ?? null;
        $v['llave'] = $d['llave'] ?? null;
        // Excel con varias hojas: de dónde se leyó (p.ej. la Maestra Tarifaria está en «EVENTO 2025», encabezados en la fila 5)
        $v['hoja_leida'] = ($d['hoja_leida'] ?? 'CSV') !== 'CSV' && count($v['hojas']) > 1 ? $d['hoja_leida'] . ' (encabezados en la fila ' . ($d['fila_encabezado'] ?? 1) . ')' : null;
        $v['detalle_omitidas'] = array_slice($d['detalle_omitidas'] ?? [], 0, 20);
        $v['detalle_duplicados'] = array_slice($d['detalle_duplicados'] ?? [], 0, 20);
        unset($v['detalle']);
        $porBase[$v['tipo_base']] = $v;
    }
    $versiones = $pdo->query('SELECT tipo_base, COUNT(*) AS n FROM ref_carga GROUP BY tipo_base')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($bases as &$b) {
        $b['vigente'] = $porBase[$b['codigo']] ?? null;
        $b['cargas'] = (int) ($versiones[$b['codigo']] ?? 0);
        $b['lectura'] = (bool) lector_definicion($b['codigo']);
        $b['consultable'] = (bool) base_consulta_def($b['codigo']);
        $b['descarga_automatica'] = in_array($b['codigo'], BASES_DESCARGA_AUTOMATICA, true); // el aplicativo las descarga solo
        // Alerta A07: base con más días sin actualizar que los permitidos.
        $b['dias_sin_actualizar'] = $b['vigente'] ? (int) floor((time() - strtotime($b['vigente']['creado_en'])) / 86400) : null;
        $b['desactualizada'] = $b['vigente'] && $b['dias_sin_actualizar'] > (int) $b['dias_alerta'];
        $b['con_precios'] = in_array($b['codigo'], BASES_CON_PRECIOS, true); // sin PRECIO.VER no se ven sus datos ni archivos
        // Contrato Evento: el número sale del archivo vigente (columna NUMERO), no del nombre de la base
        if ($b['codigo'] === BASE_CONTRATO_EVENTO && $b['vigente']) {
            $b['numero_contrato'] = implode(', ', contrato_numeros($pdo, (int) $b['vigente']['id']));
            // el título es «Contrato Evento»; el número va como subtítulo (decisión del usuario, 2026-10-13)
        }
    }
    return $bases;
}

/** Código interno de la base del contrato de evento (no se cambia: lo usan las cargas guardadas). */
const BASE_CONTRATO_EVENTO = 'CONTRATO_EVENTO_22382';

/** Números de contrato que trae una carga del Contrato Evento. */
function contrato_numeros(PDO $pdo, int $cargaId): array {
    $st = $pdo->prepare('SELECT DISTINCT numero_contrato FROM ref_contrato_evento WHERE carga_id = ? AND numero_contrato IS NOT NULL ORDER BY numero_contrato');
    $st->execute([$cargaId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Antes de cargar un Contrato Evento: lee el número del archivo y lo compara con el vigente para saber si es una
 * ACTUALIZACION del mismo contrato o un contrato NUEVO (otro número). No guarda nada.
 */
function contrato_revisar(PDO $pdo, string $ruta, string $nombre): array {
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $nums = [];
    foreach (leer_archivo_base($ruta, $ext, 'PRODUCTO', lector_definicion(BASE_CONTRATO_EVENTO)) as $f) {
        $n = codigo_excel($f['NUMERO'] ?? '');
        if ($n !== '') $nums[$n] = true;
    }
    if (!$nums) throw new ErrorApi('El archivo no trae la columna NUMERO (número del contrato) con datos.', 422);
    $nuevo = array_map('strval', array_keys($nums)); sort($nuevo);
    $st = $pdo->prepare('SELECT MAX(id) FROM ref_carga WHERE tipo_base = ? AND vigente = 1');
    $st->execute([BASE_CONTRATO_EVENTO]);
    $vig = (int) $st->fetchColumn();
    $actual = $vig ? contrato_numeros($pdo, $vig) : [];
    $tipo = !$actual ? 'PRIMERO' : ($nuevo == $actual ? 'ACTUALIZACION' : 'NUEVO');
    return ['numeros' => $nuevo, 'actual' => $actual, 'tipo' => $tipo];
}

function base_definicion(PDO $pdo, string $codigo): array {
    $st = $pdo->prepare('SELECT * FROM ref_base WHERE codigo = ? AND activo = 1');
    $st->execute([$codigo]);
    $b = $st->fetch();
    if (!$b) throw new ErrorApi('Base no encontrada.', 404);
    return $b;
}

function base_historial(PDO $pdo, string $codigo): array {
    base_definicion($pdo, $codigo);
    $st = $pdo->prepare(
        "SELECT c.id, c.version, c.origen, c.nombre_version, c.estado, c.vigente, c.filas, c.archivo, c.tamano_bytes, c.hash_sha256, c.creado_en, c.detalle,
                COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)) AS desde, c.vigente_hasta AS hasta_digitada, u.nombre AS usuario
           FROM ref_carga c LEFT JOIN seg_usuario u ON u.id = c.usuario_id
          WHERE c.tipo_base = ? ORDER BY COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)) DESC, c.id DESC LIMIT 100");
    $st->execute([$codigo]);
    $filas = $st->fetchAll();
    // Vigencia: desde su fecha de ejecución hasta que empieza la versión siguiente (las cargas con error no cuentan); la más reciente, hasta hoy
    $hasta = null;
    foreach ($filas as &$f) {
        $valida = !in_array($f['estado'], ['ERROR', 'RECIBIDA', 'PREVISUALIZADA'], true);
        $f['vigente_desde'] = $valida ? $f['desde'] : null;
        $f['vigente_hasta'] = $valida ? ($f['hasta_digitada'] ?: $hasta) : null;
        $f['hasta_es_digitada'] = (bool) $f['hasta_digitada'];
        if ($valida) $hasta = $f['desde'];
        $d = json_decode($f['detalle'] ?? '', true) ?: [];
        $f['cambios'] = $d['cambios'] ?? null;
        $f['descargable'] = $f['archivo'] && is_file(__DIR__ . '/../' . $f['archivo']);
        unset($f['archivo']);
    }
    unset($f);
    foreach ($filas as &$f) {
        $f['hojas'] = json_decode($f['detalle'] ?? '', true)['hojas'] ?? [];
        $c = json_decode($f['detalle'] ?? '', true)['contrato'] ?? null;
        if ($codigo === BASE_CONTRATO_EVENTO) $f['contrato'] = $c ? implode(', ', $c['numeros']) . match ($c['tipo']) { 'NUEVO' => ' (contrato nuevo)', 'ACTUALIZACION' => ' (actualización)', default => '' }
            : implode(', ', contrato_numeros($pdo, (int) $f['id']));
        $f['hash_corto'] = substr((string) $f['hash_sha256'], 0, 12);
        unset($f['detalle'], $f['hash_sha256']);
    }
    return $filas;
}

/** Ruta del archivo de una versión (solo dentro de la carpeta de bases). */
function base_version_ruta(PDO $pdo, int $cargaId): array {
    $st = $pdo->prepare('SELECT c.tipo_base, c.version, c.archivo, b.nombre FROM ref_carga c JOIN ref_base b ON b.codigo = c.tipo_base WHERE c.id = ?');
    $st->execute([$cargaId]);
    $c = $st->fetch();
    $ruta = $c && $c['archivo'] ? realpath(__DIR__ . '/../' . $c['archivo']) : false;
    if (!$ruta || !str_starts_with($ruta, realpath(__DIR__ . '/../data/bases'))) throw new ErrorApi('El archivo de esa versión no está disponible (las versiones anteriores al historial no se conservaron).', 404);
    return $c + ['ruta' => $ruta];
}

/** Excel con los cambios de una versión frente a la anterior (nuevos, retirados y modificados campo por campo). */
function base_cambios_exportar(PDO $pdo, int $cargaId, string $destino): string {
    $st = $pdo->prepare('SELECT c.tipo_base, c.version, c.creado_en, b.nombre, a.version AS version_anterior, a.creado_en AS fecha_anterior
                           FROM ref_carga c JOIN ref_base b ON b.codigo = c.tipo_base
                           LEFT JOIN ref_carga a ON a.id = (SELECT MAX(carga_anterior_id) FROM ref_carga_cambio WHERE carga_id = c.id)
                          WHERE c.id = ?');
    $st->execute([$cargaId]);
    $c = $st->fetch();
    if (!$c) throw new ErrorApi('Versión no encontrada.', 404);
    $def = lector_definicion($c['tipo_base']);
    $nombreCol = []; foreach ($def['columnas'] ?? [] as $k => $x) $nombreCol[$k] = $x[0][0];
    $filas = (function () use ($pdo, $cargaId, $nombreCol) {
        $st = $pdo->prepare('SELECT tipo, llave, campo, antes, despues FROM ref_carga_cambio WHERE carga_id = ? ORDER BY tipo, llave, id');
        $st->execute([$cargaId]);
        foreach ($st as $r) yield [$r['tipo'], $r['llave'], $r['campo'] ? ($nombreCol[$r['campo']] ?? $r['campo']) : null, $r['antes'], $r['despues']];
    })();
    $llave = str_replace('|', ' + ', strtoupper($def['llave'] ?? 'LLAVE'));
    XlsxWriter::guardar($destino, [['nombre' => 'CAMBIOS', 'encabezados' => ['TIPO', $llave, 'CAMPO', 'ANTES', 'DESPUÉS'], 'filas' => $filas]]);
    return "{$c['nombre']} - cambios v{$c['version_anterior']} a v{$c['version']} - " . substr($c['creado_en'], 0, 10) . '.xlsx';
}

/** Recibe un archivo subido ($_FILES['archivo']) para una base MANUAL. */
/** Valida una fecha AAAA-MM-DD (no futura); null si viene vacía. */
function _base_fecha(?string $v, string $nombre): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) throw new ErrorApi("La «{$nombre}» no es válida.", 422);
    if ($v > date('Y-m-d')) throw new ErrorApi("La «{$nombre}» no puede ser futura.", 422);
    return $v;
}

/** Deja como vigente la versión leída con la fecha de inicio más reciente (la que rige hoy). */
function base_recalcular_vigente(PDO $pdo, string $codigo): void {
    $st = $pdo->prepare("SELECT id FROM ref_carga WHERE tipo_base = ? AND estado IN ('LEIDA', 'APLICADA') ORDER BY COALESCE(vigente_desde, SUBSTR(creado_en, 1, 10)) DESC, id DESC LIMIT 1");
    $st->execute([$codigo]);
    $id = (int) $st->fetchColumn();
    if (!$id) return;
    $pdo->prepare('UPDATE ref_carga SET vigente = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE tipo_base = ?')->execute([$id, $codigo]);
}

/**
 * Corrige el nombre y la vigencia de una versión ya cargada (pedido del usuario, 2026-10-19): fecha de inicio y fecha final.
 * Se recalcula cuál es la vigente y los «cambios vs. anterior» de esta versión y de la siguiente.
 */
function base_version_editar(PDO $pdo, int $cargaId, array $in, int $usuarioId): array {
    $st = $pdo->prepare('SELECT c.*, b.guarda_versiones FROM ref_carga c JOIN ref_base b ON b.codigo = c.tipo_base WHERE c.id = ?');
    $st->execute([$cargaId]);
    $c = $st->fetch();
    if (!$c) throw new ErrorApi('La versión no existe.', 404);
    if (!(int) $c['guarda_versiones']) throw new ErrorApi('Esta base no guarda versiones: su vigencia es la fecha de cargue.', 422);
    $desde = _base_fecha($in['vigente_desde'] ?? null, 'fecha inicio') ?? throw new ErrorApi('Indique la fecha inicio de la versión.', 422);
    $hasta = _base_fecha($in['vigente_hasta'] ?? null, 'fecha final');
    if ($hasta !== null && $hasta < $desde) throw new ErrorApi('La fecha final no puede ser anterior a la fecha inicio.', 422);
    $nombre = mb_substr(trim((string) ($in['nombre_version'] ?? '')), 0, 150) ?: null;
    $antes = ['nombre_version' => $c['nombre_version'], 'vigente_desde' => $c['vigente_desde'], 'vigente_hasta' => $c['vigente_hasta']];
    $despues = ['nombre_version' => $nombre, 'vigente_desde' => $desde, 'vigente_hasta' => $hasta];
    $pdo->prepare('UPDATE ref_carga SET nombre_version = ?, vigente_desde = ?, vigente_hasta = ? WHERE id = ?')->execute([$nombre, $desde, $hasta, $cargaId]);
    base_recalcular_vigente($pdo, $c['tipo_base']);
    // con otra fecha cambia el orden: se recalculan los cambios de esta versión y de la que ahora le sigue
    if ($desde !== $c['vigente_desde'] && ($def = lector_definicion($c['tipo_base'])) && in_array($c['tipo_base'], BASES_CON_CAMBIOS, true)) {
        $cols = array_keys($def['columnas']);
        $st = $pdo->prepare('SELECT carga_id, ' . implode(', ', $cols) . " FROM {$def['tabla']} WHERE carga_id = ?");
        $st->execute([$cargaId]);
        $cambios = base_registrar_cambios($pdo, $c['tipo_base'], $cargaId, array_map('array_values', $st->fetchAll()));
        $det = json_decode((string) $c['detalle'], true) ?: []; $det['cambios'] = $cambios;
        $pdo->prepare('UPDATE ref_carga SET detalle = ? WHERE id = ?')->execute([json_encode($det, JSON_UNESCAPED_UNICODE), $cargaId]);
        base_recalcular_cambios_siguiente($pdo, $c['tipo_base'], $cargaId);
    }
    auditar($usuarioId, 'BASE_VERSION_EDITAR', 'ref_carga', $cargaId, $antes, $despues);
    return ['ok' => true];
}

function base_cargar_manual(PDO $pdo, string $codigo, array $archivo, int $usuarioId, ?string $nombreVersion = null, ?string $vigenteDesde = null, ?string $vigenteHasta = null): array {
    $base = base_definicion($pdo, $codigo);
    // Versiones históricas (2026-10-19): cada versión rige desde su fecha de ejecución hasta que empieza la siguiente; la de fecha más
    // reciente es la vigente. En las bases que reemplazan la anterior, la fecha es siempre la de hoy.
    $nombreVersion = mb_substr(trim((string) $nombreVersion), 0, 150) ?: null;
    $desde = (int) $base['guarda_versiones'] && trim((string) $vigenteDesde) !== '' ? trim((string) $vigenteDesde) : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !checkdate((int) substr($desde, 5, 2), (int) substr($desde, 8, 2), (int) substr($desde, 0, 4))) throw new ErrorApi('La «fecha inicio» no es válida.', 422);
    if ($desde > date('Y-m-d')) throw new ErrorApi('La «fecha inicio» no puede ser futura.', 422);
    $st = $pdo->prepare("SELECT MAX(COALESCE(vigente_desde, SUBSTR(creado_en, 1, 10))) FROM ref_carga WHERE tipo_base = ? AND estado IN ('LEIDA', 'REEMPLAZADA', 'APLICADA')");
    $st->execute([$codigo]);
    $maxDesde = $st->fetchColumn() ?: null;
    $esVigente = !(int) $base['guarda_versiones'] || $maxDesde === null || $desde >= $maxDesde; // una versión más antigua queda en el historial
    $hasta = (int) $base['guarda_versiones'] ? _base_fecha($vigenteHasta, 'fecha final') : null; // fecha final opcional
    if ($hasta !== null && $hasta < $desde) throw new ErrorApi('La fecha final no puede ser anterior a la fecha inicio.', 422);
    if ($base['modo'] !== 'MANUAL') {
        throw new ErrorApi("La base {$base['nombre']} se actualiza automáticamente (fase {$base['fase_automatica']}); no admite cargue manual.", 422);
    }

    // --- verificaciones del archivo
    $err = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new ErrorApi('El archivo supera el tamaño permitido por el servidor.', 413);
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) throw new ErrorApi('No se recibió el archivo. Intente de nuevo.', 422);
    $maxMb = (int) parametro('BASE_TAMANO_MAX_MB', '200');
    if ($archivo['size'] > $maxMb * 1024 * 1024) throw new ErrorApi("El archivo supera $maxMb MB.", 413);
    $nombre = basename((string) $archivo['name']);
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $permitidas = array_map('trim', explode(',', $base['extensiones']));
    if (!in_array($ext, $permitidas, true)) throw new ErrorApi('Tipo de archivo no permitido. Use: ' . implode(', ', $permitidas), 422);

    $hash = hash_file('sha256', $archivo['tmp_name']);
    $st = $pdo->prepare('SELECT version, creado_en FROM ref_carga WHERE tipo_base = ? AND hash_sha256 = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$codigo, $hash]);
    if ($previo = $st->fetch()) {
        throw new ErrorApi("Este mismo archivo ya se cargó como versión {$previo['version']} el {$previo['creado_en']}. No se vuelve a cargar.", 409);
    }

    $hojas = [];
    if ($ext === 'csv') {
        $hojas = ['CSV'];
    } else {
        try { $hojas = (new XlsxReader($archivo['tmp_name']))->hojas(); }
        catch (Throwable) { throw new ErrorApi('El archivo no se pudo abrir como Excel (.xlsx). Verifique que no esté dañado ni protegido con contraseña.', 422); }
    }

    // --- guardar
    $st = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM ref_carga WHERE tipo_base = ?');
    $st->execute([$codigo]);
    $version = (int) $st->fetchColumn() + 1;
    $dir = DIR_BASES . '/' . $codigo;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("No se pudo crear $dir");
    $destino = sprintf('%s/v%03d_%s.%s', $dir, $version, date('Ymd_His'), $ext);
    if (!move_uploaded_file($archivo['tmp_name'], $destino)) throw new RuntimeException('No se pudo guardar el archivo.');
    $relativo = substr($destino, strlen(realpath(__DIR__ . '/..')) + 1);

    $reemplazados = [];
    $pdo->beginTransaction();
    try {
        if ($esVigente && !(int) $base['guarda_versiones']) {
            $st = $pdo->prepare("SELECT id, archivo FROM ref_carga WHERE tipo_base = ? AND archivo IS NOT NULL");
            $st->execute([$codigo]);
            $reemplazados = $st->fetchAll();
            // el archivo anterior se conserva (historial de versiones por fecha); sus datos se reemplazan
            $pdo->prepare("UPDATE ref_carga SET estado = 'REEMPLAZADA' WHERE tipo_base = ? AND vigente = 1")->execute([$codigo]);
        }
        if ($esVigente) $pdo->prepare('UPDATE ref_carga SET vigente = 0 WHERE tipo_base = ?')->execute([$codigo]);
        $id = insertar($pdo, 'ref_carga', [
            'tipo_base' => $codigo, 'origen' => $nombre, 'hash_sha256' => $hash, 'filas' => 0,
            'estado' => 'RECIBIDA', 'vigente' => $esVigente ? 1 : 0, 'version' => $version, 'archivo' => str_replace('\\', '/', $relativo),
            'nombre_version' => $nombreVersion ?? pathinfo($nombre, PATHINFO_FILENAME), 'vigente_desde' => $desde, 'vigente_hasta' => $hasta,
            'tamano_bytes' => (int) $archivo['size'], 'detalle' => json_encode(['hojas' => $hojas], JSON_UNESCAPED_UNICODE),
            'usuario_id' => $usuarioId, 'creado_en' => ahora(),
        ]);
        // Lectura a tabla en la MISMA transacción: si la estructura no corresponde se lanza ErrorApi,
        // se deshace todo (el archivo nuevo no queda vigente) y la versión anterior sigue en uso.
        $actualContrato = $codigo === BASE_CONTRATO_EVENTO ? (function () use ($pdo, $id, $codigo) {
            $st = $pdo->prepare('SELECT MAX(id) FROM ref_carga WHERE tipo_base = ? AND id < ? AND estado IN (\'LEIDA\', \'REEMPLAZADA\')');
            $st->execute([$codigo, $id]);
            $prev = (int) $st->fetchColumn();
            return $prev ? contrato_numeros($pdo, $prev) : [];
        })() : null;
        $lectura = lector_definicion($codigo) ? _registrar_lectura($pdo, $id, $codigo, $destino, $ext, (bool) $base['guarda_versiones'], $hojas) : null;
        if ($lectura && !$esVigente) base_recalcular_cambios_siguiente($pdo, $codigo, $id); // la versión siguiente ahora se compara con esta
        $contrato = null;
        if ($codigo === BASE_CONTRATO_EVENTO) {
            $nums = contrato_numeros($pdo, $id);
            $contrato = ['numeros' => $nums, 'anterior' => $actualContrato, 'tipo' => !$actualContrato ? 'PRIMERO' : ($nums == $actualContrato ? 'ACTUALIZACION' : 'NUEVO')];
            $st = $pdo->prepare('SELECT detalle FROM ref_carga WHERE id = ?'); $st->execute([$id]);
            $det = (json_decode((string) $st->fetchColumn(), true) ?: []) + ['contrato' => $contrato];
            $pdo->prepare('UPDATE ref_carga SET detalle = ? WHERE id = ?')->execute([json_encode($det, JSON_UNESCAPED_UNICODE), $id]);
        }
        auditar($usuarioId, 'BASE_CARGAR', 'ref_carga', $id, null, [
            'base' => $codigo, 'version' => $version, 'archivo' => $nombre, 'hash' => $hash, 'hojas' => $hojas,
            'nombre_version' => $nombreVersion, 'vigente_desde' => $desde, 'vigente' => $esVigente,
            'reemplaza' => array_column($reemplazados, 'id'), 'filas_leidas' => $lectura['filas'] ?? null,
        ] + ($contrato ? ['contrato' => $contrato] : []));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        @unlink($destino);
        throw $e;
    }
    // Los archivos anteriores se conservan para el historial de versiones (vigencia por fecha y descarga).
    return ['id' => $id, 'version' => $version, 'hojas' => $hojas, 'lectura' => $lectura, 'contrato' => $contrato, 'vigente' => $esVigente, 'vigente_desde' => $desde];
}

/** Lee el archivo a su tabla y deja el resultado en la carga (estado LEIDA, filas, omitidas, detalle). */
function _registrar_lectura(PDO $pdo, int $cargaId, string $codigo, string $ruta, string $ext, bool $guardaVersiones, array $hojas): array {
    $ini = microtime(true);
    $r = lector_procesar($pdo, $codigo, $ruta, $ext, $cargaId, $guardaVersiones);
    $r['segundos'] = round(microtime(true) - $ini, 1);
    $pdo->prepare("UPDATE ref_carga SET estado = 'LEIDA', filas = ?, omitidas = ?, leido_en = ?, detalle = ? WHERE id = ?")
        ->execute([$r['filas'], $r['omitidas'], ahora(), json_encode(['hojas' => $hojas] + $r, JSON_UNESCAPED_UNICODE), $cargaId]);
    return $r;
}

/**
 * Lee la versión vigente ya cargada (p.ej. archivos recibidos antes de que existiera la lectura).
 * Si falla, la carga queda en estado ERROR con el motivo y no se cargan filas.
 */
function base_procesar_vigente(PDO $pdo, string $codigo, int $usuarioId): array {
    $base = base_definicion($pdo, $codigo);
    if (!lector_definicion($codigo)) throw new ErrorApi("La base {$base['nombre']} no se lee desde archivo.", 422);
    $st = $pdo->prepare('SELECT * FROM ref_carga WHERE tipo_base = ? AND vigente = 1 ORDER BY id DESC LIMIT 1');
    $st->execute([$codigo]);
    $c = $st->fetch();
    if (!$c || !$c['archivo']) throw new ErrorApi('No hay archivo vigente para leer. Cárguelo primero.', 404);
    $ruta = realpath(__DIR__ . '/..') . '/' . $c['archivo'];
    if (!is_file($ruta)) throw new ErrorApi('El archivo vigente no se encuentra en el servidor. Cárguelo de nuevo.', 404);
    $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
    $hojas = json_decode((string) $c['detalle'], true)['hojas'] ?? [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ' . lector_definicion($codigo)['tabla'] . ' WHERE carga_id = ?')->execute([$c['id']]);
        $r = _registrar_lectura($pdo, (int) $c['id'], $codigo, $ruta, $ext, (bool) $base['guarda_versiones'], $hojas);
        auditar($usuarioId, 'BASE_LEER', 'ref_carga', $c['id'], null, ['base' => $codigo, 'version' => $c['version'], 'filas' => $r['filas'], 'omitidas' => $r['omitidas']]);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $motivo = $e instanceof ErrorApi ? $e->getMessage() : 'Error interno al leer el archivo.';
        $pdo->prepare("UPDATE ref_carga SET estado = 'ERROR', detalle = ? WHERE id = ?")
            ->execute([json_encode(['hojas' => $hojas, 'error' => $motivo], JSON_UNESCAPED_UNICODE), $c['id']]);
        throw $e;
    }
}

/**
 * Datos leídos de una base (versión vigente), paginados y con filtros por columna.
 * $filtrosSql: [condiciones, parámetros] ya validados contra las columnas de la base.
 */
/**
 * Plantilla de cargue de una base manual (pedido del usuario, 2026-10-19): la hoja de datos con los encabezados exactos que lee el
 * aplicativo y la hoja INSTRUCCIONES con cada columna (obligatoria, tipo, otros nombres aceptados) y la llave de la base.
 */
function base_plantilla(PDO $pdo, string $codigo, string $destino): string {
    $base = base_definicion($pdo, $codigo);
    $def = lector_definicion($codigo);
    if (!$def) throw new ErrorApi("La base {$base['nombre']} todavía no tiene una estructura de lectura definida.", 404);
    // nombre legible de un encabezado aceptado (algunos son patrones, p.ej. «PRECIO DE VENTA 2026»)
    $legible = static function (string $a): string {
        if ($a[0] !== '/') return $a;
        $t = preg_replace('~^/\^?|\$?/[a-z]*$~', '', $a);           // delimitadores del patrón
        $t = preg_replace('~\(.*?\)\??~', '', $t);                 // partes opcionales, p.ej. el año
        $t = str_replace('\d{4}', date('Y'), $t);                 // año obligatorio: el actual (p.ej. «PRECIO UNITARIO 2026»)
        $t = str_replace(['\s*', '\s+', '\s', '\+', '\\'], [' ', ' ', ' ', '+', ''], $t);
        return trim(preg_replace('~ +~', ' ', $t));
    };
    $tipos = ['texto' => 'Texto', 'codigo' => 'Código (se guarda tal cual, sin decimales)', 'numero' => 'Número', 'fecha' => 'Fecha (AAAA-MM-DD o fecha de Excel)'];
    $encabezados = []; $instr = [];
    foreach ($def['columnas'] as $campo => $c) {
        $aceptados = array_map($legible, $c[0]);
        $encabezados[] = $aceptados[0];
        $instr[] = [$aceptados[0], $c[2] === true ? 'SÍ' : ($c[2] === 'columna' ? 'La columna debe existir (la celda puede ir vacía)' : 'No'),
                    $tipos[$c[1]] ?? $c[1], count($aceptados) > 1 ? implode(' · ', array_slice($aceptados, 1)) : '', isset($c[3]) ? implode(', ', $c[3]) . ' = vacío' : ''];
    }
    $llave = implode(' + ', array_map(fn($k) => $legible($def['columnas'][$k][0][0] ?? $k), explode('|', $def['llave'])));
    $hoja = !empty($def['hoja']) ? trim(preg_replace('~^/\^?|/[a-z]*$~', '', $def['hoja'])) : 'DATOS';
    XlsxWriter::guardar($destino, [
        ['nombre' => $hoja ?: 'DATOS', 'encabezados' => $encabezados, 'filas' => []],
        ['nombre' => 'INSTRUCCIONES', 'encabezados' => ['COLUMNA', 'OBLIGATORIA', 'TIPO DE DATO', 'TAMBIÉN SE ACEPTA COMO', 'VALORES QUE SIGNIFICAN VACÍO'], 'filas' => [
            ...$instr, [], ['Base', $base['nombre']], ['Llave (fila única)', $llave . ($def['descartar_duplicados'] ?? false ? ' — las filas repetidas no se guardan' : ' — si se repite, se avisa')],
            ['Hoja', 'Se lee la hoja «' . ($hoja ?: 'la primera') . '» (o la primera); los encabezados pueden estar en cualquiera de las primeras 30 filas'],
            ['Columnas', 'El orden no importa; puede haber columnas adicionales (no se leen). Si falta una obligatoria, el archivo se rechaza y sigue la versión vigente'],
            ['Versiones', (int) $base['guarda_versiones'] ? 'Al cargar indique el nombre, la fecha inicio y, si aplica, la fecha final de la versión' : 'Cada cargue reemplaza la versión anterior']]],
    ]);
    return "PLANTILLA - {$base['nombre']}.xlsx";
}

/** Tabla y columnas consultables de una base (las manuales vienen del lector; INVIMA del consolidado). */
function base_consulta_def(string $codigo, string $vista = ''): ?array {
    if ($codigo === 'INVIMA') {
        return $vista === 'registro'
            ? ['tabla' => 'ref_invima_registro', 'columnas' => array_flip(['registro_sanitario', 'estado_registro', 'fecha_vencimiento', 'producto', 'titular', 'grupo', 'fuente'])]
            : ['tabla' => 'ref_invima_cum', 'columnas' => array_flip(['cum', 'estado_cum', 'registro_sanitario', 'estado_registro', 'producto', 'titular', 'fuente'])];
    }
    return lector_definicion($codigo);
}

function base_datos(PDO $pdo, string $codigo, int $pagina, int $tam, array $cond, array $params, string $vista = ''): array {
    $def = base_consulta_def($codigo, $vista);
    if (!$def) throw new ErrorApi('Base sin datos leídos.', 404);
    $st = $pdo->prepare('SELECT id FROM ref_carga WHERE tipo_base = ? AND vigente = 1 ORDER BY id DESC LIMIT 1');
    $st->execute([$codigo]);
    $carga = (int) $st->fetchColumn();
    $cols = implode(', ', array_keys($def['columnas']));
    $where = 'WHERE carga_id = ?' . ($cond ? ' AND ' . implode(' AND ', $cond) : '');
    $p = [$carga, ...$params];
    $st = $pdo->prepare("SELECT COUNT(*) FROM {$def['tabla']} $where");
    $st->execute($p);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT $cols FROM {$def['tabla']} $where ORDER BY id LIMIT $tam OFFSET " . (($pagina - 1) * $tam));
    $st->execute($p);
    return ['total' => $total, 'pagina' => $pagina, 'tam' => $tam, 'filas' => $st->fetchAll(), 'columnas' => array_keys($def['columnas'])];
}

/** Días máximos sin actualizar antes de la alerta de base desactualizada (solo Administrador). */
function base_config_guardar(PDO $pdo, string $codigo, int $dias, int $usuarioId): array {
    $antes = base_definicion($pdo, $codigo);
    if ($dias < 1 || $dias > 365) throw new ErrorApi('Los días deben estar entre 1 y 365.', 422);
    $pdo->prepare('UPDATE ref_base SET dias_alerta = ? WHERE codigo = ?')->execute([$dias, $codigo]);
    auditar($usuarioId, 'BASE_CONFIG', 'ref_base', $codigo, ['dias_alerta' => (int) $antes['dias_alerta']], ['dias_alerta' => $dias]);
    return base_definicion($pdo, $codigo);
}

