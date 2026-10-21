<?php
/**
 * Carga inicial de catálogos desde el Excel vigente "CREAR ITEMS - CSALDARRIAGA 2025.xlsx"
 * (hojas COD GEN, LAB, Presentacion, LISTAS, TABLA-FFM/UMM/UPR, IUM) y la lista de
 * descripciones técnicas "0. doc base/items.xlsx".
 *
 * Cada catálogo se reemplaza completo dentro de una transacción. Los duplicados y datos
 * inválidos no detienen la carga: se omiten y se reportan (hoy el Excel solo avisa
 * "HAY DUPLICADOS" en COD GEN y no dice cuáles).
 */
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/datos.php';

function importar_catalogos(PDO $pdo, string $excel, string $excelCampos, ?int $usuarioId = null): array {
    $x = new XlsxReader($excel);
    $ahora = ahora();
    $reporte = [];

    $cargar = function (string $tabla, array $cols, array $filas, array $omitidas) use ($pdo, &$reporte) {
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM $tabla");
            $n = insertar_masivo($pdo, $tabla, $cols, $filas);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $reporte[$tabla] = ['cargadas' => $n, 'omitidas' => $omitidas];
    };

    // ---- COD GEN (encabezado en fila 5, datos desde fila 6)
    $filas = []; $omit = []; $vistos = [];
    foreach ($x->filas('COD GEN') as $num => $f) {
        if ($num < 6) continue;
        $cod = codigo_excel($f['A'] ?? '');
        if ($cod === '') continue;
        if (isset($vistos[$cod])) { $omit[] = "Fila $num: código $cod duplicado (se conserva la fila {$vistos[$cod]})"; continue; }
        $vistos[$cod] = $num;
        $filas[] = [$cod, trim($f['B'] ?? ''), codigo_excel($f['D'] ?? ''), trim($f['E'] ?? ''), trim($f['F'] ?? ''),
                    codigo_excel($f['G'] ?? ''), trim($f['H'] ?? ''), trim($f['I'] ?? ''), trim($f['J'] ?? ''),
                    trim($f['K'] ?? ''), trim($f['L'] ?? ''), 1, $ahora];
    }
    $cargar('cat_molecula', ['cod_gen_audifarma', 'descripcion_general', 'codigo_medicar', 'pos_no_pos', 'concentracion_agrupada',
        'cantidad_tope', 'principio_activo', 'forma_farmaceutica', 'grupo_farmacologico', 'via_administracion', 'cadena_frio',
        'activo', 'actualizado_en'], $filas, $omit);

    // ---- LAB
    $filas = []; $omit = []; $nombres = []; $codigos = []; $codigos_por_nombre = [];
    foreach ($x->filas('LAB') as $num => $f) {
        if ($num < 2) continue;
        $nom = trim($f['A'] ?? ''); $cod = codigo_excel($f['B'] ?? '');
        if ($nom === '' && $cod === '') continue;
        if ($nom === '' || $cod === '') { $omit[] = "Fila $num: laboratorio sin nombre o sin código"; continue; }
        $k = mb_strtoupper($nom);
        if (isset($nombres[$k])) {
            // Igual que XLOOKUP del Excel: se conserva el PRIMER código. Requiere decisión del Administrador.
            $omit[] = "Fila $num: laboratorio '$nom' tiene dos códigos: se conserva {$codigos_por_nombre[$k]} (fila {$nombres[$k]}), se omite $cod";
            continue;
        }
        if (isset($codigos[$cod])) { $omit[] = "Fila $num: código de laboratorio $cod repetido (fila {$codigos[$cod]})"; continue; }
        $nombres[$k] = $num; $codigos[$cod] = $num; $codigos_por_nombre[$k] = $cod;
        $filas[] = [$nom, $cod, 1, $ahora];
    }
    $cargar('cat_laboratorio', ['nombre', 'codigo', 'activo', 'actualizado_en'], $filas, $omit);

    // ---- Presentacion
    // La unidad de orden se repite entre presentaciones (CAJA X 300 / CAJA SELLADA X 300 -> C300).
    // El Excel busca el código POR UNIDAD DE ORDEN (fórmula AM) y asigna a la segunda el código de
    // la primera; el aplicativo lo busca por presentación agrupada. Se reporta como aviso.
    $filas = []; $omit = []; $ya = ['a' => [], 'b' => []]; $porUnidad = [];
    foreach ($x->filas('Presentacion') as $num => $f) {
        if ($num < 2) continue;
        $pa = trim($f['A'] ?? ''); $cod = codigo_excel($f['B'] ?? ''); $uo = trim($f['C'] ?? '');
        if ($pa === '' && $cod === '' && $uo === '') continue;
        if ($pa === '' || $cod === '' || $uo === '') { $omit[] = "Fila $num: presentación incompleta ($pa / $cod / $uo)"; continue; }
        foreach (['a' => $pa, 'b' => $cod] as $k => $v) {
            if (isset($ya[$k][$v])) { $omit[] = "Fila $num: '$v' repetido (fila {$ya[$k][$v]})"; continue 2; }
        }
        $ya['a'][$pa] = $ya['b'][$cod] = $num;
        if (isset($porUnidad[$uo])) {
            [$filaAnt, $paAnt, $codAnt] = $porUnidad[$uo];
            $omit[] = "AVISO fila $num: '$pa' (código $cod) comparte la unidad de orden $uo con '$paAnt' (fila $filaAnt). "
                    . "En el Excel recibía el código $codAnt; en el aplicativo conserva el suyo ($cod).";
        } else {
            $porUnidad[$uo] = [$num, $pa, $cod];
        }
        $filas[] = [$pa, $cod, $uo, 1, $ahora];
    }
    $cargar('cat_presentacion', ['presentacion_agrupada', 'codigo', 'unidad_orden', 'activo', 'actualizado_en'], $filas, $omit);

    // ---- LISTAS (validaciones de datos del FORMATO). Subplanes e IVA tienen tabla propia.
    // CATEGORIA PBS (columna K) no se importa: es dato duplicado, ya viene de COD GEN (POS / NO POS).
    $mapa = ['A' => 'TIPO_MEDICAMENTO', 'E' => 'ESTADO_INVIMA', 'H' => 'ENVASE'];
    $filas = []; $orden = [];
    foreach ($x->filas('LISTAS') as $num => $f) {
        if ($num < 2) continue;
        foreach ($mapa as $col => $lista) {
            $v = trim($f[$col] ?? '');
            if ($v === '' || isset($orden[$lista][$v])) continue;
            $orden[$lista][$v] = count($orden[$lista] ?? []) + 1;
            $filas[] = [$lista, $v, $orden[$lista][$v], 1];
        }
    }
    $cargar('cat_lista_valor', ['lista', 'valor', 'orden', 'activo'], $filas, []);

    // ---- RIPS (FFM y UPR: C=código, D=nombre; UMM: B="nombre-código")
    $filas = []; $omit = []; $ya = [];
    foreach (['FFM' => 'TABLA-FFM', 'UPR' => 'TABLA-UPR', 'UMM' => 'TABLA-UMM'] as $tabla => $hoja) {
        foreach ($x->filas($hoja) as $num => $f) {
            if ($num < 2) continue;
            if ($tabla === 'UMM') {
                $concat = trim($f['B'] ?? '');
                $pos = strrpos($concat, '-');
                if ($pos === false) continue;
                $cod = trim(substr($concat, $pos + 1)); $nom = trim(substr($concat, 0, $pos));
            } else {
                $cod = codigo_excel($f['C'] ?? ''); $nom = trim($f['D'] ?? '');
            }
            if ($cod === '' || $nom === '') continue;
            if (isset($ya[$tabla][$cod])) { $omit[] = "$hoja fila $num: código $cod repetido"; continue; }
            $ya[$tabla][$cod] = true;
            $filas[] = [$tabla, $cod, $nom, strtoupper(trim($f['F'] ?? 'SI')) === 'NO' ? 0 : 1];
        }
    }
    $cargar('cat_rips', ['tabla', 'codigo', 'nombre', 'habilitado'], $filas, $omit);

    // ---- IUM
    $filas = []; $ya = [];
    foreach ($x->filas('IUM') as $num => $f) {
        if ($num < 2) continue;
        $cod = trim($f['B'] ?? '');
        if ($cod === '' || isset($ya[$cod])) continue;
        $ya[$cod] = true;
        $filas[] = [$cod, trim($f['D'] ?? '')];
    }
    $cargar('cat_ium', ['codigo', 'nombre'], $filas, []);

    // ---- Campos de descripciones técnicas (items.xlsx)
    $y = new XlsxReader($excelCampos);
    $filas = []; $ya = [];
    foreach ($y->filas($y->hojas()[0]) as $num => $f) {
        $v = trim($f['A'] ?? '');
        if ($num < 2 || $v === '' || isset($ya[$v])) continue;
        $ya[$v] = true;
        $filas[] = [$v, count($filas) + 1, 1];
    }
    $cargar('cat_campo_tecnico', ['f104_id', 'orden', 'activo'], $filas, []);

    insertar($pdo, 'ref_carga', [
        'tipo_base' => 'CATALOGOS_EXCEL', 'origen' => basename($excel), 'hash_sha256' => hash_file('sha256', $excel),
        'filas' => array_sum(array_column($reporte, 'cargadas')), 'estado' => 'OK', 'vigente' => 1,
        'detalle' => json_encode($reporte, JSON_UNESCAPED_UNICODE), 'usuario_id' => $usuarioId, 'creado_en' => $ahora,
    ]);
    $pdo->exec("UPDATE ref_carga SET vigente = 0 WHERE tipo_base = 'CATALOGOS_EXCEL' AND id <> (SELECT MAX(id) FROM (SELECT id FROM ref_carga WHERE tipo_base = 'CATALOGOS_EXCEL') t)");
    return $reporte;
}
