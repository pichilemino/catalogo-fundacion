<?php
/** Buscar una obra en el catálogo de otra biblioteca y copiar su registro (catalogación por copia). */
declare(strict_types=1);
require __DIR__ . '/comun.php';
require __DIR__ . '/../inc/externos.php';
exigir_sesion();

$fuente = isset(FUENTES[$_GET['fuente'] ?? '']) ? $_GET['fuente'] : 'uchile';
$tipo   = isset(TIPOS_EXTERNOS[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : 'todo';
$q      = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 200) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!token_ok()) {
        avisar('La sesión expiró. Intenta otra vez.');
        ir('externos.php');
    }
    $guardados = $_SESSION['externos'] ?? [];
    $elegidos  = array_map('intval', (array) ($_POST['elegir'] ?? []));
    $sel       = array_values(array_intersect_key($guardados, array_flip($elegidos)));
    if (!$sel) {
        avisar('No marcaste ningún registro.');
        ir('externos.php?' . http_build_query(['fuente' => $fuente, 'tipo' => $tipo, 'q' => $q]));
    }
    [$nuevos, $actualizados, $ids] = importar_registros($sel, post('coleccion', 20), !empty($_POST['crear_ejemplar']), !empty($_POST['portadas']));
    unset($_SESSION['externos']);
    avisar("Listo: $nuevos obras nuevas" . ($actualizados ? " y $actualizados actualizadas" : '') . '. Revisa los datos y completa la ubicación.');
    ir(count($ids) === 1 ? 'editar.php?id=' . $ids[0] : 'index.php');
}

$resultados = [];
$error      = '';
if ($q !== '') {
    [$resultados, $error] = buscar_externo($fuente, $tipo, $q, 10);
    $_SESSION['externos'] = $resultados; // se guardan para importar sin volver a consultar
}
$ya = db()->prepare("SELECT id FROM registros WHERE fuente_id = ? AND fuente_id <> ''");

admin_cabecera('Otros catálogos', 'externos');
?>
<h1>Buscar en otros catálogos</h1>
<p class="admin-ayuda">Encuentra la obra en el catálogo de otra biblioteca y copia su registro; luego solo completas la ubicación. Para la Biblioteca Nacional, usa <a href="importar.php">Importar desde la BN</a>: su catálogo no permite consultas automáticas.</p>

<form method="get" class="admin-caja admin-externos">
    <label>Catálogo<select name="fuente"><?php foreach (FUENTES as $k => $v) : ?><option value="<?= e($k) ?>"<?= $fuente === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label>Buscar por<select name="tipo"><?php foreach (TIPOS_EXTERNOS as $k => $v) : ?><option value="<?= e($k) ?>"<?= $tipo === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label class="admin-externos__q">Texto o ISBN<input type="search" name="q" value="<?= e($q) ?>" required autofocus></label>
    <button class="boton">Buscar</button>
</form>

<?php if ($q !== '') : ?>
    <?php if ($error) : ?>
        <p class="admin-aviso admin-aviso--error" role="alert"><?= e($error) ?></p>
    <?php elseif (!$resultados) : ?>
        <p class="vacio">Sin resultados en <?= e(FUENTES[$fuente]) ?>. Prueba con menos palabras, por ISBN o en otro catálogo.</p>
    <?php else : ?>
        <form method="post">
            <?= campo_token() ?>
            <input type="hidden" name="fuente" value="<?= e($fuente) ?>">
            <ul class="externos">
                <?php foreach ($resultados as $i => $r) :
                    $ya->execute([$r['fuente_id']]);
                    $existe = (int) $ya->fetchColumn(); ?>
                    <li class="externo">
                        <label class="externo__marca"><input type="checkbox" name="elegir[]" value="<?= $i ?>"<?= $existe ? '' : ' checked' ?> aria-label="Copiar <?= e($r['titulo']) ?>"></label>
                        <span class="externo__portada"><?php if ($r['portada_url']) : ?><img src="<?= e($r['portada_url']) ?>" alt="" loading="lazy"><?php endif; ?></span>
                        <div>
                            <p class="externo__titulo"><?= e(titulo_completo($r)) ?></p>
                            <p class="externo__datos"><?= e(implode(' · ', array_filter([$r['autor'] ?: $r['responsabilidad'], trim($r['editorial'] . ' ' . $r['anio']), $r['descripcion']]))) ?></p>
                            <p class="externo__datos"><?= e(implode(' · ', array_filter([$r['isbn'] ? 'ISBN ' . $r['isbn'] : '', trim($r['dewey'] . ' ' . $r['cutter']), lineas($r['materias'])[0] ?? '']))) ?></p>
                            <?php if ($existe) : ?><p class="externo__ya">Ya está en el catálogo: <a href="editar.php?id=<?= $existe ?>">abrir</a> (si lo marcas, se actualiza)</p><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="admin-caja admin-rejilla admin-opciones">
                <label>Colección<select name="coleccion"><option value="">Según el registro</option><?php foreach (COLECCIONES as $k => $v) : ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
                <div>
                    <label class="admin-check"><input type="checkbox" name="crear_ejemplar" value="1" checked> Crear el ejemplar «c.1» en proceso técnico</label>
                    <label class="admin-check"><input type="checkbox" name="portadas" value="1" checked> Traer la portada (Open Library), si existe</label>
                </div>
            </div>
            <div class="admin-guardar"><button class="boton">Copiar los marcados al catálogo</button></div>
        </form>
    <?php endif; ?>
<?php endif; ?>
<?php admin_pie(); ?>
