<?php
require __DIR__ . '/inc/config.php';

$r = registro((int) ($_GET['id'] ?? 0));
if (!$r) {
    http_response_code(404);
    $titulo = 'Obra no encontrada';
    require __DIR__ . '/inc/cabecera.php';
    echo '<section class="encabezado envoltura"><h1>No encontramos esa obra</h1><p><a href="index.php">Volver a buscar</a></p></section>';
    require __DIR__ . '/inc/pie.php';
    exit;
}
$vistas = ['ficha' => 'Ficha', 'tarjeta' => 'Tarjeta catalográfica', 'cita' => 'Cita', 'marc' => 'Etiquetas MARC'];
$vista  = isset($vistas[$_GET['vista'] ?? '']) ? $_GET['vista'] : 'ficha';
if ($vista === 'marc' && trim((string) $r['marc']) === '') {
    $vista = 'ficha';
}
$ejs   = ejemplares((int) $r['id']);
$hemer = $r['coleccion'] === 'hemeroteca';

/** Cita en estilo APA, a partir de los datos de la ficha. */
function cita_apa(array $r): string
{
    $autor = $r['autor'] ? preg_replace('/,\s*\d{4}-?\d{0,4}$/', '', $r['autor']) : ($r['responsabilidad'] ?: '');
    $partes = [];
    if ($autor) {
        $partes[] = rtrim($autor, '.') . '.';
    }
    $partes[] = '(' . e($r['anio'] ?: 's. f.') . ').';
    $partes[] = '<i>' . e(titulo_completo($r)) . '</i>' . ($r['edicion'] ? ' (' . e(rtrim($r['edicion'], '.')) . ')' : '') . '.';
    if ($r['editorial']) {
        $partes[] = e(rtrim($r['editorial'], '.')) . '.';
    }
    return e($partes[0] ?? '') . ' ' . implode(' ', array_slice($partes, 1));
}

/** Enlaza las direcciones web dentro de un texto. */
function enlazar(string $t): string
{
    return (string) preg_replace('#(https?://[^\s<]+)#', '<a href="$1" rel="noopener">$1</a>', e($t));
}

$titulo = titulo_completo($r);
require __DIR__ . '/inc/cabecera.php';
?>

<header class="encabezado envoltura encabezado--obra">
    <p class="rotulo"><span class="tri" aria-hidden="true"><i></i><i></i><i></i></span><a href="index.php?coleccion=<?= e($r['coleccion']) ?>"><?= e(COLECCIONES[$r['coleccion']] ?? '') ?></a></p>
    <span class="formato formato--<?= e($r['formato']) ?>"><?= e(FORMATOS[$r['formato']] ?? '') ?></span>
    <h1><?= e($r['titulo']) ?><?php if ($r['resto_titulo']) : ?><span class="obra-subtitulo"><?= e($r['resto_titulo']) ?></span><?php endif; ?></h1>
    <p class="encabezado__bajada"><?= e($r['responsabilidad'] ?: $r['autor']) ?></p>
</header>

