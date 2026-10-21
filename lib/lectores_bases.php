<?php
/**
 * LECTURA DE BASES EXTERNAS (fase 3) — de archivo a tabla SQL.
 *
 * - Cada base define: tabla destino, columnas (encabezado del archivo -> columna SQL), cuáles son
 *   obligatorias y la llave para detectar duplicados.
 * - Estructura: si falta una columna obligatoria, el archivo se RECHAZA y se indica cuál falta.
 * - Filas: sin dato en una columna obligatoria -> se omite y se reporta (no se inventa nada).
 * - Carga en una sola transacción: la versión nueva entra completa o no entra; si la base no
 *   guarda versiones, las filas de la versión anterior se eliminan en la misma transacción.
 */
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/datos.php';

/**
 * columna SQL => [encabezados aceptados, tipo (texto|codigo|numero|fecha), obligatoria, valores que significan vacío]
 * - Encabezado que empieza por "/" = patrón (p.ej. '/^PRECIO UNITARIO \d{4}$/'): si varios coinciden
 *   se toma el mayor (el año más reciente), así el archivo del año siguiente no exige cambiar el código.
 * - obligatoria: true = la columna debe existir y la fila debe traer el dato; 'columna' = la columna
 *   debe existir pero la celda puede venir vacía (lo evalúa el motor de validación, no se omite la fila).
 * - 4º elemento opcional: textos como "SIN ID MUTUAL" que se guardan como vacío y se cuentan como aviso.
 * Opciones de la base: 'hoja' (patrón del nombre de la hoja a leer primero; los encabezados se buscan
 * en las primeras 30 filas), 'descartar_duplicados' (la fila repetida por llave no se guarda).
 */
