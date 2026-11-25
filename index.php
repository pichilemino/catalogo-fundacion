<?php
require __DIR__ . '/inc/config.php';

$p = [
    'q'         => is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 200) : '',
    'campo'     => isset(CAMPOS_BUSQUEDA[$_GET['campo'] ?? '']) ? $_GET['campo'] : 'todo',
    'coleccion' => isset(COLECCIONES[$_GET['coleccion'] ?? '']) ? $_GET['coleccion'] : '',
    'formato'   => isset(FORMATOS[$_GET['formato'] ?? '']) ? $_GET['formato'] : '',
    'exacta'    => !empty($_GET['exacta']) ? '1' : '',
    'orden'     => isset(ORDENES[$_GET['orden'] ?? '']) ? $_GET['orden'] : 'anio',
    'pagina'    => max(1, (int) ($_GET['pagina'] ?? 1)),
];
$buscando = $p['q'] !== '' || $p['coleccion'] !== '' || $p['formato'] !== '';
[$filas, $total] = buscar($buscando ? $p : ['orden' => 'anio']);
$paginas = max(1, (int) ceil($total / POR_PAGINA));

/** Enlace a esta búsqueda con algún parámetro cambiado. */
function enlace(array $p, array $cambios): string
{
    $q = array_filter(array_merge($p, $cambios), fn ($v) => $v !== '' && $v !== null);
    if (($q['pagina'] ?? 1) == 1) {
        unset($q['pagina']);
    }
    if (($q['campo'] ?? '') === 'todo') {
        unset($q['campo']);
    }
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
}

