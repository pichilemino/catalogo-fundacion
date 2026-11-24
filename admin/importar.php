<?php
/**
 * Importar registros de la Biblioteca Nacional de Chile: se pega el texto del correo que envía su catálogo
 * («Guardar o Enviar» → formato MARC con etiquetas) o se sube como archivo .txt. Primero se muestra una vista previa.
 */
declare(strict_types=1);
require __DIR__ . '/comun.php';
require __DIR__ . '/../inc/externos.php';
exigir_sesion();

$texto  = '';
$vista  = [];
$accion = $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!token_ok()) {
        avisar('La sesión expiró. Intenta otra vez.');
        ir('importar.php');
    }
    $texto = post('texto', 2_000_000);
    if (!empty($_FILES['archivo']['tmp_name']) && ($_FILES['archivo']['error'] ?? 1) === UPLOAD_ERR_OK && $_FILES['archivo']['size'] < 5_000_000) {
        $texto = (string) file_get_contents($_FILES['archivo']['tmp_name']);
    }
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
    }
    $regs = array_map('marc_a_registro', marc_leer_texto($texto));

    if ($accion === 'importar' && $regs) {
        $elegidos = array_map('intval', (array) ($_POST['elegir'] ?? []));
        $sel      = array_values(array_filter($regs, fn ($i) => in_array($i, $elegidos, true), ARRAY_FILTER_USE_KEY));
        [$nuevos, $actualizados] = importar_registros($sel, post('coleccion', 20), !empty($_POST['crear_ejemplar']), !empty($_POST['portadas']));
        avisar("Importación lista: $nuevos obras nuevas" . ($actualizados ? " y $actualizados actualizadas" : '') . '.');
        ir('index.php');
    }
    $vista = $regs;
}

admin_cabecera('Importar', 'importar');
?>
<h1>Importar desde la Biblioteca Nacional</h1>

<?php if (!$vista) : ?>
    <p class="admin-ayuda">Busca la obra en <a href="https://www.bncatalogo.cl/" rel="noopener">el catálogo de la Biblioteca Nacional</a>, márcala y usa «Guardar o Enviar» con el formato de etiquetas MARC. Pega aquí el texto del correo que llega (puede traer muchos registros) o súbelo como archivo de texto.</p>
    <?php if ($accion === 'ver') : ?><p class="admin-aviso admin-aviso--error" role="alert">No se encontraron registros en ese texto. Revisa que incluya las líneas con etiquetas (245, 100, 264…).</p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="admin-caja admin-subir">
        <?= campo_token() ?>
        <label>Texto de los registros<textarea name="texto" rows="14" placeholder="Número de registro: 1&#10;FMT  BK&#10;24510  |a Historia de Pichilemu / |c Diego Alberto Grez Cañete."><?= e($texto) ?></textarea></label>
        <label>O un archivo .txt<input type="file" name="archivo" accept=".txt,text/plain"></label>
        <button class="boton" name="accion" value="ver">Ver qué se importará</button>
    </form>
<?php else : ?>
    <p class="admin-ayuda">Se encontraron <?= count($vista) ?> registros. Desmarca los que no quieras. Si un registro ya estaba importado, se actualizan sus datos y se conservan sus ejemplares.</p>
    <form method="post">
        <?= campo_token() ?>
        <textarea name="texto" hidden><?= e($texto) ?></textarea>
        <div class="tabla-envoltura">
            <table class="catalogo">
                <thead><tr><th scope="col"><span class="screen-reader-text">Importar</span></th><th scope="col">Título</th><th scope="col">Autor</th><th scope="col">Año</th><th scope="col">Clasificación</th><th scope="col">Estado</th></tr></thead>
                <tbody>
                    <?php
                    $ya = db()->prepare('SELECT COUNT(*) FROM registros WHERE fuente_id = ? AND fuente_id <> ""');
                    foreach ($vista as $i => $reg) :
                        $ya->execute([$reg['fuente_id']]);
                        $existe = (int) $ya->fetchColumn() > 0; ?>
                        <tr>
                            <td><input type="checkbox" name="elegir[]" value="<?= $i ?>" checked aria-label="Importar <?= e($reg['titulo']) ?>"></td>
                            <td><strong><?= e(titulo_completo($reg)) ?></strong><br><small><?= e($reg['edicion']) ?></small></td>
                            <td><?= e($reg['autor'] ?: $reg['responsabilidad']) ?></td>
                            <td class="cifra"><?= e($reg['anio']) ?></td>
                            <td class="cifra"><?= e(trim($reg['dewey'] . ' ' . $reg['cutter'])) ?></td>
                            <td><?= $existe ? 'Ya está: se actualizará' : 'Nueva' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="admin-caja admin-rejilla admin-opciones">
            <label>Colección<select name="coleccion"><option value="">Según el registro (libros a Biblioteca, seriadas a Hemeroteca)</option><?php foreach (COLECCIONES as $k => $v) : ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label class="admin-check"><input type="checkbox" name="crear_ejemplar" value="1" checked> Crear el ejemplar «c.1» en proceso técnico para cada obra nueva</label>
            <label class="admin-check"><input type="checkbox" name="portadas" value="1" checked> Buscar la portada en Open Library por ISBN</label>
        </div>
        <div class="admin-guardar"><button class="boton" name="accion" value="importar">Importar los marcados</button> <a href="importar.php">Volver a empezar</a></div>
    </form>
<?php endif; ?>
<?php admin_pie(); ?>