function lector_definicion(string $base): ?array {
    $d = [
        'MAESTRO_SIESA' => ['tabla' => 'ref_siesa_item', 'llave' => 'referencia', 'columnas' => [
            'item'              => [['ITEM'], 'codigo', false],
            'referencia'        => [['REFERENCIA'], 'codigo', true],
            'cum'               => [['MED-CUM'], 'codigo', false], // hay ítems sin CUM (insumos): igual cuentan para el PLU
            'registro_sanitario'=> [['MED-REGISTRO SANITARIO'], 'codigo', false],
            'descripcion'       => [['NOTAS'], 'texto', false],
            'contrato'          => [['MED-CONTRATO'], 'texto', false],
            'unidad_orden'      => [['U.M. ORDEN'], 'codigo', false],
            'laboratorio'       => [['MED-LABORATORIO'], 'texto', false],
            'cod_gen_audifarma' => [['MED-CODIGO GENERICO AUDIFARMA'], 'codigo', false],
            'id_mutual'         => [['MED-ID MUTUAL'], 'codigo', false],
            'tipo_inventario'   => [['TIPO INVENTARIO'], 'codigo', false],
            'grupo_impositivo'  => [['GRUPO IMPOSITIVO'], 'codigo', false],
            'estado'            => [['ESTADO'], 'texto', true],
            'tipo_medicamento'  => [['MED-TIPO DE MEDICAMENTO'], 'texto', false],
            'codigo_barra'      => [['MED-CODIGO DE BARRA'], 'codigo', false],
            'codigo_atc'        => [['MED-CODIGO ATC'], 'texto', false], // ATC en la Maestra Tarifaria (pedido del usuario, 2026-10-19)
            'forma_farmaceutica_rips' => [['MED-FORMA FARMACEUTICA RIPS'], 'codigo', false],
            'concentracion_rips'      => [['MED-CONCENTRACION MEDICAMENTO RIPS'], 'codigo', false],
            'unidad_medida_rips'      => [['MED-UNIDAD MEDIDA RIPS'], 'codigo', false],
            // correcciones SIESA: el ID MUTUAL también va en el código genérico auxiliar; unidades para CREACION_ITEM
            'codigo_generico_auxiliar'=> [['MED-CODIGO GENERICO AUXILIAR'], 'codigo', false],
            'unidad_inventario'       => [['U.M. INVENT.'], 'codigo', false],
            'unidad_presentacion'     => [['MED-UNIDAD DE PRESENTACION'], 'codigo', false],
        ]],
        // Un mismo PLU aparece una vez por subplán: la llave es PLU + subplán.
        'MAESTRA_MEDICAR' => ['tabla' => 'ref_medicar', 'llave' => 'plu_cadena|id_subplan', 'columnas' => [
            'codigo_molecula'      => [['CODIGO_MOLECULA', 'CODIGO MOLECULA'], 'codigo', true],
            'molecula'             => [['DESCRIPCION MOLECULA MEDICAR', 'MOLECULA'], 'texto', false],
            'plu_cadena'           => [['PLU_CADENA', 'PLU CADENA'], 'codigo', true],
            'descripcion_articulo' => [['DESCRIPCION ARTICULO MEDICAR'], 'texto', false],
            'cum'                  => [['CUM'], 'codigo', false],
            'laboratorio'          => [['LABORATORIO'], 'texto', false],
            'subplan'              => [['SUBPLAN'], 'texto', false],
            'id_subplan'           => [['ID_SPLAN', 'ID SUBPLAN'], 'codigo', false],
            'codigo_generico'      => [['CODIGO GENERICO'], 'codigo', false],
            'opcion'               => [['OPCION CADENA', 'OPCION'], 'codigo', false], // 1 = activo, 9 = inactivo
            // correcciones MEDICAR (2026-10-19): como texto (el CSV usa punto decimal: «200.000000» = 200, no 200 millones)
            'precio_um_iva'        => [['PRECIO UNIDAD MINIMA CON IVA'], 'texto', false],
            'iva_pct'              => [['%IVA'], 'texto', false],
            'codigo_md'            => [['CODIGO MD'], 'codigo', false],
            'registro_invima'      => [['REGISTRO INVIMA'], 'texto', false],
            'tipo_medicamento'     => [['TIPO MEDICAMENTO'], 'texto', false],
        ]],
        // Se valida por Código CUP + ID MUTUAL (P3); la tarifa pactada es el precio de cápita (fase 4).
        'LISTADO_CAPITA' => ['tabla' => 'ref_capita', 'llave' => 'id_mutual|codigo_cup', 'columnas' => [
            'cum'                 => [['CUM'], 'codigo', false],
            'id_mutual'           => [['ID MUTUAL'], 'codigo', true], // obligatorio (se permitió vacío solo para cargar las versiones antiguas, 2026-10-19)
            'codigo_cup'          => [['CODIGO CUP'], 'codigo', true],
            'tipo_servicio'       => [['TIPO SERVICIO'], 'texto', false],
            'codigo_servicio'     => [['CODIGO SERVICIO'], 'codigo', false],
            'descripcion_cups'    => [['DESCRIPCION CUPS'], 'texto', false],
            'tarifa_pactada'      => [['TARIFA PACTADA'], 'numero', true],
            'invima'              => [['INVIMA'], 'codigo', false],
            'fuente_financiacion' => [['FUENTE DE FINANCIACION'], 'texto', false],
            'regulado'            => [['MEDICAMENTO REGULADO'], 'texto', false],
        ]],
        // Subcuenta EVENTO SAS -> subplán "EVENTO SAS SIN AUTORIZACION" (P2).
        // Un ID MUTUAL agrupa varios productos (p.ej. 11400 = preservativos de 9 laboratorios, cada uno
        // con su registro; en insumos la columna CUM repite el ID). La fila única es el producto:
        // Cód. Audifarma + ID MUTUAL + registro + descripción. "SIN ID MUTUAL" no es un código.
        'LISTADO_EVENTO_SAS' => ['tabla' => 'ref_evento_sas', 'llave' => 'cod_audifarma|id_mutual|registro_sanitario|descripcion', 'columnas' => [
            'cod_audifarma'      => [['COD. AUDIFARMA', 'COD AUDIFARMA'], 'codigo', true],
            'subcuenta'          => [['SUBCUENTA'], 'texto', false],
            'cum'                => [['CUM'], 'codigo', false],
            'registro_sanitario' => [['REGISTRO SANITARIO'], 'codigo', false],
            'descripcion'        => [['DESCRIPCION'], 'texto', false],
            'clasificacion'      => [['CLASIFICACION FINAL'], 'texto', false],
            'id_mutual'          => [['ID MUTUAL'], 'codigo', false, ['SIN ID MUTUAL']],
            'id_mutual_capita'   => [['ID_MUTUALSER CAPITA'], 'codigo', false],
            'cod_medicar'        => [['COD. MEDICAR', 'COD MEDICAR'], 'codigo', false],
        ]],
        // ID MUTUAL dentro del contrato y su precio unitario (P1).
        // El mismo ID MUTUAL puede estar en los servicios 714 y 799: la llave es ID MUTUAL + servicio.
        // PRODUCTO puede traer ";" sin comillas: ese texto sobrante se une en esa columna.
        'CONTRATO_EVENTO_22382' => ['tabla' => 'ref_contrato_evento', 'llave' => 'id_mutual|cod_servicio', 'columna_libre' => 'PRODUCTO', 'columnas' => [
            'numero_contrato' => [['NUMERO'], 'codigo', true],
            'cod_servicio'    => [['COD_SERVICIO'], 'codigo', false],
            'servicio'        => [['SERVICIO'], 'texto', false],
            'id_mutual'       => [['CODIGO_PRODUCTO'], 'codigo', true],
            'producto'        => [['PRODUCTO'], 'texto', false],
            'estado'          => [['ESTADO_PRODUCTO'], 'codigo', true],
            'valor_unitario'  => [['VALOR_PRODUCTO'], 'numero', true],
        ]],
        // IMPORTACIÓN de la Maestra Tarifaria (hoja EVENTO 2025, encabezados en la fila 5): solo lo que se digita.
        // Lo que en el Excel es fórmula (contrato, validadores, precio + IVA, precio máximo...) lo calcula el
        // aplicativo. La llave es el CUM a facturar; si se repite, la importación conserva la fila más antigua.
        // Llave: CUM a facturar + CONTRATO (una línea por contrato).
        'NOTA_TECNICA' => ['tabla' => 'ref_nt_item', 'llave' => 'cum_facturar|contrato|subplan_archivo|plu_archivo', 'hoja' => '/^EVENTO/i', 'columnas' => [
            'contrato'         => [['CONTRATO'], 'texto', false],
            'subplan_archivo'  => [['SUBPLAN'], 'texto', false], // EVENTO + «EVENTO SAS SIN AUTORIZACION» = línea SAS
            'plu_archivo'      => [['PLU'], 'codigo', false], // el Excel trae un PLU por fila: la misma línea puede venir en varias filas
            'fecha'            => [['FECHA', 'FECHA DE CREACION'], 'fecha', false],
            'cum'              => [['CUM'], 'codigo', 'columna'],
            'cum_facturar'     => [['CUM FACTURAR'], 'codigo', true],
            'cod_auf'          => [['COD AUF'], 'codigo', 'columna'],
            'tipo_producto'    => [['TIPO PRODUCTO'], 'codigo', false],
            'descripcion'      => [['DESCRIPCION'], 'texto', 'columna'],
            'cod_gen_siesa'    => [['COD GEN SIESA'], 'codigo', false],
            'nuevo_cod_mutual' => [['NUEVO CODIGO MUTUAL'], 'codigo', false, ['VACIO']],
            'cod_mutual'       => [['COD MUTUAL'], 'codigo', 'columna'],
            'precio_venta'     => [['/^PRECIO DE VENTA( \d{4})?$/'], 'numero', 'columna'],
            'iva'              => [['IVA'], 'numero', 'columna'],
            // calculadas: no se cargan; se leen para avisar en la vista previa si se editaron en el archivo
            'precio_final_archivo' => [['/^PRECIO FINAL \+ IVA$/', 'PRECIO FINAL + IVA'], 'numero', false],
            'precio_m_archivo'     => [['/PRECIO CORRECTO\s*-\s*M/', '¿PRECIO CORRECTO- M?'], 'numero', false],
        ]],
        // Homologación Mutual Ser (CUMS_PHARMASER.csv): un código agrupa varios CUM. Filas repetidas
        // (mismo código + CUM) no se guardan, igual que "Quitar duplicados" del Power Query actual.
        'CUMS_MUTUAL' => ['tabla' => 'ref_cums_mutual', 'llave' => 'cod_mutual|cum', 'descartar_duplicados' => true, 'columnas' => [
            'cod_mutual'         => [['PROC_CODIGO'], 'codigo', true],
            'nombre'             => [['PROC_NOMBRE'], 'texto', false],
            'principio'          => [['PROC_PRINCIPIO'], 'texto', false],
            'cum'                => [['PRIC_INVIMA'], 'codigo', 'columna'],
            'laboratorio'        => [['PRIC_LABORATORIO'], 'texto', false],
            'registro_sanitario' => [['PRIC_REGISTRO'], 'codigo', false],
            'descripcion'        => [['PRIC_DESCRIPCION'], 'texto', false],
            'clasificacion'      => [['PRON_CLASIFICACION'], 'codigo', false],
        ]],
        // Maestra Mutual Ser, hoja «Consulta de medicamentos» (descarga automática, lib/mutual.php): ID MUTUAL <-> CUM.
        // Mismas columnas que el script MaestraMutualser.R. «Código CUM» viene dos veces: se usa la primera
        // (igual que codigo_cum_1 del script). Los encabezados se ubican solos (antes venían en la fila 15).
        'MEDICAMENTOS_MUTUAL' => ['tabla' => 'ref_medicamentos_mutual', 'llave' => 'id_mutual|cum', 'descartar_duplicados' => true,
            'hoja' => '/^Consulta de medicamentos/i', 'columnas' => [
            'cum'                => [['CODIGO CUM', 'CODIGO CUM 1'], 'codigo', true],
            'id_mutual'          => [['ID MUTUALSER', 'ID MUTUAL SER'], 'codigo', true],
            'cod_gen_audifarma'  => [['CODIGO GENERICO AUDIFARMA'], 'codigo', false],
            'codigo_atc'         => [['CODIGO ATC'], 'codigo', false],
            'descripcion_atc'    => [['DESCRIPCION ATC'], 'texto', false],
            'medicamento_dci'    => [['MEDICAMENTO POR DCI'], 'texto', false],
            'medicamento'        => [['NOMBRE MEDICAMENTO COMUN'], 'texto', false],
            'financiacion'       => [['FINANCIACION'], 'texto', false],
            'nombre_comercial'   => [['NOMBRE COMERCIAL'], 'texto', false],
            'expediente'         => [['EXPEDIENTE'], 'codigo', false],
            'registro_sanitario' => [['REGISTRO SANITARIO'], 'codigo', false],
            'fecha_vencimiento'  => [['FECHA VENCIMIENTO'], 'fecha', false],
            'estado_registro'    => [['ESTADO REGISTRO SANITARIO'], 'texto', false],
            'estado_cum'         => [['ESTADO CUM'], 'texto', false],
            'codigo_ium'         => [['CODIGO IUM'], 'codigo', false],
            'estado'             => [['ESTADO'], 'texto', false],
            'valor_referencia'   => [['VALOR DE REFERENCIA MUTUAL'], 'numero', false],
        ]],
        // Circular de precios (hoja ANALISIS): precio unitario regulado vigente por código genérico.
        'PRECIOS_REGULADOS' => ['tabla' => 'ref_precio_regulado', 'llave' => 'cod_gen', 'hoja' => '/^ANALISIS/i', 'columnas' => [
            'cod_gen'         => [['COD GEN', 'CODIGO GENERICO'], 'codigo', true],
            'nombre'          => [['NOMBRE'], 'texto', false],
            'precio_regulado' => [['/^PRECIO UNITARIO \d{4}$/', 'PRECIO REGULADO'], 'numero', true],
        ]],
    ];
    return $d[$base] ?? null;
}

