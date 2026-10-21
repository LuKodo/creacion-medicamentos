<?php
/**
 * actualizar_invima.php — descarga y consolida INVIMA (datos.gov.co). Solo por consola.
 *
 * Lo lanza el aplicativo en segundo plano (botón "Actualizar ahora"), y también se puede programar
 * (Programador de tareas de Windows / cron del servidor) para que sea automático, p.ej. cada día:
 *     php actualizar_invima.php
 * Opciones: --forzar (descarga aunque las fuentes no hayan cambiado)
 *           --proceso=N --usuario=N (uso interno del aplicativo)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
set_time_limit(0);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/datos.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/invima.php';

$op = getopt('', ['proceso::', 'usuario::', 'forzar']);
$pdo = db();
$usuario = isset($op['usuario']) ? (int) $op['usuario'] : null;
$proceso = isset($op['proceso']) ? (int) $op['proceso'] : 0;

if (!$proceso) { // ejecución programada: crea su propio registro de proceso
    if (proceso_en_curso($pdo, 'INVIMA')) { echo "Ya hay una actualización de INVIMA en curso.\n"; exit(0); }
    $proceso = insertar($pdo, 'sis_proceso', ['tipo' => 'INVIMA', 'estado' => 'EN_CURSO', 'avance' => 0, 'mensaje' => 'Iniciando (programado)…',
        'usuario_id' => null, 'iniciado_en' => ahora(), 'actualizado_en' => ahora()]);
}
try {
    $r = invima_actualizar($pdo, $proceso, $usuario, isset($op['forzar']));
    echo empty($r['sin_cambios']) ? sprintf("OK: %d CUM, %d registros, %d s\n", $r['cum_consolidados'], $r['registros_consolidados'], $r['segundos'])
                                  : "Sin cambios en las fuentes: la base está al día.\n";
    exit(0);
} catch (Throwable $e) {
    $pdo->prepare("UPDATE sis_proceso SET estado = 'ERROR', mensaje = ?, terminado_en = ?, actualizado_en = ? WHERE id = ?")
        ->execute([mb_substr('Error: ' . $e->getMessage(), 0, 500), ahora(), ahora(), $proceso]);
    fwrite(STDERR, $e . "\n");
    exit(1);
}
