<?php
/** Catalogar: crear o editar una obra y sus ejemplares (o tomos, en la hemeroteca). */
declare(strict_types=1);
require __DIR__ . '/comun.php';
exigir_sesion();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$r  = $id ? registro($id) : null;
if ($id && !$r) {
    avisar('Esa obra no existe.');
    ir('index.php');
}

/** Campos del formulario: campo => [etiqueta, varias líneas, ayuda]. */
const FORM = [
    'Título y autoría' => [
        'titulo' => ['Título', false, ''], 'resto_titulo' => ['Subtítulo', false, ''],
        'responsabilidad' => ['Mención de responsabilidad', false, 'Como aparece en la portada: «Diego Grez Cañete ; prólogo de…»'],
        'autor' => ['Autor principal', false, 'Apellidos, Nombres, año de nacimiento-'],
        'otros_autores' => ['Otros autores', true, 'Uno por línea, con su función entre paréntesis'],
    ],
    'Publicación' => [
        'edicion' => ['Edición', false, ''], 'lugar' => ['Lugar', false, ''], 'editorial' => ['Editorial', false, ''],
        'anio' => ['Año', false, ''], 'impresion' => ['Impresión', false, 'Lugar e imprenta, si se conocen'],
        'descripcion' => ['Descripción física', false, '292 páginas : ilustraciones ; 23 cm'], 'serie' => ['Serie o colección', false, ''],
    ],
    'Contenido y clasificación' => [
        'materias' => ['Materias', true, 'Una por línea: «Pichilemu (Chile) — Historia»'], 'notas' => ['Notas', true, 'Una por línea'],
        'dewey' => ['Dewey', false, ''], 'cutter' => ['Cutter', false, ''], 'isbn' => ['ISBN', false, ''], 'issn' => ['ISSN', false, ''],
    ],
    'Hemeroteca' => [
        'periodicidad' => ['Periodicidad', false, 'Diario, semanal, mensual…'], 'cobertura' => ['Años en la colección', false, 'Por ejemplo: 1985-1990; 2013-2026'],
    ],
    'Enlaces y fuente' => [
        'enlace' => ['Versión digital', false, 'Dirección en colecciones.colchagua.org'], 'fuente' => ['Fuente del registro', false, ''],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!token_ok()) {
        avisar('La sesión expiró. Intenta otra vez.');
        ir('editar.php' . ($id ? '?id=' . $id : ''));
    }
    if (($_POST['accion'] ?? '') === 'borrar' && $id) {
        db()->prepare('DELETE FROM registros WHERE id = ?')->execute([$id]);
        avisar('Obra eliminada.');
        ir('index.php');
    }
    $datos = ['coleccion' => post('coleccion', 20), 'formato' => post('formato', 20)];
    foreach (FORM as $campos) {
        foreach ($campos as $c => $d) {
            $datos[$c] = post($c, $d[1] ? 4000 : 500);
        }
    }
    $datos['fuente_id'] = $r['fuente_id'] ?? '';
    $datos['marc']      = $r['marc'] ?? '';
    if ($datos['titulo'] === '') {
        avisar('El título es obligatorio.');
        ir('editar.php' . ($id ? '?id=' . $id : ''));
    }
    $pdo = db();
    $pdo->beginTransaction();
    $id = registro_guardar($datos, $id ?: null);
    // Ejemplares: se reescriben en el orden del formulario.
    $pdo->prepare('DELETE FROM ejemplares WHERE registro_id = ?')->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO ejemplares (registro_id, codigo, descripcion, ubicacion, estado, nota, orden) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $orden = 0;
    foreach ((array) ($_POST['ej'] ?? []) as $x) {
        if (!is_array($x) || !empty($x['borrar'])) {
            continue;
        }
        $f = [];
        foreach (['codigo' => 60, 'descripcion' => 200, 'ubicacion' => 120, 'estado' => 40, 'nota' => 300] as $k => $max) {
            $f[$k] = is_string($x[$k] ?? null) ? mb_substr(trim($x[$k]), 0, $max) : '';
        }
        if ($f['codigo'] === '' && $f['descripcion'] === '' && $f['ubicacion'] === '') {
            continue;
        }
        $f['estado'] = in_array($f['estado'], ESTADOS, true) ? $f['estado'] : ESTADOS[0];
        $ins->execute([$id, $f['codigo'], $f['descripcion'], $f['ubicacion'], $f['estado'], $f['nota'], $orden++]);
    }
    $pdo->commit();
    avisar('Obra guardada.');
    ir('editar.php?id=' . $id);
}

