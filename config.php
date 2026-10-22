<?php
/**
 * CONFIGURACIÓN — cómo conectarse a la base de datos y rutas del aplicativo.
 *
 * Las credenciales NUNCA van en este archivo. Se leen, en este orden:
 *   1. Variables de entorno (CI_DB_HOST, CI_DB_PORT, CI_DB_NAME, CI_DB_USER, CI_DB_PASS)
 *   2. config.local.php (no se comparte; ver config.local.example.php)
 *
 * Modo de prueba local (mismo patrón del aplicativo de Traslados): si existe
 * data/.local_test se usa un SQLite local en vez del MySQL real. Ese archivo NUNCA debe
 * existir en el servidor de producción. El SQLite se guarda FUERA de OneDrive (la sincronización
 * de OneDrive y SQLite no se llevan bien): %USERPROFILE%\CreacionMedicamentos_prueba\local_test.sqlite
 * (no en AppData: allí Windows redirige la escritura de apps empaquetadas y otros procesos no la ven),
 * o la ruta de CI_SQLITE_PATH. No afecta producción: allí se usa MySQL.
 */

date_default_timezone_set('America/Bogota');

const APP_NOMBRE  = 'Creación de Medicamentos';
const APP_VERSION = '0.1.0';

const LOCAL_TEST_MARKER = __DIR__ . '/data/.local_test';

/** Ruta del SQLite de prueba local (solo modo prueba). Crea la carpeta si no existe. */
function local_test_sqlite(): string {
    static $ruta = null;
    if ($ruta !== null) return $ruta;
    $local = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
    $ruta = (getenv('CI_SQLITE_PATH') ?: '') ?: ($local['CI_SQLITE_PATH'] ?? '');
    if ($ruta === '') {
        $base = getenv('USERPROFILE') ?: (getenv('HOME') ?: '');
        $ruta = $base !== '' ? $base . DIRECTORY_SEPARATOR . 'CreacionMedicamentos_prueba' . DIRECTORY_SEPARATOR . 'local_test.sqlite'
                             : __DIR__ . '/data/local_test.sqlite';
    }
    if (!is_dir(dirname($ruta))) mkdir(dirname($ruta), 0775, true);
    return $ruta;
}

function app_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $local = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
    $env = static fn(string $k, $def = null) => (getenv($k) !== false && getenv($k) !== '') ? getenv($k) : ($local[$k] ?? $def);

    $cfg = [
        'db_host' => $env('CI_DB_HOST', ''),
        'db_port' => (int) $env('CI_DB_PORT', 3306),
        'db_name' => $env('CI_DB_NAME', 'creacion_items'),
        'db_user' => $env('CI_DB_USER', ''),
        'db_pass' => $env('CI_DB_PASS', ''),
        // Excel vigente del proceso (fuente de los catálogos iniciales)
        'excel_catalogos' => $env('CI_EXCEL_CATALOGOS',
            getenv('USERPROFILE') . '/OneDrive - pharmaser.com.co/EQUIPO DE COMPRAS/creacion items/CREAR ITEMS - CSALDARRIAGA 2025.xlsx'),
        'excel_campos_tecnicos' => $env('CI_EXCEL_CAMPOS_TECNICOS',
            getenv('USERPROFILE') . '/OneDrive - pharmaser.com.co/EQUIPO DE COMPRAS/creacion items/0. doc base/items.xlsx'),
        'dir_salidas' => $env('CI_DIR_SALIDAS', __DIR__ . '/salidas'),
        'sesion_minutos' => (int) $env('CI_SESION_MINUTOS', 60),
        // PHP de consola para las actualizaciones en segundo plano (INVIMA, Mutual). En el servidor PHP_BINARY es el de la web.
        'php_cli' => $env('CI_PHP_CLI', ''),
        // Publicación a sistemas externos (nt_version / nt_snapshot): llaves de API separadas por coma (mínimo 32 caracteres cada una).
        'publicacion_llaves' => $env('CI_PUBLICACION_API_KEYS', ''),
        // '1' = nt_snapshot incluye precios y tarifas; por defecto salen en null.
        'publicacion_con_precios' => $env('CI_PUBLICACION_CON_PRECIOS', '0'),
    ];
    return $cfg;
}

function modo_prueba_local(): bool {
    if (!is_file(LOCAL_TEST_MARKER)) return false;
    if (PHP_SAPI === 'cli') return true;
    // por web solo en el equipo local: en el servidor de TIC el marcador no activa SQLite aunque se copie por error
    $host = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($host, '.localhost');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (modo_prueba_local()) {
        $pdo = new PDO('sqlite:' . local_test_sqlite(), null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        // Mientras un proceso en segundo plano escribe (p.ej. INVIMA), las demás escrituras esperan
        // hasta 10 s en vez de fallar con "database is locked".
        $pdo->exec('PRAGMA busy_timeout = 10000');
        return $pdo;
    }
    $c = app_config();
    if ($c['db_host'] === '' || $c['db_user'] === '') {
        throw new RuntimeException('Base de datos no configurada: defina config.local.php o variables de entorno CI_DB_*.');
    }
    $dsn = "mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], $opts + [
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT          => 5,
    ]);
    return $pdo;
}

function db_driver(): string {
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME); // 'sqlite' | 'mysql'
}

function ahora(): string {
    return date('Y-m-d H:i:s');
}
