<?php
/**
 * Catálogo de la Biblioteca y Hemeroteca de la Fundación Cultural Región de Colchagua.
 * Todo el catálogo vive en un solo archivo SQLite (data/catalogo.sqlite): no hay que configurar una base de datos
 * y no suma archivos al hosting a medida que crece la colección.
 */
declare(strict_types=1);

const SITIO_NOMBRE   = 'Catálogo de la Biblioteca Región de Colchagua';
const BIBLIOTECA_URL = 'https://biblioteca.colchagua.org/';
const COLECCIONES_URL = 'https://colecciones.colchagua.org/';
const FUNDACION_URL  = 'https://colchagua.org/';
const BASE_ARCHIVO   = __DIR__ . '/../data/catalogo.sqlite';
const POR_PAGINA     = 20;

const COLECCIONES = ['biblioteca' => 'Biblioteca', 'hemeroteca' => 'Hemeroteca Archibaldo Morales Villanueva'];
const FORMATOS = [
    'libro' => 'Libro', 'folleto' => 'Folleto', 'periodico' => 'Periódico', 'revista' => 'Revista',
    'mapa' => 'Mapa', 'documento' => 'Documento', 'otro' => 'Otro',
];
const ESTADOS = ['Disponible', 'En proceso técnico', 'En encuadernación', 'En restauración', 'No disponible'];
const CAMPOS_BUSQUEDA = ['todo' => 'Todos los campos', 'titulo' => 'Título', 'autor' => 'Autor', 'materia' => 'Materia', 'anio' => 'Año'];
const ORDENES = ['anio' => 'Año (recientes primero)', 'titulo' => 'Título', 'autor' => 'Autor'];

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/** Ruta de un recurso con su fecha como versión. */
function recurso(string $ruta, string $prefijo = ''): string
{
    $archivo = __DIR__ . '/../' . $ruta;
    return $prefijo . $ruta . (is_file($archivo) ? '?v=' . filemtime($archivo) : '');
}

/** Texto en minúsculas y sin tildes, para buscar sin que importen. */
function normalizar(?string $texto): string
{
    $t = mb_strtolower((string) $texto, 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c']);
    return trim((string) preg_replace('/[^\p{L}\p{N}ñ]+/u', ' ', $t));
}

/** Conexión a la base. La primera vez crea las tablas. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dir = dirname(BASE_ARCHIVO);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . BASE_ARCHIVO, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = DELETE;');
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS registros (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coleccion TEXT NOT NULL DEFAULT 'biblioteca',
            formato TEXT NOT NULL DEFAULT 'libro',
            titulo TEXT NOT NULL, resto_titulo TEXT, responsabilidad TEXT,
            autor TEXT, otros_autores TEXT,
            edicion TEXT, lugar TEXT, editorial TEXT, anio TEXT, impresion TEXT,
            descripcion TEXT, serie TEXT, notas TEXT, materias TEXT,
            dewey TEXT, cutter TEXT, isbn TEXT, issn TEXT,
            periodicidad TEXT, cobertura TEXT,
            enlace TEXT, fuente TEXT, fuente_id TEXT, marc TEXT,
            busqueda TEXT, b_titulo TEXT, b_autor TEXT, b_materia TEXT,
            creado TEXT NOT NULL, modificado TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS reg_coleccion ON registros (coleccion);
        CREATE INDEX IF NOT EXISTS reg_fuente ON registros (fuente_id);
        CREATE TABLE IF NOT EXISTS ejemplares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registro_id INTEGER NOT NULL REFERENCES registros(id) ON DELETE CASCADE,
            codigo TEXT, descripcion TEXT, ubicacion TEXT, estado TEXT NOT NULL DEFAULT 'Disponible', nota TEXT,
            orden INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS ej_registro ON ejemplares (registro_id);
        SQL);
    return $pdo;
}

/** Columnas de texto de un registro que se pueden editar. */
const CAMPOS_REGISTRO = [
    'coleccion', 'formato', 'titulo', 'resto_titulo', 'responsabilidad', 'autor', 'otros_autores', 'edicion', 'lugar',
    'editorial', 'anio', 'impresion', 'descripcion', 'serie', 'notas', 'materias', 'dewey', 'cutter', 'isbn', 'issn',
    'periodicidad', 'cobertura', 'enlace', 'fuente', 'fuente_id', 'marc',
];

/** Guarda un registro (nuevo si no trae id) y recalcula sus columnas de búsqueda. Devuelve el id. */
function registro_guardar(array $r, ?int $id = null): int
{
    $datos = [];
    foreach (CAMPOS_REGISTRO as $c) {
        $datos[$c] = isset($r[$c]) ? trim((string) $r[$c]) : '';
    }
    $datos['coleccion'] = isset(COLECCIONES[$datos['coleccion']]) ? $datos['coleccion'] : 'biblioteca';
    $datos['formato']   = isset(FORMATOS[$datos['formato']]) ? $datos['formato'] : 'libro';
    $datos['b_titulo']  = normalizar($datos['titulo'] . ' ' . $datos['resto_titulo'] . ' ' . $datos['serie']);
    $datos['b_autor']   = normalizar($datos['autor'] . ' ' . $datos['otros_autores'] . ' ' . $datos['responsabilidad']);
    $datos['b_materia'] = normalizar($datos['materias']);
    $datos['busqueda']  = normalizar(implode(' ', [$datos['titulo'], $datos['resto_titulo'], $datos['autor'], $datos['otros_autores'],
        $datos['responsabilidad'], $datos['editorial'], $datos['lugar'], $datos['anio'], $datos['serie'], $datos['notas'],
        $datos['materias'], $datos['isbn'], $datos['issn'], $datos['dewey']]));
    $ahora = date('Y-m-d H:i:s');
    $pdo   = db();
    if ($id) {
        $sets = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($datos)));
        $st   = $pdo->prepare("UPDATE registros SET $sets, modificado = :modificado WHERE id = :id");
        $st->execute($datos + ['modificado' => $ahora, 'id' => $id]);
        return $id;
    }
    $cols = array_keys($datos);
    $st   = $pdo->prepare('INSERT INTO registros (' . implode(', ', $cols) . ', creado, modificado) VALUES (:' . implode(', :', $cols) . ', :creado, :modificado)');
    $st->execute($datos + ['creado' => $ahora, 'modificado' => $ahora]);
    return (int) $pdo->lastInsertId();
}