function _encabezado(string $v): string {
    $v = strtr(mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $v))), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U']);
    return preg_replace('/[\x{FEFF}\x{200B}]/u', '', $v);
}

/** Texto a UTF-8 (los CSV de Medicar/contrato pueden venir en Windows-1252). */
function _utf8(string $s): string {
    return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/** Separador más probable de una línea (el que más se repite fuera de comillas). */
function _separador(string $linea): string {
    $sinComillas = preg_replace('/"[^"]*"/', '', $linea);
    $conteo = [];
    foreach ([';', ',', "\t", '|'] as $s) $conteo[$s] = substr_count($sinComillas, $s);
    arsort($conteo);
    return array_key_first($conteo);
}

/**
 * Filas de un archivo como [número => [ENCABEZADO NORMALIZADO => valor]].
 * CSV: el separador se detecta por separado en el encabezado y en los datos (el Contrato Evento
 * trae el encabezado con "," y los datos con ";").
 */
function leer_archivo_base(string $ruta, string $ext, ?string $columnaLibre = null, ?array $def = null): Generator {
    if ($ext === 'csv') {
        $fh = fopen($ruta, 'rb');
        if (!$fh) throw new RuntimeException('No se pudo abrir el archivo.');
        $primera = fgets($fh);
        if ($primera === false) { fclose($fh); return; }
        $primera = preg_replace('/^\xEF\xBB\xBF/', '', rtrim(_utf8($primera), "\r\n"));
        $encabezados = array_map('_encabezado', str_getcsv($primera, _separador($primera), '"', ''));
        $iLibre = $columnaLibre !== null ? array_search(_encabezado($columnaLibre), $encabezados, true) : false;
        $sepDatos = null; $num = 1;
        while (($linea = fgets($fh)) !== false) {
            $num++;
            $linea = rtrim(_utf8($linea), "\r\n");
            if (trim($linea) === '') continue;
            $sepDatos ??= _separador($linea);
            $valores = str_getcsv($linea, $sepDatos, '"', '');
            // Más campos que encabezados = el separador apareció dentro de un texto sin comillas
            // (p.ej. "ATORVASTATINA ...; EZETIMIBA ..."): el sobrante se une en la columna libre.
            $sobran = count($valores) - count($encabezados);
            if ($sobran > 0 && $iLibre !== false) {
                $unido = implode($sepDatos, array_slice($valores, $iLibre, $sobran + 1));
                array_splice($valores, $iLibre, $sobran + 1, [$unido]);
            }
            $fila = [];
            foreach ($encabezados as $i => $e) if ($e !== '' && isset($valores[$i]) && !isset($fila[$e])) $fila[$e] = trim($valores[$i]);
            yield $num => $fila;
        }
        fclose($fh);
        return ['hoja' => 'CSV', 'fila_encabezado' => 1];
    }
    $x = new XlsxReader($ruta);
    [$hoja, $filaEnc] = _ubicar_encabezados($x, $def);
    $encabezados = null;
    foreach ($x->filas($hoja) as $num => $celdas) {
        if ($num < $filaEnc) continue;
        if ($encabezados === null) {
            $encabezados = array_map('_encabezado', $celdas);
            $primera = []; // encabezado repetido (p.ej. «Código CUM» en la maestra de Mutual): manda la primera columna
            foreach ($encabezados as $col => $e) $primera[$e] ??= $col;
            continue;
        }
        $fila = [];
        foreach ($encabezados as $col => $e) if ($e !== '' && isset($celdas[$col]) && $primera[$e] === $col) $fila[$e] = trim((string) $celdas[$col]);
        yield $num => $fila;
    }
    return ['hoja' => $hoja, 'fila_encabezado' => $filaEnc];
}

/** Nombre legible de una columna para los mensajes (un patrón se muestra sin sus signos). */
function _nombre_encabezado(array $aceptados): string {
    if ($aceptados[0][0] !== '/') return $aceptados[0];
    return $aceptados[1] ?? trim(preg_replace(['/\\\\d\{\d+\}/', '/[^A-Z ]/'], '', $aceptados[0]));
}

/** ¿El encabezado del archivo corresponde a uno aceptado (texto exacto o patrón)? */
function _encabezado_coincide(string $aceptado, string $enc): bool {
    return $aceptado[0] === '/' ? (bool) preg_match($aceptado . 'u', $enc) : _encabezado($aceptado) === $enc;
}

/**
 * Hoja y fila donde están los encabezados: la primera fila (de las 30 primeras) que contiene todas
 * las columnas obligatorias. Primero se revisan las hojas cuyo nombre coincide con 'hoja'.
 * Si no se encuentra, se usa la primera hoja y la fila 1 (y la validación indicará qué columnas faltan).
 */
function _ubicar_encabezados(XlsxReader $x, ?array $def): array {
    $hojas = $x->hojas();
    if (!$def) return [$hojas[0], 1];
    $buscar = array_filter($def['columnas'], fn($c) => $c[2]);
    if (!empty($def['hoja'])) {
        $preferidas = array_values(array_filter($hojas, fn($h) => preg_match($def['hoja'] . 'u', $h)));
        $hojas = [...$preferidas, ...array_diff($hojas, $preferidas)];
    }
    foreach ($hojas as $h) {
        foreach ($x->filas($h) as $num => $celdas) {
            if ($num > 30) break;
            $encs = array_map('_encabezado', array_map('strval', $celdas));
            $todas = true;
            foreach ($buscar as [$aceptados]) {
                $esta = false;
                foreach ($aceptados as $a) foreach ($encs as $e) if (_encabezado_coincide($a, $e)) { $esta = true; break 2; }
                if (!$esta) { $todas = false; break; }
            }
            if ($todas) return [$h, $num];
        }
    }
    return [$x->hojas()[0], 1];
}

function _valor_tipo(?string $v, string $tipo): ?string {
    $v = $v === null ? '' : trim(preg_replace('/\s+/u', ' ', $v));
    if ($v === '' || in_array(mb_strtoupper($v), ['NULL', '#N/A', '#N/D', '#REF!', '#VALUE!'], true)) return null;
    if ($tipo === 'numero') {
        $n = str_replace([' ', '$'], '', $v);
        if (preg_match('/^-?\d+,\d+$/', $n)) $n = str_replace(',', '.', $n);      // 1234,5 -> 1234.5
        elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $n)) $n = str_replace('.', '', $n); // 18.860 -> 18860
        return is_numeric($n) ? (string) round((float) $n, 2) : '#INVALIDO';
    }
    if ($tipo === 'codigo') return codigo_excel($v);
    if ($tipo === 'fecha') {
        if (is_numeric($v)) return XlsxReader::fechaSerial($v);                        // serial de Excel
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null;
    }
    return mb_substr($v, 0, 500);
}

