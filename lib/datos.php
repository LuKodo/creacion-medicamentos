<?php
/** Utilidades de acceso a datos comunes a MySQL y SQLite. */

/** Inserta o actualiza una fila según sus columnas llave (para semillas y parámetros). */
function upsert(PDO $pdo, string $tabla, array $fila, array $llaves): void {
    $where = implode(' AND ', array_map(fn($k) => "$k = ?", $llaves));
    $st = $pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE $where");
    $st->execute(array_map(fn($k) => $fila[$k], $llaves));
    if ((int) $st->fetchColumn() > 0) {
        $resto = array_diff(array_keys($fila), $llaves);
        if (!$resto) return;
        $set = implode(', ', array_map(fn($k) => "$k = ?", $resto));
        $pdo->prepare("UPDATE $tabla SET $set WHERE $where")
            ->execute([...array_map(fn($k) => $fila[$k], $resto), ...array_map(fn($k) => $fila[$k], $llaves)]);
    } else {
        insertar($pdo, $tabla, $fila);
    }
}

/** Inserta sin actualizar si ya existe (semillas que el administrador puede modificar después). */
function insertar_si_no_existe(PDO $pdo, string $tabla, array $fila, array $llaves): bool {
    $where = implode(' AND ', array_map(fn($k) => "$k = ?", $llaves));
    $st = $pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE $where");
    $st->execute(array_map(fn($k) => $fila[$k], $llaves));
    if ((int) $st->fetchColumn() > 0) return false;
    insertar($pdo, $tabla, $fila);
    return true;
}

function insertar(PDO $pdo, string $tabla, array $fila): int {
    $cols = implode(', ', array_keys($fila));
    $marcas = implode(', ', array_fill(0, count($fila), '?'));
    $pdo->prepare("INSERT INTO $tabla ($cols) VALUES ($marcas)")->execute(array_values($fila));
    return (int) $pdo->lastInsertId();
}

/** Inserción masiva por bloques (una sentencia por cada N filas, no una por fila). */
function insertar_masivo(PDO $pdo, string $tabla, array $columnas, iterable $filas, int $bloque = 400): int {
    $total = 0;
    $buffer = [];
    $cols = implode(', ', $columnas);
    $marca = '(' . implode(', ', array_fill(0, count($columnas), '?')) . ')';
    $vaciar = function () use (&$buffer, &$total, $pdo, $tabla, $cols, $marca) {
        if (!$buffer) return;
        $sql = "INSERT INTO $tabla ($cols) VALUES " . implode(', ', array_fill(0, count($buffer), $marca));
        $pdo->prepare($sql)->execute(array_merge(...$buffer));
        $total += count($buffer);
        $buffer = [];
    };
    foreach ($filas as $f) {
        $buffer[] = array_values($f);
        if (count($buffer) >= $bloque) $vaciar();
    }
    $vaciar();
    return $total;
}

function parametro(string $clave, ?string $defecto = null): ?string {
    static $cache = [];
    if (!array_key_exists($clave, $cache)) {
        $st = db()->prepare('SELECT valor FROM cat_parametro WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        $cache[$clave] = $v === false ? null : $v;
    }
    return $cache[$clave] ?? $defecto;
}

/** Registro de auditoría de cualquier cambio (quién, qué, antes, después). */
function auditar(?int $usuarioId, string $accion, ?string $entidad = null, $entidadId = null, $antes = null, $despues = null): void {
    $j = static fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
    insertar(db(), 'aud_log', [
        'usuario_id' => $usuarioId,
        'accion'     => $accion,
        'entidad'    => $entidad,
        'entidad_id' => $entidadId === null ? null : (string) $entidadId,
        'antes'      => $j($antes),
        'despues'    => $j($despues),
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        'creado_en'  => ahora(),
    ]);
}