function registro(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM registros WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function ejemplares(int $id): array
{
    $st = db()->prepare('SELECT * FROM ejemplares WHERE registro_id = ? ORDER BY orden, id');
    $st->execute([$id]);
    return $st->fetchAll();
}

/**
 * Búsqueda. $p: q, campo, coleccion, formato, exacta, orden, pagina. Devuelve [filas, total].
 * Cada palabra debe aparecer (en cualquier orden) salvo con búsqueda exacta, que busca la frase completa.
 */
function buscar(array $p): array
{
    $where = [];
    $args  = [];
    $col   = ['todo' => 'busqueda', 'titulo' => 'b_titulo', 'autor' => 'b_autor', 'materia' => 'b_materia', 'anio' => 'anio'][$p['campo'] ?? 'todo'] ?? 'busqueda';
    $q     = normalizar($p['q'] ?? '');
    if ($q !== '') {
        $partes = !empty($p['exacta']) ? [$q] : array_slice(array_unique(explode(' ', $q)), 0, 10);
        foreach ($partes as $i => $parte) {
            $where[] = "(' ' || $col || ' ') LIKE :q$i";
            $args["q$i"] = !empty($p['exacta']) ? '% ' . $parte . ' %' : '%' . $parte . '%';
        }
    }
    if (isset(COLECCIONES[$p['coleccion'] ?? ''])) {
        $where[] = 'coleccion = :col';
        $args['col'] = $p['coleccion'];
    }
    if (isset(FORMATOS[$p['formato'] ?? ''])) {
        $where[] = 'formato = :for';
        $args['for'] = $p['formato'];
    }
    $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orden = ['anio' => 'anio DESC, b_titulo', 'titulo' => 'b_titulo, anio DESC', 'autor' => "CASE WHEN b_autor = '' THEN 1 ELSE 0 END, b_autor, anio DESC"][$p['orden'] ?? 'anio'] ?? 'anio DESC, b_titulo';
    $st = db()->prepare("SELECT COUNT(*) FROM registros $sql_where");
    $st->execute($args);
    $total  = (int) $st->fetchColumn();
    $pagina = max(1, (int) ($p['pagina'] ?? 1));
    $st = db()->prepare("SELECT r.*, (SELECT COUNT(*) FROM ejemplares x WHERE x.registro_id = r.id) AS n_ejemplares
        FROM registros r $sql_where ORDER BY $orden LIMIT " . POR_PAGINA . ' OFFSET ' . (($pagina - 1) * POR_PAGINA));
    $st->execute($args);
    return [$st->fetchAll(), $total];
}

/** Líneas no vacías de un campo de varias líneas (autores, materias, notas). */
function lineas(?string $texto): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $texto)), fn ($l) => $l !== ''));
}

/** Título completo con subtítulo. */
function titulo_completo(array $r): string
{
    return $r['titulo'] . ($r['resto_titulo'] ? ': ' . $r['resto_titulo'] : '');
}

/** Signatura topográfica (Dewey + Cutter + año), como en las bibliotecas chilenas. */
function signatura(array $r): string
{
    return trim(implode(' ', array_filter([$r['dewey'], $r['cutter'], $r['formato'] === 'libro' ? $r['anio'] : ''])));
}
