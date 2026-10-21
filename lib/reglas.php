<?php
/**
 * Reglas de validación configurables por el Administrador (pedido del usuario, 2026-10-13).
 *
 * - Activar / inactivar: una regla inactiva no se aplica (ni en Crear productos ni en la Maestra Tarifaria).
 * - Reglas personalizadas: el Administrador elige el ámbito (Crear productos / Maestra Tarifaria), el campo, la condición,
 *   el valor, la severidad y el mensaje. El motor las evalúa en cada validación como cualquier otra regla.
 *   Códigos: P01, P02… (Crear productos) y NTP01, NTP02… (Maestra Tarifaria).
 * Las reglas del sistema (programadas) no se editan: solo se activan o inactivan.
 */
require_once __DIR__ . '/datos.php';

/** Condiciones disponibles: código => [nombre, ¿pide valor?]. */
const REGLA_CONDICIONES = [
    'OBLIGATORIO' => ['No puede estar vacío', false],
    'IGUAL'       => ['Debe ser igual a', true],
    'DISTINTO'    => ['No puede ser igual a', true],
    'CONTIENE'    => ['Debe contener', true],
    'NO_CONTIENE' => ['No puede contener', true],
    'EN_LISTA'    => ['Debe estar en la lista (separe con ;)', true],
    'NO_EN_LISTA' => ['No puede estar en la lista (separe con ;)', true],
    'FORMATO'     => ['Debe cumplir el formato (expresión regular)', true],
    'LARGO_MAX'   => ['Largo máximo (caracteres)', true],
    'MAYOR_IGUAL' => ['Número mayor o igual a', true],
    'MENOR_IGUAL' => ['Número menor o igual a', true],
];

/** Campos que se pueden validar por ámbito: campo => etiqueta. */
function regla_campos(): array {
    $producto = [];
    foreach (SOL_CAMPOS_REGLAS as $k => $t) $producto[$k] = $t;
    return [
        'PRODUCTO' => $producto,
        'NT' => ['contrato' => 'CONTRATO', 'fecha' => 'FECHA DE CREACION', 'cum' => 'CUM', 'cum_facturar' => 'CUM FACTURAR', 'cod_auf' => 'COD AUF',
                 'tipo_producto' => 'TIPO PRODUCTO', 'descripcion' => 'DESCRIPCION', 'cod_gen_siesa' => 'COD GEN SIESA', 'cod_mutual' => 'COD MUTUAL',
                 'precio_venta' => 'PRECIO DE VENTA', 'iva' => 'IVA',
                 // datos que salen de las bases (SIESA / Maestra Mutual) para la línea
                 'atc' => 'ATC', 'referencia_siesa' => 'PLU', 'descripcion_siesa' => 'DESCRIPCION COMPLETA'],
    ];
}
/** Campos del producto (mismos de la hoja FORMATO). */
const SOL_CAMPOS_REGLAS = [
    'codigo_generico_audifarma' => 'CODIGO GENERICO AUDIFARMA', 'iva' => 'IVA', 'cum' => 'CUM', 'codigo_cum_auxiliar' => 'CODIGO CUM AUXILIAR',
    'registro_sanitario' => 'REGISTRO SANITARIO', 'codigo_ium' => 'CODIGO IUM', 'nombre_comercial' => 'NOMBRE COMERCIAL', 'laboratorio' => 'LABORATORIO',
    'codigo_atc' => 'CODIGO ATC', 'tipo_medicamento' => 'TIPO DE MEDICAMENTO', 'fecha_reg_invima' => 'FECHA REG INVIMA', 'estado_invima' => 'ESTADO INVIMA',
    'presentacion_1' => 'PRESENTACION 1', 'unidad_presentacion' => 'UNIDAD DE PRESENTACION', 'forma_farmaceutica_rips' => 'FORMA FARMACEUTICA RIPS',
    'concentracion_rips' => 'CONCENTRACION MEDICAMENTO RIPS', 'unidad_medida_rips' => 'UNIDAD MEDIDA RIPS',
];

