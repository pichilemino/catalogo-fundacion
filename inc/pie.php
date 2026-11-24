</main>
<footer class="pie">
    <span class="tri tri--ancho" aria-hidden="true"><i></i><i></i><i></i></span>
    <div class="pie__interior envoltura">
        <div>
            <img class="pie__logo" src="assets/img/logo-blanco.svg" alt="Fundación Región de Colchagua" width="648" height="200" loading="lazy">
            <p class="pie__nombre">Biblioteca de la Fundación y Hemeroteca Archibaldo Morales Villanueva</p>
            <p>Un proyecto de la <a href="<?= e(FUNDACION_URL) ?>">Fundación Cultural Región de Colchagua</a>, Pichilemu.</p>
        </div>
        <div>
            <p class="pie__titulo">Escríbenos</p>
            <p>Para saber más del proyecto o colaborar con él, usa el <a href="<?= e(CONTACTO_URL) ?>">formulario de contacto</a> de la Fundación.</p>
        </div>
        <div>
            <p class="pie__titulo">Páginas</p>
            <ul>
                <?php foreach (MENU_BIBLIOTECA as $archivo => $texto) : ?>
                    <li><a href="<?= e(BIBLIOTECA_URL . $archivo) ?>"><?= e($texto) ?></a></li>
                <?php endforeach; ?>
                <li><a href="index.php">Catálogo</a></li>
                <li><a href="<?= e(COLECCIONES_URL) ?>">Colecciones digitales</a></li>
            </ul>
        </div>
    </div>
    <p class="pie__legal envoltura">© <?= date('Y') ?> Fundación Cultural Región de Colchagua.</p>
</footer>
<script>
(function () {
    var b = document.querySelector('.menu-boton');
    if (!b) { return; }
    b.addEventListener('click', function () {
        var abierto = b.getAttribute('aria-expanded') === 'true';
        b.setAttribute('aria-expanded', String(!abierto));
        document.body.classList.toggle('menu-abierto', !abierto);
    });
}());
</script>
</body>
</html>
