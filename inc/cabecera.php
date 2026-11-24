<?php
/** Cabecera común: la misma de los sitios de la Fundación y de la biblioteca. Cada página define $titulo antes de incluirla. */
require_once __DIR__ . '/config.php';
$titulo  = $titulo ?? SITIO_NOMBRE;
$col_act = $_GET['coleccion'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo === SITIO_NOMBRE ? $titulo : $titulo . ' · Catálogo Región de Colchagua') ?></title>
    <meta name="description" content="Catálogo de libros, periódicos y revistas de la Biblioteca de la Fundación Cultural Región de Colchagua y la Hemeroteca Archibaldo Morales Villanueva.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Newsreader:opsz,wght@6..72,400;6..72,500;6..72,600&display=swap">
    <link rel="stylesheet" href="<?= e(recurso('assets/css/estilo.css')) ?>">
    <link rel="stylesheet" href="<?= e(recurso('assets/css/catalogo.css')) ?>">
</head>
<body>
<a class="saltar" href="#contenido">Ir al contenido</a>
<header class="cabecera">
    <div class="cabecera__barra envoltura">
        <a class="marca" href="index.php">
            <img class="marca__logo" src="assets/img/logo.svg" alt="Fundación Región de Colchagua" width="648" height="200">
            <span class="marca__nombre">Catálogo</span>
        </a>
        <button class="menu-boton" type="button" aria-expanded="false" aria-controls="menu">Menú</button>
        <nav id="menu" class="menu" aria-label="Principal">
            <ul>
                <li><a href="index.php"<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php' && $col_act === '' ? ' aria-current="page"' : '' ?>>Buscar</a></li>
                <?php foreach (COLECCIONES as $clave => $nombre) : ?>
                    <li><a href="index.php?coleccion=<?= e($clave) ?>"<?= $col_act === $clave ? ' aria-current="page"' : '' ?>><?= e($clave === 'hemeroteca' ? 'Hemeroteca' : $nombre) ?></a></li>
                <?php endforeach; ?>
                <li><a href="<?= e(COLECCIONES_URL) ?>">Colecciones digitales</a></li>
            </ul>
            <a class="boton boton--claro" href="<?= e(BIBLIOTECA_URL) ?>">La biblioteca</a>
        </nav>
    </div>
</header>
<main id="contenido">