$r   = $r ?? ['coleccion' => $_GET['coleccion'] ?? 'biblioteca', 'formato' => 'libro'];
$ejs = $id ? ejemplares($id) : [];
admin_cabecera($id ? 'Editar obra' : 'Nueva obra', $id ? 'obras' : 'nueva');
?>
<div class="admin-titulo">
    <h1><?= $id ? e($r['titulo']) : 'Nueva obra' ?></h1>
    <?php if ($id) : ?><div class="admin-acciones"><a class="boton boton--claro" href="../registro.php?id=<?= $id ?>">Ver en el catálogo ↗</a></div><?php endif; ?>
</div>
<form method="post" class="admin-ficha">
    <?= campo_token() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <fieldset class="admin-caja admin-rejilla">
        <legend>Tipo</legend>
        <label>Colección<select name="coleccion"><?php foreach (COLECCIONES as $k => $v) : ?><option value="<?= e($k) ?>"<?= ($r['coleccion'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label>Formato<select name="formato"><?php foreach (FORMATOS as $k => $v) : ?><option value="<?= e($k) ?>"<?= ($r['formato'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    </fieldset>
    <?php foreach (FORM as $grupo => $campos) : ?>
        <fieldset class="admin-caja admin-rejilla">
            <legend><?= e($grupo) ?></legend>
            <?php foreach ($campos as $c => [$etq, $largo, $ayuda]) : ?>
                <label class="<?= $largo || in_array($c, ['titulo', 'responsabilidad', 'enlace', 'fuente', 'descripcion'], true) ? 'admin-ancho' : '' ?>"><?= e($etq) ?>
                    <?php if ($largo) : ?>
                        <textarea name="<?= e($c) ?>" rows="3"><?= e($r[$c] ?? '') ?></textarea>
                    <?php else : ?>
                        <input type="text" name="<?= e($c) ?>" value="<?= e($r[$c] ?? '') ?>"<?= $c === 'titulo' ? ' required' : '' ?>>
                    <?php endif; ?>
                    <?php if ($ayuda) : ?><small><?= e($ayuda) ?></small><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>

    <fieldset class="admin-caja">
        <legend>Ejemplares o tomos</legend>
        <p class="admin-ayuda">Cada ejemplar físico (c.1, c.2) o, en la hemeroteca, cada tomo empastado («Enero–marzo de 2015»). Hay filas vacías al final para agregar.</p>
        <div class="admin-tabla">
            <div class="admin-fila admin-fila--ej admin-fila--cab"><span>Descripción</span><span>Código</span><span>Ubicación</span><span>Estado</span><span>Nota</span><span></span></div>
            <?php foreach (array_merge($ejs, array_fill(0, 3, [])) as $i => $x) : ?>
                <div class="admin-fila admin-fila--ej">
                    <input type="text" name="ej[<?= $i ?>][descripcion]" value="<?= e($x['descripcion'] ?? '') ?>" aria-label="Descripción" placeholder="<?= $i === count($ejs) ? 'c.1' : '' ?>">
                    <input type="text" name="ej[<?= $i ?>][codigo]" value="<?= e($x['codigo'] ?? '') ?>" aria-label="Código">
                    <input type="text" name="ej[<?= $i ?>][ubicacion]" value="<?= e($x['ubicacion'] ?? '') ?>" aria-label="Ubicación" placeholder="<?= $i === count($ejs) ? 'Estante A, caja 3' : '' ?>">
                    <select name="ej[<?= $i ?>][estado]" aria-label="Estado"><?php foreach (ESTADOS as $s) : ?><option<?= ($x['estado'] ?? '') === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
                    <input type="text" name="ej[<?= $i ?>][nota]" value="<?= e($x['nota'] ?? '') ?>" aria-label="Nota">
                    <?php if ($x) : ?><label class="admin-check"><input type="checkbox" name="ej[<?= $i ?>][borrar]" value="1"> Quitar</label><?php else : ?><span></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <?php if (!empty($r['marc'])) : ?>
        <details class="admin-caja"><summary>Registro MARC original (solo lectura)</summary><pre class="marc"><?= e($r['marc']) ?></pre></details>
    <?php endif; ?>

    <div class="admin-guardar">
        <button class="boton" name="accion" value="guardar">Guardar</button>
        <?php if ($id) : ?><button class="admin-borrar" name="accion" value="borrar" formnovalidate onclick="return confirm('¿Eliminar esta obra y sus ejemplares? No se puede deshacer.')">Eliminar obra</button><?php endif; ?>
    </div>
</form>
<?php admin_pie(); ?>