/** Códigos de las reglas inactivas (no se aplican). */
function reglas_inactivas(PDO $pdo): array {
    return array_fill_keys($pdo->query('SELECT codigo FROM proc_regla WHERE activa = 0')->fetchAll(PDO::FETCH_COLUMN), true);
}

/** Reglas personalizadas activas de un ámbito (PRODUCTO / NT). */
function reglas_personalizadas(PDO $pdo, string $ambito): array {
    $st = $pdo->prepare('SELECT codigo, descripcion, severidad, accion_sugerida, campo, condicion, valor FROM proc_regla WHERE personalizada = 1 AND activa = 1 AND ambito = ? ORDER BY orden, codigo');
    $st->execute([$ambito]);
    return $st->fetchAll();
}

/** ¿El valor cumple la regla? Un campo vacío solo lo valida «No puede estar vacío». */
function regla_cumple(array $r, $valor): bool {
    $v = trim((string) ($valor ?? ''));
    $u = fn($x) => mb_strtoupper(trim((string) $x));
    if ($r['condicion'] === 'OBLIGATORIO') return $v !== '';
    if ($v === '') return true;
    $ref = (string) ($r['valor'] ?? '');
    $lista = array_values(array_filter(array_map($u, explode(';', $ref)), fn($x) => $x !== ''));
    return match ($r['condicion']) {
        'IGUAL'       => $u($v) === $u($ref),
        'DISTINTO'    => $u($v) !== $u($ref),
        'CONTIENE'    => str_contains($u($v), $u($ref)),
        'NO_CONTIENE' => !str_contains($u($v), $u($ref)),
        'EN_LISTA'    => in_array($u($v), $lista, true),
        'NO_EN_LISTA' => !in_array($u($v), $lista, true),
        'FORMATO'     => (bool) @preg_match('/' . str_replace('/', '\/', $ref) . '/u', $v),
        'LARGO_MAX'   => mb_strlen($v) <= (int) $ref,
        'MAYOR_IGUAL' => is_numeric($v) && (float) $v >= (float) $ref,
        'MENOR_IGUAL' => is_numeric($v) && (float) $v <= (float) $ref,
        default       => true,
    };
}

/** Texto de lo esperado (para el resultado de la validación). */
function regla_esperado(array $r): string {
    $c = REGLA_CONDICIONES[$r['condicion']][0] ?? $r['condicion'];
    return REGLA_CONDICIONES[$r['condicion']][1] ?? false ? "$c {$r['valor']}" : $c;
}

