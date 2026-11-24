</main>
<footer class="pie">
    <span class="tri tri--ancho" aria-hidden="true"><i></i><i></i><i></i></span>
    <div class="pie__interior envoltura">
        <div>
            <img class="pie__logo" src="assets/img/logo-blanco.svg" alt="Fundación Región de Colchagua" width="648" height="200" loading="lazy">
            <p class="pie__nombre">Catálogo de la Biblioteca y de la Hemeroteca Archibaldo Morales Villanueva</p>
            <p>Un proyecto de la <a href="<?= e(FUNDACION_URL) ?>">Fundación Cultural Región de Colchagua</a>, Pichilemu.</p>
        </div>
        <div>
            <p class="pie__titulo">La colección</p>
            <p>Las obras se consultan en sala. El catálogo crece a medida que se ordena la colección.</p>
        </div>
        <div>
            <p class="pie__titulo">Sitios</p>
            <ul>
                <li><a href="<?= e(BIBLIOTECA_URL) ?>">La biblioteca</a></li>
                <li><a href="<?= e(COLECCIONES_URL) ?>">Colecciones digitales</a></li>
                <li><a href="<?= e(FUNDACION_URL) ?>">Fundación Región de Colchagua</a></li>
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
