<?php
/** Entrada al panel (crear contraseña la primera vez) y lista de obras. */
declare(strict_types=1);
require __DIR__ . '/comun.php';

$clave_hash = is_file(CLAVE_ARCHIVO) ? (string) (include CLAVE_ARCHIVO) : '';
$accion     = $_POST['accion'] ?? '';
$error      = '';

if ($accion === 'crear' && $clave_hash === '' && token_ok()) {
    $c1 = (string) ($_POST['clave'] ?? '');
    if (mb_strlen($c1) < 10) {
        $error = 'La contraseña debe tener al menos 10 caracteres.';
    } elseif ($c1 !== ($_POST['clave2'] ?? '')) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        db(); // crea la carpeta data y la base
        file_put_contents(CLAVE_ARCHIVO, "<?php\nreturn " . var_export(password_hash($c1, PASSWORD_DEFAULT), true) . ";\n", LOCK_EX);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        avisar('Contraseña creada. Ya puedes catalogar.');
        ir('index.php');
    }
}
if ($accion === 'entrar' && $clave_hash !== '' && token_ok()) {
    if (password_verify((string) ($_POST['clave'] ?? ''), $clave_hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        ir('index.php');
    }
    sleep(2);
    $error = 'Contraseña incorrecta.';
}
if ($accion === 'salir' && token_ok()) {
    $_SESSION = [];
    session_destroy();
    ir('index.php');
}

if (!dentro()) {
    admin_cabecera('Entrar');
    ?>
    <form method="post" class="admin-caja admin-acceso">
        <?php if ($clave_hash === '') : ?>
            <h1>Crear contraseña</h1>
            <p>Es la primera vez que se abre el panel. Elige una contraseña de al menos 10 caracteres y guárdala en un lugar seguro.</p>
        <?php else : ?>
            <h1>Entrar</h1>
        <?php endif; ?>
        <?php if ($error) : ?><p class="admin-aviso admin-aviso--error" role="alert"><?= e($error) ?></p><?php endif; ?>
        <?= campo_token() ?>
        <label>Contraseña<input type="password" name="clave" required autocomplete="<?= $clave_hash === '' ? 'new-password' : 'current-password' ?>" autofocus></label>
        <?php if ($clave_hash === '') : ?><label>Repite la contraseña<input type="password" name="clave2" required autocomplete="new-password"></label><?php endif; ?>
        <button class="boton" name="accion" value="<?= $clave_hash === '' ? 'crear' : 'entrar' ?>"><?= $clave_hash === '' ? 'Crear y entrar' : 'Entrar' ?></button>
    </form>
    <?php
    admin_pie();
    exit;
}

// Lista de obras (con la misma búsqueda del catálogo público)
$p = [
    'q'         => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
    'coleccion' => is_string($_GET['coleccion'] ?? null) ? $_GET['coleccion'] : '',
    'orden'     => 'titulo',
    'pagina'    => max(1, (int) ($_GET['pagina'] ?? 1)),
];
[$filas, $total] = buscar($p);
$paginas = max(1, (int) ceil($total / POR_PAGINA));
admin_cabecera('Obras', 'obras');
?>
<div class="admin-titulo">
    <h1>Obras <span class="admin-cuenta"><?= number_format($total, 0, ',', '.') ?></span></h1>
    <div class="admin-acciones"><a class="boton" href="editar.php">Nueva obra</a><a class="boton boton--claro" href="externos.php">Otros catálogos</a><a class="boton boton--claro" href="importar.php">Importar desde la BN</a></div>
</div>
<form method="get" class="admin-filtro">
    <input type="search" name="q" value="<?= e($p['q']) ?>" placeholder="Buscar por título, autor, materia…" aria-label="Buscar">
    <select name="coleccion" aria-label="Colección"><option value="">Todas las colecciones</option><?php foreach (COLECCIONES as $k => $v) : ?><option value="<?= e($k) ?>"<?= $p['coleccion'] === $k ? ' selected' : '' ?>><?= e($k === 'hemeroteca' ? 'Hemeroteca' : $v) ?></option><?php endforeach; ?></select>
    <button class="boton boton--claro">Filtrar</button>
</form>
<?php if ($filas) : ?>
    <div class="tabla-envoltura">
        <table class="catalogo">
            <thead><tr><th scope="col">Título</th><th scope="col">Autor</th><th scope="col">Año</th><th scope="col">Colección</th><th scope="col">Ejemplares</th></tr></thead>
            <tbody>
                <?php foreach ($filas as $r) : ?>
                    <tr>
                        <td><a href="editar.php?id=<?= (int) $r['id'] ?>"><?= e(titulo_completo($r)) ?></a></td>
                        <td><?= e($r['autor']) ?></td>
                        <td class="cifra"><?= e($r['anio']) ?></td>
                        <td><?= e($r['coleccion'] === 'hemeroteca' ? 'Hemeroteca' : 'Biblioteca') ?></td>
                        <td class="cifra"><?= (int) $r['n_ejemplares'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($paginas > 1) : ?>
        <nav class="paginas" aria-label="Páginas">
            <?php if ($p['pagina'] > 1) : ?><a href="?<?= e(http_build_query(['q' => $p['q'], 'coleccion' => $p['coleccion'], 'pagina' => $p['pagina'] - 1])) ?>">← Anterior</a><?php endif; ?>
            <span>Página <?= $p['pagina'] ?> de <?= $paginas ?></span>
            <?php if ($p['pagina'] < $paginas) : ?><a href="?<?= e(http_build_query(['q' => $p['q'], 'coleccion' => $p['coleccion'], 'pagina' => $p['pagina'] + 1])) ?>">Siguiente →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php else : ?>
    <p class="vacio">No hay obras<?= $p['q'] !== '' ? ' con esa búsqueda' : ' todavía. Empieza con «Nueva obra» o importa registros de la Biblioteca Nacional' ?>.</p>
<?php endif; ?>
<?php admin_pie(); ?>