/**
 * Lee el archivo y lo carga en la tabla de la base con $cargaId (dentro de la transacción del
 * llamador). Devuelve el resumen. Lanza ErrorApi si la estructura no corresponde.
 */
/**
 * Cuando se carga una versión histórica (en medio de otras), la versión que sigue por vigencia debe compararse ahora con ella:
 * se recalculan sus cambios y su resumen.
 */
function base_recalcular_cambios_siguiente(PDO $pdo, string $base, int $cargaId): void {
    if (!in_array($base, BASES_CON_CAMBIOS, true)) return;
    $def = lector_definicion($base);
    $st = $pdo->prepare("SELECT COALESCE(vigente_desde, SUBSTR(creado_en, 1, 10)) FROM ref_carga WHERE id = ?");
    $st->execute([$cargaId]);
    $desde = (string) $st->fetchColumn();
    $st = $pdo->prepare("SELECT c.id, c.detalle FROM ref_carga c WHERE c.tipo_base = ? AND c.id <> ? AND EXISTS (SELECT 1 FROM {$def['tabla']} t WHERE t.carga_id = c.id)
                            AND (COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)) > ? OR (COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)) = ? AND c.id > ?))
                          ORDER BY COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)), c.id LIMIT 1");
    $st->execute([$base, $cargaId, $desde, $desde, $cargaId]);
    $sig = $st->fetch();
    if (!$sig) return;
    $cols = array_keys($def['columnas']);
    $st = $pdo->prepare('SELECT carga_id, ' . implode(', ', $cols) . " FROM {$def['tabla']} WHERE carga_id = ?");
    $st->execute([$sig['id']]);
    $filas = array_map('array_values', $st->fetchAll());
    $cambios = base_registrar_cambios($pdo, $base, (int) $sig['id'], $filas);
    $det = (json_decode((string) $sig['detalle'], true) ?: []); $det['cambios'] = $cambios;
    $pdo->prepare('UPDATE ref_carga SET detalle = ? WHERE id = ?')->execute([json_encode($det, JSON_UNESCAPED_UNICODE), $sig['id']]);
}

