<?php
/**
 * verificar_entorno.php — chequeo de salud del aplicativo (solo lectura, no modifica nada).
 * Sirve en local (SQLite) y en el servidor (MySQL). Lo puede ejecutar TIC tras cada despliegue.
 *
 * Uso:  php verificar_entorno.php
 * Sale con código 1 si hay algún ERROR (para usarlo en procesos automáticos).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/schema.php';

$pdo = db();
$q = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$res = ['OK' => 0, 'AVISO' => 0, 'ERROR' => 0];
$chequeo = function (string $nombre, bool $ok, string $detalle = '', string $nivel = 'ERROR') use (&$res) {
    $estado = $ok ? 'OK' : $nivel;
    $res[$estado]++;
    printf("[%-5s] %s%s\n", $estado, $nombre, $detalle !== '' ? " — $detalle" : '');
};

echo 'Motor: ' . db_driver() . (modo_prueba_local() ? ' (MODO PRUEBA LOCAL)' : '') . "\n\n== Estructura\n";
$faltan = [];
foreach (array_keys(esquema_tablas()) as $t) {
    try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); } catch (Throwable) { $faltan[] = $t; }
}
$chequeo('Tablas del esquema', !$faltan, $faltan ? 'faltan: ' . implode(', ', $faltan) : count(esquema_tablas()) . ' tablas');
$colFalta = array_filter(esquema_columnas_nuevas(), fn($c) => !columna_existe($pdo, $c[0], $c[1]));
$chequeo('Columnas agregadas por migración', !$colFalta, $colFalta ? count($colFalta) . ' pendientes (ejecute actualizar_estructura.php)' : '');
if (db_driver() === 'sqlite') {
    $chequeo('Integridad de la base (PRAGMA integrity_check)', $q('PRAGMA integrity_check') === 'ok');
    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    $chequeo('Llaves foráneas', !$fk, $fk ? count($fk) . ' violaciones' : '');
    $chequeo('Base de prueba fuera de OneDrive', stripos(local_test_sqlite(), 'OneDrive') === false, local_test_sqlite());
}

echo "\n== Seguridad\n";
$admins = (int) $q("SELECT COUNT(*) FROM seg_usuario u JOIN seg_usuario_perfil up ON up.usuario_id = u.id JOIN seg_perfil p ON p.id = up.perfil_id WHERE p.codigo = 'ADMIN' AND u.activo = 1");
$chequeo('Al menos un Administrador activo', $admins > 0, "$admins");
$soloAdmin = ['BASE.ACTUALIZAR', 'CATALOGO.EDITAR', 'USUARIO.GESTIONAR', 'PERFIL.ASIGNAR', 'PARAMETRO.ADMINISTRAR', 'PRECIO.ASIGNAR', 'PRECIO.APROBAR_EXCEPCION', 'NT.GESTIONAR'];
$lista = "'" . implode("','", $soloAdmin) . "'";
$fuga = $pdo->query("SELECT p.codigo AS perfil, pe.codigo AS permiso FROM seg_perfil_permiso pp JOIN seg_perfil p ON p.id = pp.perfil_id JOIN seg_permiso pe ON pe.id = pp.permiso_id WHERE p.codigo <> 'ADMIN' AND pe.codigo IN ($lista)")->fetchAll();
$chequeo('Permisos exclusivos solo en Administrador', !$fuga, $fuga ? implode(', ', array_map(fn($f) => "{$f['perfil']}:{$f['permiso']}", $fuga)) : '');
$esperado = ['CREACION' => ['PRODUCTO.CREAR', 'PRODUCTO.CONSULTAR', 'PRODUCTO.DESCARGAR', 'NT.CONSULTAR', 'NT.DESCARGAR', 'PRECIO.VER', 'CORRECCION.CONSULTAR', 'NT.VER_FUERA_CONTRATO'],
             'CONSULTA' => ['PRODUCTO.CONSULTAR', 'PRODUCTO.DESCARGAR', 'NT.CONSULTAR', 'NT.DESCARGAR', 'PRECIO.VER', 'CORRECCION.CONSULTAR'],
             'CONSULTA_SP' => ['PRODUCTO.CONSULTAR', 'NT.CONSULTAR', 'NT.DESCARGAR']];
foreach ($esperado as $perfil => $perms) {
    $st = $pdo->prepare('SELECT pe.codigo FROM seg_perfil_permiso pp JOIN seg_perfil p ON p.id = pp.perfil_id JOIN seg_permiso pe ON pe.id = pp.permiso_id WHERE p.codigo = ? ORDER BY pe.codigo');
    $st->execute([$perfil]);
    $tiene = $st->fetchAll(PDO::FETCH_COLUMN); sort($perms);
    $chequeo("Matriz 13.5 — perfil $perfil", $tiene === $perms, $tiene === $perms ? '' : 'tiene: ' . implode(', ', $tiene), 'AVISO');
}
$temp = (int) $q("SELECT COUNT(*) FROM seg_usuario WHERE usuario LIKE 'zz.%'");
$chequeo('Sin usuarios temporales de prueba', $temp === 0, $temp ? "$temp usuario(s) zz.*" : '');
// usuarios de prueba de perfiles (prueba.creacion, prueba.consulta): solo para el equipo local, no deben quedar activos en producción
$prueba = (int) $q("SELECT COUNT(*) FROM seg_usuario WHERE usuario LIKE 'prueba.%' AND activo = 1");
$chequeo('Sin usuarios de prueba activos (prueba.*)', $prueba === 0, $prueba ? "$prueba usuario(s) prueba.*: inactívelos antes de producción" : '', 'AVISO');
$claveInicial = is_file(__DIR__ . '/data/CLAVE_INICIAL_ADMIN.txt');
$chequeo('Archivo de clave inicial retirado', !$claveInicial, $claveInicial ? 'borre data/CLAVE_INICIAL_ADMIN.txt' : '', 'AVISO');
$chequeo('Sin credenciales en config.php', !preg_match("/DB_PASS\s*=\s*'[^']+'|'CI_DB_PASS'\s*=>\s*'[^']+'/", file_get_contents(__DIR__ . '/config.php')));

echo "\n== Consistencia de catálogos (necesaria para validar y crear)\n";
$sinInv = $pdo->query("SELECT valor FROM cat_lista_valor v WHERE lista = 'TIPO_MEDICAMENTO' AND activo = 1 AND NOT EXISTS (SELECT 1 FROM cat_tipo_inventario t WHERE UPPER(t.tipo_medicamento) = UPPER(v.valor) AND t.activo = 1)")->fetchAll(PDO::FETCH_COLUMN);
$chequeo('Cada tipo de medicamento tiene tipo de inventario', !$sinInv, $sinInv ? implode(', ', $sinInv) : '');
$chequeo('Grupos impositivos SIN IVA / CON IVA', (int) $q("SELECT COUNT(*) FROM cat_grupo_impositivo WHERE etiqueta_iva IN ('SIN IVA','CON IVA') AND activo = 1") === 2);
$chequeo('Subplanes activos', (int) $q('SELECT COUNT(*) FROM cat_subplan WHERE activo = 1') > 0, (string) $q('SELECT COUNT(*) FROM cat_subplan WHERE activo = 1'));
$envSinLista = $pdo->query("SELECT DISTINCT SUBSTR(presentacion_agrupada, 1, INSTR(presentacion_agrupada, ' X ') - 1) FROM cat_presentacion WHERE activo = 1 AND INSTR(presentacion_agrupada, ' X ') > 0
    AND SUBSTR(presentacion_agrupada, 1, INSTR(presentacion_agrupada, ' X ') - 1) NOT IN (SELECT valor FROM cat_lista_valor WHERE lista = 'ENVASE')")->fetchAll(PDO::FETCH_COLUMN);
$chequeo('Envases de las presentaciones existen en el catálogo Envase', !$envSinLista, $envSinLista ? 'no están en la lista: ' . implode(', ', $envSinLista) : '', 'AVISO');
// patrones: GLOB en SQLite, REGEXP en MySQL (mismo resultado)
$tiene = fn(string $col, string $clase) => db_driver() === 'mysql' ? "$col REGEXP '[^$clase]'" : "$col GLOB '*[^$clase]*'";
$soloUno = fn(string $col) => db_driver() === 'mysql' ? "$col REGEXP '^[^0-9A-Za-z]$'" : "$col GLOB '[^0-9A-Za-z]'";
$medNoNum = (int) $q("SELECT COUNT(*) FROM cat_molecula WHERE activo = 1 AND (codigo_medicar IS NULL OR codigo_medicar = '' OR " . $tiene('codigo_medicar', '0-9') . ")");
$chequeo('Código Medicar numérico en COD GEN activos', $medNoNum === 0, $medNoNum ? "$medNoNum registros" : '');
$codMal = (int) $q("SELECT COUNT(*) FROM cat_molecula WHERE " . $tiene('cod_gen_audifarma', '0-9A-Za-z'));
$chequeo('Códigos Audifarma solo letras y números', $codMal === 0, $codMal ? "$codMal registros" : '');
$signos = (int) $q("SELECT COUNT(*) FROM cat_molecula WHERE activo = 1 AND (" . implode(' OR ', array_map($soloUno, ['forma_farmaceutica', 'grupo_farmacologico', 'via_administracion', 'principio_activo', 'concentracion_agrupada'])) . ")");
$chequeo("COD GEN sin valores de solo signos ('.', '-')", $signos === 0, $signos ? "$signos registros (corregir con el cargue por Excel)" : '', 'AVISO');
$frio = (int) $q("SELECT COUNT(*) FROM cat_molecula WHERE activo = 1 AND cadena_frio NOT IN ('SI','NO','NO APLICA')");
$chequeo('Cadena de frío con valores permitidos', $frio === 0, $frio ? "$frio registros" : '', 'AVISO');
$pos = (int) $q("SELECT COUNT(*) FROM cat_molecula WHERE activo = 1 AND pos_no_pos NOT IN ('POS','NO POS')");
$chequeo('POS / NO POS asignado en COD GEN activos', $pos === 0, $pos ? "$pos registros (ASIGNAR u otro)" : '', 'AVISO');
$huerf = (int) $q('SELECT COUNT(*) FROM cat_lista_valor WHERE lista NOT IN (SELECT codigo FROM cat_lista)');
$chequeo('Valores de lista sin catálogo', $huerf === 0, $huerf ? "$huerf" : '');

$repMed = (int) $q("SELECT COUNT(*) FROM (SELECT codigo_medicar FROM cat_molecula WHERE codigo_medicar IS NOT NULL AND codigo_medicar <> '' GROUP BY codigo_medicar HAVING COUNT(*) > 1) x");
$chequeo('Código Medicar sin repetir en COD GEN', $repMed === 0, $repMed ? "$repMed código(s) Medicar con varios COD GEN: corríjalos en Catálogos › COD GEN" : '');

echo "\n== Bases externas\n";
foreach ($pdo->query("SELECT b.nombre, b.modo, b.dias_alerta, c.estado, c.filas, c.creado_en FROM ref_base b
        LEFT JOIN ref_carga c ON c.tipo_base = b.codigo AND c.vigente = 1 WHERE b.activo = 1 ORDER BY b.orden")->fetchAll() as $b) {
    if ($b['modo'] === 'AUTOMATICA' && !$b['estado']) { $chequeo("Base {$b['nombre']}", true, 'automática (pendiente de implementar)'); continue; }
    $dias = $b['creado_en'] ? (int) floor((time() - strtotime($b['creado_en'])) / 86400) : null;
    $ok = $b['estado'] === 'LEIDA' && $dias <= (int) $b['dias_alerta'];
    $det = !$b['estado'] ? 'pendiente de cargue' : ($b['estado'] !== 'LEIDA' ? "estado {$b['estado']} (sin leer)" : number_format((int) $b['filas'], 0, ',', '.') . " filas leídas, hace $dias día(s)"
        . ($dias > (int) $b['dias_alerta'] ? " — DESACTUALIZADA (máx. {$b['dias_alerta']})" : ''));
    $chequeo("Base {$b['nombre']}", $ok, $det, 'AVISO');
}

echo "\n== Maestra Tarifaria\n";
$nNt = (int) $q('SELECT COUNT(*) FROM nt_item WHERE activo = 1');
$chequeo('Maestra Tarifaria cargada', $nNt > 0, $nNt ? number_format($nNt, 0, ',', '.') . ' productos activos' : 'vacía: importarla desde el Excel (pantalla Maestra Tarifaria)', 'AVISO');
if ($nNt) {
    require_once __DIR__ . '/lib/auth.php';
    require_once __DIR__ . '/lib/nota_tecnica.php';
    $r = nt_listar($pdo, [], 1, 1)['resumen'];
    $chequeo('Maestra Tarifaria sin errores de validación', $r['ERROR'] === 0, "{$r['ERROR']} con error · {$r['CON ALERTA']} con alerta (ver pantalla Maestra Tarifaria)", 'AVISO');
}

printf("\nResultado: %d OK · %d avisos · %d errores\n", $res['OK'], $res['AVISO'], $res['ERROR']);
exit($res['ERROR'] ? 1 : 0);
