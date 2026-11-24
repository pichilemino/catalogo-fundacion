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
    <title><?= e(($titulo === SITIO_NOMBRE ? 'Catálogo' : $titulo . ' · Catálogo') . ' · ' . SITIO_CORTO) ?></title>
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
        <a class="marca" href="<?= e(BIBLIOTECA_URL) ?>">
            <img class="marca__logo" src="assets/img/logo.svg" alt="Fundación Región de Colchagua" width="648" height="200">
            <span class="marca__nombre">Biblioteca</span>
        </a>
        <button class="menu-boton" type="button" aria-expanded="false" aria-controls="menu">Menú</button>
        <nav id="menu" class="menu" aria-label="Principal">
            <ul>
                <?php foreach (MENU_BIBLIOTECA as $archivo => $texto) : ?>
                    <li><a href="<?= e(BIBLIOTECA_URL . $archivo) ?>"><?= e($texto) ?></a></li>
                <?php endforeach; ?>
                <li><a href="index.php" aria-current="page">Catálogo</a></li>
            </ul>
            <a class="boton boton--claro" href="<?= e(FUNDACION_URL) ?>">colchagua.org</a>
        </nav>
    </div>
</header>
<main id="contenido">
