<?php
/**
 * actualizar_estructura.php — aplica los cambios de estructura y semillas pendientes SIN borrar
 * datos (usuarios, contraseñas, cargas). Funciona igual en SQLite (prueba) y MySQL (servidor).
 * iniciar_local.bat lo ejecuta automáticamente antes de iniciar el servidor.
 *
 * Uso:  php actualizar_estructura.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require_once __DIR__ . '/lib/instalar.php';
instalar(false);
