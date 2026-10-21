<?php
/**
 * setup_db.php — instalación en MySQL (servidor). Lo ejecuta TIC UNA vez cuando se autorice
 * la migración. Seguro de re-ejecutar: crea lo que falte, no borra ni duplica.
 *
 * Requisitos: config.local.php (o variables CI_DB_*) con un usuario restringido a la base
 * del aplicativo, y que NO exista data/.local_test.
 *
 * Uso:  php setup_db.php            -> estructura + semillas + catálogos del Excel
 *       php setup_db.php --sin-catalogos
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
if (is_file(__DIR__ . '/data/.local_test')) {
    exit("Está activo el modo de prueba local (data/.local_test). Desactívelo con: php setup_local_test_db.php --off" . PHP_EOL);
}
require_once __DIR__ . '/lib/instalar.php';
instalar(!in_array('--sin-catalogos', $argv, true));
