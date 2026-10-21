<?php
/**
 * actualizar_mutual.php — descarga y lee la maestra de Mutual Ser (reemplaza MaestraMutualser.R). Solo por consola.
 *
 * Lo lanza el aplicativo en segundo plano (botón "Actualizar ahora"), y también se puede programar
 * (Programador de tareas de Windows / cron del servidor):
 *     php actualizar_mutual.php
 * Opciones: --forzar (lee el archivo aunque no haya cambiado)
 *           --proceso=N --usuario=N (uso interno del aplicativo)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
set_time_limit(0);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/datos.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mutual.php';

$op = getopt('', ['proceso::', 'usuario::', 'forzar']);
$pdo = db();
$usuario = isset($op['usuario']) ? (int) $op['usuario'] : null;
$proceso = isset($op['proceso']) ? (int) $op['proceso'] : 0;

if (!$proceso) { // ejecución programada: crea su propio registro de proceso
    if (proceso_en_curso($pdo, 'MEDICAMENTOS_MUTUAL')) { echo "Ya hay una actualización de la maestra Mutual en curso.\n"; exit(0); }
    $proceso = insertar($pdo, 'sis_proceso', ['tipo' => 'MEDICAMENTOS_MUTUAL', 'estado' => 'EN_CURSO', 'avance' => 0, 'mensaje' => 'Iniciando (programado)…',
        'usuario_id' => null, 'iniciado_en' => ahora(), 'actualizado_en' => ahora()]);
}
try {
    $r = mutual_actualizar($pdo, $proceso, $usuario, isset($op['forzar']));
    echo empty($r['sin_cambios']) ? sprintf("OK: %d filas, %d omitidas, %d s\n", $r['filas'], $r['omitidas'], $r['segundos'])
                                  : "Sin cambios: la maestra de Mutual Ser está al día.\n";
    exit(0);
} catch (Throwable $e) {
    $pdo->prepare("UPDATE sis_proceso SET estado = 'ERROR', mensaje = ?, terminado_en = ?, actualizado_en = ? WHERE id = ?")
        ->execute([mb_substr('Error: ' . $e->getMessage(), 0, 500), ahora(), ahora(), $proceso]);
    fwrite(STDERR, $e . "\n");
    exit(1);
}
