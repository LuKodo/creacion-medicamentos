<?php
/**
 * corregir_precios_20261026.php — corrección de datos del ajuste de precios del 2026-10-26 (decisión del usuario).
 *
 * Solo hace falta si la base se trasladó ANTES de ese ajuste (base del 19-oct). Es seguro re-ejecutarlo: si no hay nada que
 * corregir, no cambia nada.
 *  1. Las líneas EVENTO / EVENTO SAS que se crearon con el precio del Contrato Evento (origen CONTRATO) pasan al precio del
 *     producto (su línea original), con el contrato como tope. Antes se guarda la Maestra Tarifaria como versión anterior.
 *  2. Se revisan las inconsistencias de MEDICAR con el precio nuevo (precio propio en EVENTO, peso entero hacia abajo en CAPITA
 *     y regulado) y se eliminan las de precio que se cierran en esa revisión: eran falsas, nada se corrigió en MEDICAR.
 *
 * Uso:  php corregir_precios_20261026.php              -> revisión: informa y no guarda nada
 *       php corregir_precios_20261026.php --confirmar  -> guarda (queda en auditoría)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/datos.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/bases.php';
require_once __DIR__ . '/lib/nota_tecnica.php';
require_once __DIR__ . '/lib/correcciones.php';

$confirmar = in_array('--confirmar', $argv, true);
$pdo = db();
$admin = (int) $pdo->query("SELECT u.id FROM seg_usuario u JOIN seg_usuario_perfil up ON up.usuario_id = u.id JOIN seg_perfil p ON p.id = up.perfil_id
                              WHERE p.codigo = 'ADMIN' AND u.activo = 1 ORDER BY u.id LIMIT 1")->fetchColumn();
if (!$admin) exit("No hay un Administrador activo." . PHP_EOL);

// Contrato Evento vigente: precio por ID MUTUAL y tipo de servicio (714 / 799), el primero si se repite (como nt_calcular)
$ctr = [];
$st = $pdo->prepare('SELECT id_mutual, cod_servicio, valor_unitario FROM ref_contrato_evento WHERE carga_id = ? ORDER BY id');
$st->execute([_carga_vigente($pdo, 'CONTRATO_EVENTO_22382')]);
foreach ($st as $r) $ctr[_u($r['id_mutual'])][(string) $r['cod_servicio']] ??= (float) $r['valor_unitario'];

$lineas = $pdo->query('SELECT * FROM nt_item ORDER BY id')->fetchAll();
$original = []; // línea original del producto (la primera que no tomó el precio del contrato)
foreach ($lineas as $l) if ($l['origen_precio'] !== 'CONTRATO' && $l['precio_venta'] !== null) $original[_u($l['cum_facturar'])] ??= $l;

$cambios = [];
$conteo = ['revisadas' => 0, 'cambian' => 0, 'quedan_en_el_contrato' => 0, 'sin_linea_original' => 0, 'ya_iguales' => 0];
foreach ($lineas as $l) {
    if ($l['origen_precio'] !== 'CONTRATO' || !in_array($l['contrato'], ['EVENTO', 'EVENTO SAS'], true)) continue;
    $conteo['revisadas']++;
    $b = $original[_u($l['cum_facturar'])] ?? null;
    if (!$b) { $conteo['sin_linea_original']++; continue; }
    $final = (float) $b['precio_venta'] * (1 + (float) $b['iva']);
    $tipo = in_array((string) $l['tipo_producto'], ['714', '799'], true) ? (string) $l['tipo_producto'] : nt_tipo_producto($l['cum_facturar']);
    $tope = $ctr[_u($l['cod_mutual'])][$tipo] ?? null;
    if ($tope !== null && $final > $tope + 0.5) { $conteo['quedan_en_el_contrato']++; continue; } // el precio del producto supera el contrato
    $nuevo = round($final / (1 + (float) $l['iva']), 2);
    if (abs($nuevo - (float) $l['precio_venta']) < 0.005) { $conteo['ya_iguales']++; continue; }
    $conteo['cambian']++;
    $cambios[] = ['id' => (int) $l['id'], 'antes' => (float) $l['precio_venta'], 'despues' => $nuevo, 'desde' => $b['contrato']];
}
echo 'Líneas EVENTO / EVENTO SAS con el precio del contrato: ' . json_encode($conteo, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (!$confirmar) exit('Revisión: no se guardó nada. Para aplicar: php corregir_precios_20261026.php --confirmar' . PHP_EOL);

$pdo->beginTransaction();
try {
    if ($cambios) {
        $v = nt_guardar_version($pdo, 'Versión anterior: antes de pasar las líneas EVENTO / EVENTO SAS del precio del contrato al precio del producto', $admin, ['lineas' => count($cambios)]);
        echo "Versión anterior de la Maestra Tarifaria guardada: v{$v['version']}" . PHP_EOL;
        $up = $pdo->prepare("UPDATE nt_item SET precio_venta = ?, origen_precio = 'COPIA', actualizado_en = ?, actualizado_por = ? WHERE id = ?");
        foreach ($cambios as $c) {
            $up->execute([$c['despues'], ahora(), $admin, $c['id']]);
            auditar($admin, 'NT_PRECIO_LINEA_ORIGINAL', 'nt_item', $c['id'], ['precio_venta' => $c['antes'], 'origen_precio' => 'CONTRATO'],
                ['precio_venta' => $c['despues'], 'origen_precio' => 'COPIA', 'desde' => $c['desde'], 'motivo' => 'EVENTO: precio propio, el contrato es el tope (2026-10-26)']);
        }
    }
    // Inconsistencias de MEDICAR con el precio nuevo: las de precio que se cierran ahora eran falsas (no se corrigió nada en MEDICAR)
    $desde = ahora();
    nt_calcular($pdo, true);
    $rev = cor_revisar($pdo, 'MEDICAR', $admin);
    $falsas = "sistema = 'MEDICAR' AND campo = 'PRECIO' AND estado = 'CORREGIDO' AND cerrado_en >= ? AND exportacion_id IS NULL";
    $st = $pdo->prepare("DELETE FROM cor_inconsistencia WHERE $falsas");
    $st->execute([$desde]);
    $eliminadas = $st->rowCount();
    auditar($admin, 'COR_PURGAR', 'cor_inconsistencia', null, null, ['sistema' => 'MEDICAR', 'campo' => 'PRECIO', 'filas' => $eliminadas,
        'motivo' => 'Falsos errores de precio por la regla anterior (precio del contrato en EVENTO y redondeo hacia arriba), 2026-10-26']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('Error: no se guardó nada. ' . $e->getMessage() . PHP_EOL);
}
echo 'Líneas corregidas: ' . count($cambios) . PHP_EOL;
echo 'Inconsistencias MEDICAR: ' . json_encode($rev, JSON_UNESCAPED_UNICODE) . "; de precio eliminadas por falsas: $eliminadas" . PHP_EOL;