/** Bases manuales a las que se les calculan los cambios entre versiones (INVIMA y la Maestra Mutual son automáticas y muy grandes). */
const BASES_CON_CAMBIOS = ['MAESTRO_SIESA', 'MAESTRA_MEDICAR', 'LISTADO_CAPITA', 'LISTADO_EVENTO_SAS', 'CONTRATO_EVENTO_22382', 'PRECIOS_REGULADOS'];

/**
 * Compara las filas nuevas de una carga con las de la versión anterior de la misma base (por la llave de la base) y
 * guarda los cambios en ref_carga_cambio: NUEVO, RETIRADO y MODIFICADO (campo, antes, después). Devuelve el resumen.
 */
function base_registrar_cambios(PDO $pdo, string $base, int $cargaId, array $filas): ?array {
    $def = lector_definicion($base);
    $tabla = $def['tabla'];
    $cols = array_keys($def['columnas']);
    $llaves = explode('|', $def['llave']);
    $pdo->prepare('DELETE FROM ref_carga_cambio WHERE carga_id = ?')->execute([$cargaId]); // si se vuelve a leer la misma carga
    // versión anterior por vigencia (fecha de ejecución) y no por orden de cargue: las versiones históricas se cargan en cualquier orden
    $st = $pdo->prepare("SELECT COALESCE(vigente_desde, SUBSTR(creado_en, 1, 10)) FROM ref_carga WHERE id = ?");
    $st->execute([$cargaId]);
    $desde = (string) $st->fetchColumn();
    $st = $pdo->prepare("SELECT c.id FROM ref_carga c WHERE c.tipo_base = ? AND c.id <> ? AND EXISTS (SELECT 1 FROM $tabla t WHERE t.carga_id = c.id)
                            AND (COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)) < ? OR (COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)) = ? AND c.id < ?))
                          ORDER BY COALESCE(c.vigente_desde, SUBSTR(c.creado_en, 1, 10)) DESC, c.id DESC LIMIT 1");
    $st->execute([$base, $cargaId, $desde, $desde, $cargaId]);
    $anterior = (int) $st->fetchColumn();
    if (!$anterior) return ['anterior' => null, 'nuevos' => 0, 'retirados' => 0, 'modificados' => 0, 'campos' => 0];
    $norm = fn($v) => $v === null ? '' : (is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') : mb_strtoupper(trim((string) $v)));
    $clave = fn(array $f) => implode(' + ', array_map(fn($c) => trim((string) ($f[$c] ?? '')), $llaves));
    $previas = [];
    $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . " FROM $tabla WHERE carga_id = ?");
    $st->execute([$anterior]);
    foreach ($st as $f) $previas[mb_strtoupper($clave($f))] ??= $f;
    $nuevas = [];
    foreach ($filas as $fila) { $f = array_combine($cols, array_slice($fila, 1)); $nuevas[mb_strtoupper($clave($f))] ??= $f; }
    $ins = []; $res = ['anterior' => $anterior, 'nuevos' => 0, 'retirados' => 0, 'modificados' => 0, 'campos' => 0];
    $corto = fn($v) => $v === null ? null : mb_substr((string) $v, 0, 500);
    foreach ($nuevas as $k => $f) {
        if (!isset($previas[$k])) { $res['nuevos']++; $ins[] = [$cargaId, $anterior, 'NUEVO', mb_substr($clave($f), 0, 250), null, null, null]; continue; }
        $mod = false;
        foreach ($cols as $c) {
            if ($norm($previas[$k][$c] ?? null) === $norm($f[$c] ?? null)) continue;
            $mod = true; $res['campos']++;
            $ins[] = [$cargaId, $anterior, 'MODIFICADO', mb_substr($clave($f), 0, 250), $c, $corto($previas[$k][$c] ?? null), $corto($f[$c] ?? null)];
        }
        if ($mod) $res['modificados']++;
    }
    foreach ($previas as $k => $f) if (!isset($nuevas[$k])) { $res['retirados']++; $ins[] = [$cargaId, $anterior, 'RETIRADO', mb_substr($clave($f), 0, 250), null, null, null]; }
    if ($ins) insertar_masivo($pdo, 'ref_carga_cambio', ['carga_id', 'carga_anterior_id', 'tipo', 'llave', 'campo', 'antes', 'despues'], $ins, 300);
    return $res;
}

