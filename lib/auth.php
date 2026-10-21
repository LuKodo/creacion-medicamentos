<?php
/**
 * SEGURIDAD — sesión, permisos por módulo/acción (USUARIOS -> PERFILES -> PERMISOS -> MÓDULOS),
 * protección CSRF y bloqueo por intentos fallidos. Todo se valida en el servidor.
 */

const MAX_INTENTOS = 5;
const MINUTOS_BLOQUEO = 15;

function iniciar_sesion_php(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('CI_SESION');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path'     => '/',
    ]);
    session_start();

    $limite = app_config()['sesion_minutos'] * 60;
    if (isset($_SESSION['ultimo_uso']) && time() - $_SESSION['ultimo_uso'] > $limite) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['ultimo_uso'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function usuario_actual(): ?array {
    return $_SESSION['usuario'] ?? null;
}

function permisos_de(int $usuarioId): array {
    $st = db()->prepare(
        "SELECT DISTINCT pe.codigo
           FROM seg_usuario_perfil up
           JOIN seg_perfil p         ON p.id = up.perfil_id AND p.activo = 1
           JOIN seg_perfil_permiso pp ON pp.perfil_id = p.id
           JOIN seg_permiso pe       ON pe.id = pp.permiso_id
          WHERE up.usuario_id = ?");
    $st->execute([$usuarioId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function perfiles_de(int $usuarioId): array {
    $st = db()->prepare("SELECT p.codigo FROM seg_usuario_perfil up JOIN seg_perfil p ON p.id = up.perfil_id WHERE up.usuario_id = ?");
    $st->execute([$usuarioId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function login(string $usuario, string $clave): array {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM seg_usuario WHERE usuario = ?');
    $st->execute([mb_strtolower(trim($usuario))]);
    $u = $st->fetch();
    $generico = 'Usuario o contraseña incorrectos.';

    if (!$u || !(int) $u['activo']) {
        auditar(null, 'LOGIN_FALLIDO', 'seg_usuario', null, null, ['usuario' => $usuario]);
        throw new ErrorApi($generico, 401);
    }
    if ($u['bloqueado_hasta'] && $u['bloqueado_hasta'] > ahora()) {
        throw new ErrorApi('Usuario bloqueado temporalmente por intentos fallidos. Intente más tarde.', 423);
    }
    if (!password_verify($clave, $u['password_hash'])) {
        $intentos = (int) $u['intentos_fallidos'] + 1;
        $bloqueo = $intentos >= MAX_INTENTOS ? date('Y-m-d H:i:s', time() + MINUTOS_BLOQUEO * 60) : null;
        $pdo->prepare('UPDATE seg_usuario SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id = ?')
            ->execute([$bloqueo ? 0 : $intentos, $bloqueo, $u['id']]);
        auditar((int) $u['id'], 'LOGIN_FALLIDO', 'seg_usuario', $u['id']);
        throw new ErrorApi($generico, 401);
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE seg_usuario SET password_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    }
    $pdo->prepare('UPDATE seg_usuario SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = ? WHERE id = ?')
        ->execute([ahora(), $u['id']]);

    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id' => (int) $u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'],
        'debe_cambiar_clave' => (bool) $u['debe_cambiar_clave'],
    ];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    auditar((int) $u['id'], 'LOGIN', 'seg_usuario', $u['id']);
    return $_SESSION['usuario'];
}

function logout(): void {
    if ($u = usuario_actual()) auditar($u['id'], 'LOGOUT', 'seg_usuario', $u['id']);
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Exige sesión activa (y que ya haya cambiado la clave temporal, salvo $permitirClaveTemporal). */
function requerir_login(bool $permitirClaveTemporal = false): array {
    $u = usuario_actual();
    if (!$u) throw new ErrorApi('Sesión no iniciada o vencida.', 401);
    // Revalida contra la base en cada petición: si el administrador lo desactiva, pierde acceso de inmediato.
    $st = db()->prepare('SELECT activo, debe_cambiar_clave FROM seg_usuario WHERE id = ?');
    $st->execute([$u['id']]);
    $fila = $st->fetch();
    if (!$fila || !(int) $fila['activo']) { logout(); throw new ErrorApi('Usuario inactivo.', 401); }
    $_SESSION['usuario']['debe_cambiar_clave'] = (bool) $fila['debe_cambiar_clave'];
    if (!$permitirClaveTemporal && $fila['debe_cambiar_clave']) throw new ErrorApi('Debe cambiar su contraseña temporal.', 403);
    return $_SESSION['usuario'];
}

function requerir_permiso(string $permiso): array {
    $u = requerir_login();
    if (!in_array($permiso, permisos_de($u['id']), true)) {
        throw new ErrorApi("No tiene permiso para esta acción ($permiso).", 403);
    }
    return $u;
}

function verificar_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) throw new ErrorApi('Token de seguridad inválido. Recargue la página.', 419);
}

/** Política mínima: 10+ caracteres con letras y números. */
function validar_clave_nueva(string $clave): void {
    if (mb_strlen($clave) < 10 || !preg_match('/[A-Za-z]/', $clave) || !preg_match('/\d/', $clave)) {
        throw new ErrorApi('La contraseña debe tener al menos 10 caracteres e incluir letras y números.', 422);
    }
}

final class ErrorApi extends RuntimeException {
    public function __construct(string $mensaje, public int $http = 400) { parent::__construct($mensaje); }
}