<section class="envoltura">
    <nav class="vistas" aria-label="Formato de la ficha">
        <?php foreach ($vistas as $k => $v) : if ($k === 'marc' && trim((string) $r['marc']) === '') { continue; } ?>
            <a href="registro.php?id=<?= (int) $r['id'] ?>&amp;vista=<?= e($k) ?>"<?= $vista === $k ? ' aria-current="page"' : '' ?>><?= e($v) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($vista === 'ficha') : ?>
        <dl class="ficha ficha--obra">
            <?php
            $filas = [
                'Autor' => $r['autor'], 'Otros autores' => implode('; ', lineas($r['otros_autores'])),
                'Título' => titulo_completo($r) . ($r['responsabilidad'] ? ' / ' . $r['responsabilidad'] : ''),
                'Edición' => $r['edicion'],
                'Publicación' => trim($r['lugar'] . ($r['lugar'] && $r['editorial'] ? ' : ' : '') . $r['editorial'] . ($r['anio'] ? ', ' . $r['anio'] : '')),
                'Impresión' => $r['impresion'], 'Descripción' => $r['descripcion'],
                'Periodicidad' => $r['periodicidad'], 'Años en la colección' => $r['cobertura'],
                'Serie' => $r['serie'], 'ISBN' => $r['isbn'], 'ISSN' => $r['issn'],
                'Clasificación' => signatura($r),
            ];
            foreach ($filas as $etq => $val) :
                if (trim((string) $val) === '') {
                    continue;
                } ?>
                <div><dt><?= e($etq) ?></dt><dd><?= e($val) ?></dd></div>
            <?php endforeach; ?>
            <?php if (lineas($r['notas'])) : ?>
                <div><dt>Notas</dt><dd><?php foreach (lineas($r['notas']) as $n) : ?><span class="ficha__linea"><?= e($n) ?></span><?php endforeach; ?></dd></div>
            <?php endif; ?>
            <?php if (lineas($r['materias'])) : ?>
                <div><dt>Materias</dt><dd><?php foreach (lineas($r['materias']) as $m) : ?><a class="ficha__linea" href="index.php?campo=materia&amp;exacta=1&amp;q=<?= e(rawurlencode($m)) ?>"><?= e($m) ?></a><?php endforeach; ?></dd></div>
            <?php endif; ?>
            <?php if ($r['enlace']) : ?>
                <div><dt>Versión digital</dt><dd><a href="<?= e($r['enlace']) ?>">Ver en las colecciones digitales ↗</a></dd></div>
            <?php endif; ?>
            <?php if ($r['fuente']) : ?>
                <div><dt>Fuente del registro</dt><dd class="ficha__fuente"><?= enlazar($r['fuente']) ?></dd></div>
            <?php endif; ?>
        </dl>

    <?php elseif ($vista === 'tarjeta') : ?>
        <div class="tarjeta-cat">
            <div class="tarjeta-cat__signatura cifra"><?php foreach (array_filter([$r['dewey'], $r['cutter'], $r['anio']]) as $s) : ?><span><?= e($s) ?></span><?php endforeach; ?></div>
            <div class="tarjeta-cat__texto">
                <?php if ($r['autor']) : ?><p class="tarjeta-cat__encabezamiento"><?= e($r['autor']) ?></p><?php endif; ?>
                <p class="tarjeta-cat__sangria"><?= e(titulo_completo($r)) ?><?= $r['responsabilidad'] ? ' / ' . e($r['responsabilidad']) : '' ?>.<?= $r['edicion'] ? ' — ' . e($r['edicion']) . '.' : '' ?> — <?= e(trim($r['lugar'] . ' : ' . $r['editorial'] . ', ' . $r['anio'], ' :,')) ?>.</p>
                <?php if ($r['descripcion']) : ?><p class="tarjeta-cat__sangria"><?= e($r['descripcion']) ?>.<?= $r['serie'] ? ' — (' . e($r['serie']) . ')' : '' ?></p><?php endif; ?>
                <?php foreach (lineas($r['notas']) as $n) : ?><p class="tarjeta-cat__sangria"><?= e($n) ?></p><?php endforeach; ?>
                <?php if ($r['isbn']) : ?><p class="tarjeta-cat__sangria">ISBN <?= e($r['isbn']) ?></p><?php endif; ?>
                <?php if (lineas($r['materias'])) : ?>
                    <p class="tarjeta-cat__sangria"><?php foreach (lineas($r['materias']) as $i => $m) : ?><?= $i + 1 ?>. <?= e($m) ?>. <?php endforeach; ?>I. Título.</p>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($vista === 'cita') : ?>
        <div class="cita-bib">
            <p class="cita-bib__estilo">APA, 7.ª edición</p>
            <p class="cita-bib__texto" id="cita"><?= cita_apa($r) ?></p>
            <button type="button" class="boton boton--claro" data-copiar="cita">Copiar cita</button>
        </div>

    <?php else : ?>
        <pre class="marc"><?= e($r['marc']) ?></pre>
    <?php endif; ?>
</section>

<section class="envoltura existencias">
    <h2><?= $hemer ? 'Tomos en la hemeroteca' : 'Ejemplares' ?></h2>
    <?php if ($ejs) : ?>
        <div class="tabla-envoltura">
            <table class="catalogo">
                <thead><tr><th scope="col"><?= $hemer ? 'Tomo' : 'Ejemplar' ?></th><th scope="col">Código</th><th scope="col">Ubicación</th><th scope="col">Estado</th><th scope="col">Nota</th></tr></thead>
                <tbody>
                    <?php foreach ($ejs as $x) : ?>
                        <tr>
                            <td><?= e($x['descripcion']) ?></td>
                            <td class="cifra"><?= e($x['codigo']) ?></td>
                            <td><?= e($x['ubicacion']) ?></td>
                            <td><span class="estado<?= $x['estado'] === 'Disponible' ? ' estado--si' : '' ?>"><?= e($x['estado']) ?></span></td>
                            <td><?= e($x['nota']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else : ?>
        <p class="vacio">Esta obra está catalogada, pero sus ejemplares todavía se están ordenando.</p>
    <?php endif; ?>
    <p class="existencias__nota">La colección se consulta en sala. <a href="index.php">Volver a buscar</a></p>
</section>

<script>
document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-copiar]');
    if (!b || !navigator.clipboard) { return; }
    navigator.clipboard.writeText(document.getElementById(b.getAttribute('data-copiar')).innerText).then(function () { b.textContent = 'Cita copiada'; });
});
</script>

<?php require __DIR__ . '/inc/pie.php'; ?>