function lector_procesar(PDO $pdo, string $base, string $ruta, string $ext, int $cargaId, bool $guardaVersiones): array {
    $def = lector_definicion($base);
    if (!$def) throw new ErrorApi("La base $base no tiene lectura definida.", 422);
    $cols = $def['columnas'];

    $mapa = null; $faltan = [];
    $filas = []; $omitidas = []; $duplicados = []; $vistos = []; $numOmitidas = 0; $numDup = 0; $sinValor = [];
    $llaves = explode('|', $def['llave']);

    $lector = leer_archivo_base($ruta, $ext, $def['columna_libre'] ?? null, $def);
    foreach ($lector as $num => $f) {
        if ($mapa === null) {
            // En la primera fila de datos ya se conocen los encabezados del archivo.
            $disponibles = array_keys($f);
            $mapa = [];
            foreach ($cols as $col => [$aceptados, , $oblig]) {
                $enc = null;
                foreach ($aceptados as $a) {
                    $coinciden = array_values(array_filter($disponibles, fn($d) => _encabezado_coincide($a, $d)));
                    if ($coinciden) { rsort($coinciden); $enc = $coinciden[0]; break; } // patrón: el año más reciente
                }
                if ($enc) $mapa[$col] = $enc; elseif ($oblig) $faltan[] = _nombre_encabezado($aceptados);
            }
        }
        $fila = [];
        foreach ($mapa as $col => $enc) {
            $fila[$col] = _valor_tipo($f[$enc] ?? null, $cols[$col][1]);
            // Textos que significan "no tiene" (p.ej. "Sin ID Mutual"): vacío, contado como aviso.
            if ($fila[$col] !== null && in_array(mb_strtoupper($fila[$col]), $cols[$col][3] ?? [], true)) {
                $fila[$col] = null;
                $sinValor[$cols[$col][0][0]] = ($sinValor[$cols[$col][0][0]] ?? 0) + 1;
            }
        }
        if (!array_filter($fila, fn($v) => $v !== null)) continue; // fila vacía
        $sinDato = [];
        foreach ($cols as $col => [$aceptados, $tipo, $oblig]) {
            if ($oblig === true && (($fila[$col] ?? null) === null)) $sinDato[] = _nombre_encabezado($aceptados);
            if (($fila[$col] ?? null) === '#INVALIDO') $sinDato[] = _nombre_encabezado($aceptados) . ' (no numérico)';
        }
        if ($sinDato) {
            $numOmitidas++;
            if (count($omitidas) < 100) $omitidas[] = "Fila $num: sin " . implode(', ', $sinDato);
            continue;
        }
        $k = implode('|', array_map(fn($c) => mb_strtoupper((string) ($fila[$c] ?? '')), $llaves)); // columnas opcionales de la llave que el archivo no trae
        if (isset($vistos[$k])) {
            $numDup++;
            if (count($duplicados) < 100) $duplicados[] = "Fila $num: " . str_replace('|', ' + ', $k) . " repetido (fila {$vistos[$k]})"
                . (!empty($def['descartar_duplicados']) ? ' — no se guarda' : '');
            if (!empty($def['descartar_duplicados'])) continue;
        } else {
            $vistos[$k] = $num;
        }
        $filas[] = [$cargaId, ...array_values(array_merge(array_fill_keys(array_keys($cols), null), $fila))];
    }
    if ($mapa === null) throw new ErrorApi('El archivo no tiene filas de datos.', 422);
    $ubicacion = $lector->getReturn() ?? [];
    if ($faltan) {
        throw new ErrorApi('La estructura del archivo no corresponde a ' . $base . '. Faltan las columnas: ' . implode(', ', $faltan)
            . '. Verifique que sea el archivo correcto y que la primera fila tenga los encabezados.', 422);
    }
    if (!$filas) throw new ErrorApi('Ninguna fila tiene los datos obligatorios. No se cargó nada.', 422);

    // Cambios frente a la versión anterior (antes de borrar sus datos en las bases que reemplazan)
    $cambios = in_array($base, BASES_CON_CAMBIOS, true) ? base_registrar_cambios($pdo, $base, $cargaId, $filas) : null;
    if (!$guardaVersiones) $pdo->prepare("DELETE FROM {$def['tabla']} WHERE carga_id <> ?")->execute([$cargaId]);
    $n = insertar_masivo($pdo, $def['tabla'], ['carga_id', ...array_keys($cols)], $filas, 300);

    $avisos = [];
    foreach ($sinValor as $columna => $cuantas) $avisos[] = "$cuantas fila(s) con «sin dato» en $columna: se guardan vacías (se identifican por las demás columnas)";
    return ['filas' => $n, 'omitidas' => $numOmitidas, 'duplicados' => $numDup,
            'detalle_omitidas' => $omitidas, 'detalle_duplicados' => [...$avisos, ...$duplicados],
            'columnas_leidas' => array_values($mapa), 'llave' => str_replace('|', ' + ', $def['llave']),
            'hoja_leida' => $ubicacion['hoja'] ?? null, 'fila_encabezado' => $ubicacion['fila_encabezado'] ?? null, 'cambios' => $cambios];
}
