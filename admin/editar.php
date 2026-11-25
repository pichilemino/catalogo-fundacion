<?php
/** Catalogar: crear o editar una obra y sus ejemplares (o tomos, en la hemeroteca). */
declare(strict_types=1);
require __DIR__ . '/comun.php';
require __DIR__ . '/../inc/externos.php';
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
        portada_borrar($id);
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
    $ins = $pdo->prepare('INSERT INTO ejemplares (registro_id, tipo, numero, fecha_desde, fecha_hasta, codigo, descripcion, ubicacion, estado, nota, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $es_seriada = $datos['coleccion'] === 'hemeroteca' || in_array($datos['formato'], ['periodico', 'revista'], true);
    $fecha = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    $orden = 0;
    foreach ((array) ($_POST['ej'] ?? []) as $x) {
        if (!is_array($x) || !empty($x['borrar'])) {
            continue;
        }
        $f = [];
        foreach (['codigo' => 60, 'descripcion' => 200, 'ubicacion' => 120, 'estado' => 40, 'nota' => 300, 'numero' => 40, 'tipo' => 20] as $k => $max) {
            $f[$k] = is_string($x[$k] ?? null) ? mb_substr(trim($x[$k]), 0, $max) : '';
        }
        $f['desde'] = $fecha($x['fecha_desde'] ?? '');
        $f['hasta'] = $fecha($x['fecha_hasta'] ?? '');
        if ($f['codigo'] === '' && $f['descripcion'] === '' && $f['ubicacion'] === '' && $f['numero'] === '' && $f['desde'] === '') {
            continue;
        }
        $f['tipo']   = isset(TIPOS_UNIDAD[$f['tipo']]) ? $f['tipo'] : 'ejemplar';
        if (!$es_seriada) { // en libros, el tipo y las fechas no aplican
            $f['tipo']   = 'ejemplar';
            $f['numero'] = $f['desde'] = $f['hasta'] = '';
        }
        $f['hasta']  = $f['tipo'] === 'tomo' && $f['hasta'] > $f['desde'] ? $f['hasta'] : '';
        $f['estado'] = in_array($f['estado'], ESTADOS, true) ? $f['estado'] : ESTADOS[0];
        $ins->execute([$id, $f['tipo'], $f['numero'], $f['desde'], $f['hasta'], $f['codigo'], $f['descripcion'], $f['ubicacion'], $f['estado'], $f['nota'], $orden++]);
    }
    // Generador de series (hemeroteca): muchos números o tomos de una vez.
    $gen_desde = $fecha($_POST['gen_desde'] ?? '');
    $gen_hasta = $fecha($_POST['gen_hasta'] ?? '');
    $pasos = ['diaria' => '+1 day', 'semanal' => '+1 week', 'quincenal' => '+2 weeks', 'mensual' => '+1 month', 'bimestral' => '+2 months', 'trimestral' => '+3 months', 'semestral' => '+6 months', 'anual' => '+1 year'];
    $generados = 0;
    $gen_tipo  = ($_POST['gen_tipo'] ?? '') === 'tomo' ? 'tomo' : 'numero';
    if ($es_seriada && $gen_desde && $gen_hasta && $gen_hasta >= $gen_desde && isset($pasos[$_POST['gen_cada'] ?? ''])) {
        $num  = ctype_digit((string) ($_POST['gen_numero'] ?? '')) ? (int) $_POST['gen_numero'] : null;
        $ubic = post('gen_ubicacion', 120);
        $est  = in_array($_POST['gen_estado'] ?? '', ESTADOS, true) ? $_POST['gen_estado'] : ESTADOS[0];
        $t    = new DateTimeImmutable($gen_desde);
        $fin  = new DateTimeImmutable($gen_hasta);
        while ($t <= $fin && $generados < 1000) {
            $sig   = $t->modify($pasos[$_POST['gen_cada']]);
            $hasta = $gen_tipo === 'tomo' ? min($sig->modify('-1 day'), $fin)->format('Y-m-d') : '';
            $ins->execute([$id, $gen_tipo, $num !== null ? (string) $num++ : '', $t->format('Y-m-d'), $hasta, '', '', $ubic, $est, '', $orden++]);
            $generados++;
            $t = $sig;
        }
    }
    // Años en la colección: se calculan solos a partir de las fechas, si las hay.
    $cob = cobertura_calculada(ejemplares($id));
    if ($cob !== '') {
        $pdo->prepare('UPDATE registros SET cobertura = ? WHERE id = ?')->execute([$cob, $id]);
    }
    $pdo->commit();
    // Portada (opcional): quitar, subir una imagen o traerla de Open Library por ISBN.
    $aviso = 'Obra guardada.' . ($generados ? " Se agregaron $generados " . ($gen_tipo === 'tomo' ? 'tomos.' : 'números.') : '');
    if (!empty($_POST['portada_quitar'])) {
        portada_borrar($id);
    } elseif (!empty($_FILES['portada']['tmp_name']) && ($_FILES['portada']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $aviso .= portada_guardar($id, (string) file_get_contents($_FILES['portada']['tmp_name'])) ? '' : ' La portada no se guardó: usa una foto JPG, PNG o WebP de menos de 8 MB.';
    } elseif (!empty($_POST['portada_ol'])) {
        $url   = portada_por_isbn($datos['isbn']);
        $bytes = $url ? http_get($url, 12) : '';
        $aviso .= ($bytes !== '' && portada_guardar($id, $bytes)) ? ' Portada traída de Open Library.' : ' Open Library no tiene portada para ese ISBN.';
    }
    avisar($aviso);
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
<form method="post" class="admin-ficha" enctype="multipart/form-data">
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
        <legend>Portada <small>(opcional)</small></legend>
        <div class="admin-portada">
            <div class="admin-portada__img"><?php if (!empty($r['portada'])) : ?><img src="../<?= e($r['portada']) ?>" alt="Portada actual"><?php else : ?>Sin portada<?php endif; ?></div>
            <div class="admin-portada__acciones">
                <label>Subir una foto de la portada (JPG, PNG o WebP)<input type="file" name="portada" accept="image/jpeg,image/png,image/webp"></label>
                <label class="admin-check"><input type="checkbox" name="portada_ol" value="1"> Buscarla en Open Library por el ISBN</label>
                <?php if (!empty($r['portada'])) : ?><label class="admin-check"><input type="checkbox" name="portada_quitar" value="1"> Quitar la portada</label><?php endif; ?>
            </div>
        </div>
    </fieldset>

    <?php $seriada = ($r['coleccion'] ?? '') === 'hemeroteca' || in_array($r['formato'] ?? '', ['periodico', 'revista'], true); ?>
    <fieldset class="admin-caja unidades<?= $seriada ? ' es-seriada' : '' ?>">
        <legend><span class="solo-libro">Ejemplares</span><span class="solo-seriada">Números y tomos</span></legend>
        <p class="admin-ayuda solo-libro">Cada ejemplar físico: c.1, c.2…</p>
        <p class="admin-ayuda solo-seriada">Agrega cada <strong>número suelto</strong> con su número y fecha, o cada <strong>tomo o período</strong> (agrupado o empastado) con su fecha de inicio y de término. Si dejas la descripción vacía, se arma sola, y los «Años en la colección» se calculan con las fechas.</p>
        <div class="admin-tabla">
            <div class="admin-fila admin-fila--ej admin-fila--cab"><span class="solo-seriada">Tipo</span><span class="solo-seriada">N.°</span><span class="solo-seriada">Fecha</span><span class="solo-seriada">Hasta</span><span>Descripción</span><span class="solo-libro">Código</span><span>Ubicación</span><span>Estado</span><span>Nota</span><span></span></div>
            <?php foreach (array_merge($ejs, array_fill(0, 3, [])) as $i => $x) : ?>
                <div class="admin-fila admin-fila--ej">
                    <select class="solo-seriada" name="ej[<?= $i ?>][tipo]" aria-label="Tipo"><?php foreach (['numero' => 'Número suelto', 'tomo' => 'Tomo o período'] as $k => $v) : ?><option value="<?= e($k) ?>"<?= ($x['tipo'] ?? 'numero') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                    <input class="solo-seriada" type="text" name="ej[<?= $i ?>][numero]" value="<?= e($x['numero'] ?? '') ?>" aria-label="Número">
                    <input class="solo-seriada" type="date" name="ej[<?= $i ?>][fecha_desde]" value="<?= e($x['fecha_desde'] ?? '') ?>" aria-label="Fecha del número o inicio del período">
                    <input class="solo-seriada" type="date" name="ej[<?= $i ?>][fecha_hasta]" value="<?= e($x['fecha_hasta'] ?? '') ?>" aria-label="Término del período (solo tomos)">
                    <input type="text" name="ej[<?= $i ?>][descripcion]" value="<?= e($x['descripcion'] ?? '') ?>" aria-label="Descripción" placeholder="<?= $i === count($ejs) ? 'c.1' : '' ?>">
                    <input class="solo-libro" type="text" name="ej[<?= $i ?>][codigo]" value="<?= e($x['codigo'] ?? '') ?>" aria-label="Código">
                    <input type="text" name="ej[<?= $i ?>][ubicacion]" value="<?= e($x['ubicacion'] ?? '') ?>" aria-label="Ubicación" placeholder="<?= $i === count($ejs) ? 'Estante A, caja 3' : '' ?>">
                    <select name="ej[<?= $i ?>][estado]" aria-label="Estado"><?php foreach (ESTADOS as $st) : ?><option<?= ($x['estado'] ?? '') === $st ? ' selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select>
                    <input type="text" name="ej[<?= $i ?>][nota]" value="<?= e($x['nota'] ?? '') ?>" aria-label="Nota">
                    <?php if ($x) : ?><label class="admin-check"><input type="checkbox" name="ej[<?= $i ?>][borrar]" value="1"> Quitar</label><?php else : ?><span></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <details class="generador solo-seriada">
            <summary>Agregar una serie de números o tomos de una vez</summary>
            <p class="admin-ayuda">Por ejemplo, todos los números de un mensual entre 2013 y 2016, o tomos trimestrales de un diario. Se agregan al guardar.</p>
            <div class="admin-rejilla">
                <label>Agregar<select name="gen_tipo"><option value="numero">Números sueltos</option><option value="tomo">Tomos o períodos</option></select></label>
                <label>Cada<select name="gen_cada"><option value="">—</option><option value="diaria">día</option><option value="semanal">semana</option><option value="quincenal">quincena</option><option value="mensual" selected>mes</option><option value="bimestral">dos meses</option><option value="trimestral">tres meses</option><option value="semestral">seis meses</option><option value="anual">año</option></select></label>
                <label>Desde<input type="date" name="gen_desde"></label>
                <label>Hasta<input type="date" name="gen_hasta"></label>
                <label>Primer número (opcional)<input type="text" name="gen_numero" inputmode="numeric"></label>
                <label>Ubicación<input type="text" name="gen_ubicacion"></label>
                <label>Estado<select name="gen_estado"><?php foreach (ESTADOS as $st) : ?><option><?= e($st) ?></option><?php endforeach; ?></select></label>
            </div>
        </details>
    </fieldset>
    <script>
    // Campos de hemeroteca cuando la obra es un periódico o una revista, o está en la hemeroteca.
    (function () {
        var f = document.querySelector('.unidades'), col = document.querySelector('[name="coleccion"]'), fmt = document.querySelector('[name="formato"]');
        function act() { f.classList.toggle('es-seriada', col.value === 'hemeroteca' || fmt.value === 'periodico' || fmt.value === 'revista'); }
        col.addEventListener('change', act); fmt.addEventListener('change', act);
    }());
    </script>


    <?php if (!empty($r['marc'])) : ?>
        <details class="admin-caja"><summary>Registro MARC original (solo lectura)</summary><pre class="marc"><?= e($r['marc']) ?></pre></details>
    <?php endif; ?>

    <div class="admin-guardar">
        <button class="boton" name="accion" value="guardar">Guardar</button>
        <?php if ($id) : ?><button class="admin-borrar" name="accion" value="borrar" formnovalidate onclick="return confirm('¿Eliminar esta obra y sus ejemplares? No se puede deshacer.')">Eliminar obra</button><?php endif; ?>
    </div>
</form>
<?php admin_pie(); ?>