/** Crea o edita una regla personalizada. */
function regla_guardar(PDO $pdo, array $in, int $usuarioId): array {
    $codigo = strtoupper(trim((string) ($in['codigo'] ?? '')));
    $ambito = (string) ($in['ambito'] ?? '');
    $campos = regla_campos();
    if (!isset($campos[$ambito])) throw new ErrorApi('Elija el ámbito: Crear productos o Maestra Tarifaria.', 422);
    $campo = (string) ($in['campo'] ?? '');
    if (!isset($campos[$ambito][$campo])) throw new ErrorApi('Elija el campo que se valida.', 422);
    $condicion = (string) ($in['condicion'] ?? '');
    if (!isset(REGLA_CONDICIONES[$condicion])) throw new ErrorApi('Elija la condición.', 422);
    $valor = trim((string) ($in['valor'] ?? ''));
    if (REGLA_CONDICIONES[$condicion][1] && $valor === '') throw new ErrorApi('Esa condición necesita un valor.', 422);
    if ($condicion === 'FORMATO' && @preg_match('/' . str_replace('/', '\/', $valor) . '/u', '') === false) throw new ErrorApi('El formato (expresión regular) no es válido.', 422);
    if (in_array($condicion, ['LARGO_MAX', 'MAYOR_IGUAL', 'MENOR_IGUAL'], true) && !is_numeric($valor)) throw new ErrorApi('Esa condición necesita un número.', 422);
    $severidad = (string) ($in['severidad'] ?? '');
    if (!in_array($severidad, ['ERROR', 'ALERTA'], true)) throw new ErrorApi('Elija la severidad: Error o Alerta.', 422);
    $descripcion = trim((string) ($in['descripcion'] ?? ''));
    if ($descripcion === '' || mb_strlen($descripcion) > 300) throw new ErrorApi('Escriba qué valida la regla (máximo 300 caracteres).', 422);
    $accion = mb_substr(trim((string) ($in['accion_sugerida'] ?? '')), 0, 300) ?: null;
    $datos = ['descripcion' => ($ambito === 'NT' && !str_starts_with($descripcion, 'Maestra Tarifaria:') ? 'Maestra Tarifaria: ' : '') . $descripcion,
        'severidad' => $severidad, 'base_comparada' => 'Regla personalizada', 'accion_sugerida' => $accion,
        'ambito' => $ambito, 'campo' => $campo, 'condicion' => $condicion, 'valor' => $valor !== '' ? $valor : null];
    if ($codigo !== '') {
        $st = $pdo->prepare('SELECT * FROM proc_regla WHERE codigo = ?'); $st->execute([$codigo]);
        $antes = $st->fetch();
        if (!$antes) throw new ErrorApi('La regla no existe.', 404);
        if (!(int) $antes['personalizada']) throw new ErrorApi('Las reglas del sistema no se editan: solo se activan o inactivan.', 422);
        if ($antes['ambito'] !== $ambito) throw new ErrorApi('El ámbito de una regla no se cambia: cree una nueva.', 422);
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($datos)));
        $pdo->prepare("UPDATE proc_regla SET $set WHERE codigo = ?")->execute([...array_values($datos), $codigo]);
    } else {
        $pref = $ambito === 'NT' ? 'NTP' : 'P';
        $n = 0;
        foreach ($pdo->query('SELECT codigo FROM proc_regla WHERE personalizada = 1')->fetchAll(PDO::FETCH_COLUMN) as $c)
            if (preg_match('/^' . $pref . '(\d+)$/', $c, $m)) $n = max($n, (int) $m[1]);
        $codigo = sprintf('%s%02d', $pref, $n + 1);
        $orden = (int) $pdo->query('SELECT COALESCE(MAX(orden), 0) FROM proc_regla')->fetchColumn() + 1;
        $antes = null;
        insertar($pdo, 'proc_regla', ['codigo' => $codigo] + $datos + ['activa' => 1, 'orden' => $orden, 'personalizada' => 1, 'creado_por' => $usuarioId, 'creado_en' => ahora()]);
    }
    $st = $pdo->prepare('SELECT * FROM proc_regla WHERE codigo = ?'); $st->execute([$codigo]);
    $despues = $st->fetch();
    auditar($usuarioId, $antes ? 'REGLA_EDITAR' : 'REGLA_CREAR', 'proc_regla', null, $antes ?: null, $despues);
    return $despues;
}

/** Activa o inactiva una regla (del sistema o personalizada). */
function regla_activar(PDO $pdo, string $codigo, bool $activa, int $usuarioId): array {
    $st = $pdo->prepare('SELECT * FROM proc_regla WHERE codigo = ?'); $st->execute([$codigo]);
    $antes = $st->fetch();
    if (!$antes) throw new ErrorApi('La regla no existe.', 404);
    $pdo->prepare('UPDATE proc_regla SET activa = ? WHERE codigo = ?')->execute([$activa ? 1 : 0, $codigo]);
    auditar($usuarioId, $activa ? 'REGLA_ACTIVAR' : 'REGLA_INACTIVAR', 'proc_regla', null, ['codigo' => $codigo, 'activa' => (int) $antes['activa']], ['codigo' => $codigo, 'activa' => $activa ? 1 : 0]);
    return ['codigo' => $codigo, 'activa' => $activa];
}
