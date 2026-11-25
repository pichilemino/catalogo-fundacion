# Catálogo de la Biblioteca y Hemeroteca

Catálogo en línea de la Biblioteca de la Fundación Cultural Región de Colchagua y de la Hemeroteca Archibaldo Morales Villanueva. PHP 8.1 o superior con SQLite (pdo_sqlite), sin base de datos que configurar: todo el catálogo es un solo archivo, `data/catalogo.sqlite`, así que no suma archivos al hosting a medida que crece.

- `index.php`: búsqueda por colección, formato y campo, sin importar tildes, con frase exacta y orden por año, título o autor.
- `registro.php`: ficha completa, tarjeta catalográfica, cita APA y etiquetas MARC, con los ejemplares o tomos.
- `admin/`: catalogar obras, ejemplares y portadas (opcional), y copiar registros de otras bibliotecas:
  - Universidad de Chile y Library of Congress (SRU, MARC completo), Harvard (LibraryCloud) y Open Library (con portadas), en «Otros catálogos». La Library of Congress responde por el puerto 210: si el hosting bloquea las salidas por ese puerto, esa fuente no funcionará.
  - Biblioteca Nacional de Chile: pegando el texto que envía su catálogo por correo («MARC con etiquetas»), porque no permite consultas automáticas.
- Hemeroteca: cada periódico o revista es un registro, y sus unidades pueden ser **números sueltos** (n.° y fecha) o **tomos o períodos** (fecha de inicio y de término, si están agrupados o empastados). Un generador agrega series completas (por ejemplo, todos los números mensuales de 2013 a 2016, o tomos trimestrales). Los años de la colección se calculan solos.
- `portadas/`: imágenes de portada (una por obra, reducidas a 600 px si el servidor tiene GD). Debe poder escribirse.
- `inc/config.php`: direcciones de los otros sitios (biblioteca, colecciones digitales) y listas de formatos y estados.

La carpeta `data/` debe poder escribirse; el `.htaccess` incluido impide leerla desde la web en Apache.