$titulo = $p['coleccion'] ? COLECCIONES[$p['coleccion']] : ($p['q'] !== '' ? 'Resultados para «' . $p['q'] . '»' : SITIO_NOMBRE);
require __DIR__ . '/inc/cabecera.php';
$conteo = db()->query("SELECT coleccion, COUNT(*) n FROM registros GROUP BY coleccion")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<section class="busqueda<?= $buscando ? ' busqueda--compacta' : '' ?>">
    <div class="envoltura">
        <p class="rotulo"><span class="tri" aria-hidden="true"><i></i><i></i><i></i></span><?= $p['coleccion'] ? 'Catálogo' : 'Biblioteca y Hemeroteca de la Fundación' ?></p>
        <h1><?= $p['coleccion'] ? e(COLECCIONES[$p['coleccion']]) : 'Buscar en la colección' ?></h1>
        <?php if (!$buscando) : ?>
            <p class="busqueda__bajada">Libros, periódicos y revistas sobre Pichilemu, Cardenal Caro y Colchagua, para consultar en sala.</p>
        <?php endif; ?>
        <form class="buscador" method="get" action="index.php" role="search">
            <div class="buscador__fila">
                <label class="buscador__q"><span class="screen-reader-text">Buscar</span>
                    <input type="search" name="q" value="<?= e($p['q']) ?>" placeholder="Título, autor, materia o año"<?= $buscando ? '' : ' autofocus' ?>>
                </label>
                <button class="boton" type="submit">Buscar</button>
            </div>
            <div class="buscador__filtros">
                <label>Colección
                    <select name="coleccion">
                        <option value="">Todas</option>
                        <?php foreach (COLECCIONES as $k => $v) : ?><option value="<?= e($k) ?>"<?= $p['coleccion'] === $k ? ' selected' : '' ?>><?= e($k === 'hemeroteca' ? 'Hemeroteca' : $v) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Formato
                    <select name="formato">
                        <option value="">Todos</option>
                        <?php foreach (FORMATOS as $k => $v) : ?><option value="<?= e($k) ?>"<?= $p['formato'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Buscar en
                    <select name="campo">
                        <?php foreach (CAMPOS_BUSQUEDA as $k => $v) : ?><option value="<?= e($k) ?>"<?= $p['campo'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="buscador__check"><input type="checkbox" name="exacta" value="1"<?= $p['exacta'] ? ' checked' : '' ?>> Frase exacta</label>
            </div>
        </form>
    </div>
</section>

<?php if (!$buscando) : ?>
    <section class="envoltura explorar" aria-label="Colecciones">
        <a class="explorar__item" href="index.php?coleccion=biblioteca">
            <span class="explorar__cifra cifra"><?= number_format((int) ($conteo['biblioteca'] ?? 0), 0, ',', '.') ?></span>
            <span class="explorar__nombre">Biblioteca</span>
            <span class="explorar__texto">Libros, folletos y documentos sobre la zona</span>
        </a>
        <a class="explorar__item explorar__item--hemeroteca" href="index.php?coleccion=hemeroteca">
            <span class="explorar__cifra cifra"><?= number_format((int) ($conteo['hemeroteca'] ?? 0), 0, ',', '.') ?></span>
            <span class="explorar__nombre">Hemeroteca Archibaldo Morales Villanueva</span>
            <span class="explorar__texto">Periódicos y revistas, con sus tomos empastados</span>
        </a>
        <a class="explorar__item explorar__item--digital" href="<?= e(COLECCIONES_URL) ?>">
            <span class="explorar__cifra" aria-hidden="true">↗</span>
            <span class="explorar__nombre">Colecciones digitales</span>
            <span class="explorar__texto">Documentos, afiches y fotografías digitalizados</span>
        </a>
    </section>
<?php endif; ?>

<section class="envoltura resultados" aria-live="polite">
    <div class="resultados__cab">
        <p class="resultados__total">
            <?php if ($buscando) : ?>
                <?= $total === 0 ? 'No encontramos obras con esa búsqueda' : ($total === 1 ? '1 obra' : number_format($total, 0, ',', '.') . ' obras') ?>
            <?php else : ?>
                Últimas incorporaciones
            <?php endif; ?>
        </p>
        <?php if ($buscando && $total > 1) : ?>
            <p class="resultados__orden">Ordenar por
                <?php foreach (ORDENES as $k => $v) : ?>
                    <a href="<?= e(enlace($p, ['orden' => $k, 'pagina' => 1])) ?>"<?= $p['orden'] === $k ? ' aria-current="true"' : '' ?>><?= e(explode(' (', $v)[0]) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($filas) : ?>
        <ol class="obras" start="<?= ($p['pagina'] - 1) * POR_PAGINA + 1 ?>">
            <?php foreach ($filas as $r) : ?>
                <li class="obra">
                    <span class="obra__lado">
                        <?php if (!empty($r['portada'])) : ?><img class="obra__portada" src="<?= e($r['portada']) ?>" alt="" loading="lazy"><?php endif; ?>
                        <span class="formato formato--<?= e($r['formato']) ?>"><?= e(FORMATOS[$r['formato']] ?? $r['formato']) ?></span>
                    </span>
                    <div class="obra__cuerpo">
                        <h2 class="obra__titulo"><a href="registro.php?id=<?= (int) $r['id'] ?>"><?= e(titulo_completo($r)) ?></a></h2>
                        <p class="obra__datos">
                            <?= e($r['autor'] ?: $r['responsabilidad']) ?>
                            <?php if ($r['editorial'] || $r['anio']) : ?><span><?= e(trim($r['editorial'] . ($r['editorial'] && $r['anio'] ? ', ' : '') . $r['anio'])) ?></span><?php endif; ?>
                        </p>
                    </div>
                    <div class="obra__meta">
                        <span class="cifra"><?= e(signatura($r)) ?></span>
                        <span><?= (int) $r['n_ejemplares'] === 0 ? 'Sin ejemplares aún' : ((int) $r['n_ejemplares'] === 1 ? ($r['coleccion'] === 'hemeroteca' ? '1 número o tomo' : '1 ejemplar') : (int) $r['n_ejemplares'] . ($r['coleccion'] === 'hemeroteca' ? ' números y tomos' : ' ejemplares')) ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php elseif ($buscando) : ?>
        <div class="vacio">
            <p>Prueba con menos palabras, quita los filtros o busca en <a href="<?= e(COLECCIONES_URL) ?>">las colecciones digitales</a>.</p>
        </div>
    <?php else : ?>
        <div class="vacio"><p>El catálogo todavía no tiene obras. Se irán sumando a medida que se ordene la colección.</p></div>
    <?php endif; ?>

    <?php if ($buscando && $paginas > 1) : ?>
        <nav class="paginas" aria-label="Páginas de resultados">
            <?php if ($p['pagina'] > 1) : ?><a href="<?= e(enlace($p, ['pagina' => $p['pagina'] - 1])) ?>">← Anterior</a><?php endif; ?>
            <span>Página <?= $p['pagina'] ?> de <?= $paginas ?></span>
            <?php if ($p['pagina'] < $paginas) : ?><a href="<?= e(enlace($p, ['pagina' => $p['pagina'] + 1])) ?>">Siguiente →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/inc/pie.php'; ?>
