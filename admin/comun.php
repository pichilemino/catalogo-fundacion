<?php
/** Sesión, contraseña y diseño del panel del catálogo. */
declare(strict_types=1);
require_once __DIR__ . '/../inc/config.php';

const CLAVE_ARCHIVO = __DIR__ . '/../data/clave.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('catalogo_admin');
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function token(): string
{
    if (empty($_SESSION['token'])) {
        $_SESSION['token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['token'];
}

function token_ok(): bool
{
    return is_string($_POST['token'] ?? null) && hash_equals(token(), $_POST['token']);
}

function campo_token(): string
{
    return '<input type="hidden" name="token" value="' . e(token()) . '">';
}

function dentro(): bool
{
    return !empty($_SESSION['admin']);
}

/** Las páginas del panel (salvo la de entrada) exigen sesión. */
function exigir_sesion(): void
{
    if (!dentro()) {
        header('Location: index.php');
        exit;
    }
}

function avisar(string $texto): void
{
    $_SESSION['aviso'] = $texto;
}

function ir(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function post(string $clave, int $max = 2000): string
{
    $v = $_POST[$clave] ?? '';
    return is_string($v) ? mb_substr(str_replace("\r\n", "\n", trim($v)), 0, $max) : '';
}

function admin_cabecera(string $titulo, string $actual = ''): void
{
    $aviso = $_SESSION['aviso'] ?? '';
    unset($_SESSION['aviso']);
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($titulo) ?> · Panel del catálogo</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Newsreader:opsz,wght@6..72,500&display=swap">
    <link rel="stylesheet" href="<?= e(recurso('assets/css/estilo.css', '../')) ?>">
    <link rel="stylesheet" href="<?= e(recurso('assets/css/catalogo.css', '../')) ?>">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="admin">
<header class="cabecera">
    <div class="cabecera__barra envoltura">
        <a class="marca" href="../index.php"><img class="marca__logo" src="../assets/img/logo.svg" alt="Fundación Región de Colchagua" width="648" height="200"><span class="marca__nombre">Panel del catálogo</span></a>
        <?php if (dentro()) : ?>
            <nav class="admin-menu" aria-label="Secciones">
                <a href="index.php"<?= $actual === 'obras' ? ' aria-current="page"' : '' ?>>Obras</a>
                <a href="editar.php"<?= $actual === 'nueva' ? ' aria-current="page"' : '' ?>>Nueva obra</a>
                <a href="externos.php"<?= $actual === 'externos' ? ' aria-current="page"' : '' ?>>Otros catálogos</a>
                <a href="importar.php"<?= $actual === 'importar' ? ' aria-current="page"' : '' ?>>Importar desde la BN</a>
                <form method="post" action="index.php"><?= campo_token() ?><button class="admin-salir" name="accion" value="salir">Salir</button></form>
            </nav>
        <?php endif; ?>
    </div>
</header>
<main class="envoltura admin-cuerpo">
    <?php if ($aviso) : ?><p class="admin-aviso" role="status"><?= e($aviso) ?></p><?php endif; ?>
    <?php
}

function admin_pie(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/**
 * Importa registros ya convertidos (de la BN, la U. de Chile, etc.). Si uno ya estaba (mismo identificador de origen),
 * actualiza sus datos y conserva colección, enlace, portada y ejemplares. Devuelve [nuevos, actualizados, ids].
 */
function importar_registros(array $regs, string $coleccion, bool $crear_ejemplar, bool $con_portada): array
{
    $pdo = db();
    $buscar = $pdo->prepare("SELECT id FROM registros WHERE fuente_id = ? AND fuente_id <> ''");
    $ins_ej = $pdo->prepare("INSERT INTO ejemplares (registro_id, descripcion, estado, orden) VALUES (?, 'c.1', 'En proceso técnico', 0)");
    $nuevos = $actualizados = 0;
    $ids = [];
    $pendientes_portada = [];
    $pdo->beginTransaction();
    foreach ($regs as $reg) {
        if (isset(COLECCIONES[$coleccion])) {
            $reg['coleccion'] = $coleccion;
        }
        $buscar->execute([$reg['fuente_id'] ?? '']);
        $existente = (int) $buscar->fetchColumn();
        if ($existente) {
            $actual = registro($existente);
            $reg['enlace']    = $actual['enlace'];
            $reg['coleccion'] = $actual['coleccion'];
            registro_guardar($reg, $existente);
            $ids[] = $existente;
            $actualizados++;
            continue;
        }
        $nid = registro_guardar($reg);
        if ($crear_ejemplar) {
            $ins_ej->execute([$nid]);
        }
        $ids[] = $nid;
        $nuevos++;
        if ($con_portada) {
            $pendientes_portada[$nid] = $reg['portada_url'] ?? '' ?: (function_exists('portada_por_isbn') ? portada_por_isbn((string) ($reg['isbn'] ?? '')) : '');
        }
    }
    $pdo->commit();
    // Las portadas se descargan después de guardar, para no dejar la base bloqueada mientras tanto.
    foreach ($pendientes_portada as $nid => $url) {
        if ($url !== '' && ($bytes = http_get($url, 12)) !== '') {
            portada_guardar($nid, $bytes);
        }
    }
    return [$nuevos, $actualizados, $ids];
}
