# Catálogo de la Biblioteca y Hemeroteca

Catálogo en línea de la Biblioteca de la Fundación Cultural Región de Colchagua y de la Hemeroteca Archibaldo Morales Villanueva. PHP 8.1 o superior con SQLite (pdo_sqlite), sin base de datos que configurar: todo el catálogo es un solo archivo, `data/catalogo.sqlite`, así que no suma archivos al hosting a medida que crece.

- `index.php`: búsqueda por colección, formato y campo, sin importar tildes, con frase exacta y orden por año, título o autor.
- `registro.php`: ficha completa, tarjeta catalográfica, cita APA y etiquetas MARC, con los ejemplares o tomos.
- `admin/`: catalogar obras y ejemplares, e importar registros de la Biblioteca Nacional (el texto que envía su catálogo por correo, «MARC con etiquetas»).
- `inc/config.php`: direcciones de los otros sitios (biblioteca, colecciones digitales) y listas de formatos y estados.

La carpeta `data/` debe poder escribirse; el `.htaccess` incluido impide leerla desde la web en Apache.
