<?php
/**
 * Búsqueda en catálogos de otras bibliotecas, para copiar sus registros (catalogación por copia).
 *  - Universidad de Chile y Library of Congress: SRU, con registros MARC completos (mismo lector que los de la BN).
 *  - Harvard: API LibraryCloud (registros MODS).
 *  - Open Library: API abierta, útil sobre todo por ISBN y por las portadas.
 * La Biblioteca Nacional no permite consultas automáticas: sus registros se importan pegando el correo (importar.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/marc.php';

const FUENTES = [
    'uchile'      => 'Universidad de Chile',
    'loc'         => 'Library of Congress',
    'harvard'     => 'Harvard Library',
    'openlibrary' => 'Open Library',
];
const TIPOS_EXTERNOS = ['todo' => 'Todos los campos', 'titulo' => 'Título', 'autor' => 'Autor', 'isbn' => 'ISBN'];

/** GET con tiempo límite; usa cURL si está disponible. Devuelve el cuerpo o '' si falla. */
function http_get(string $url, int $segundos = 20): string
{
    $agente = 'CatalogoFundacionRegionDeColchagua/1.0 (+https://colchagua.org/)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $segundos, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => $agente]);
        $r    = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($r !== false && $code >= 200 && $code < 300) ? (string) $r : '';
    }
    $ctx = stream_context_create(['http' => ['timeout' => $segundos, 'user_agent' => $agente, 'ignore_errors' => false]]);
    $r   = @file_get_contents($url, false, $ctx);
    return $r === false ? '' : $r;
}

/** Comillas para CQL. */
function cql(string $t): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $t) . '"';
}

/** Busca en una fuente. Devuelve [registros, error]. Cada registro trae además 'portada_url' si la fuente la ofrece. */
function buscar_externo(string $fuente, string $tipo, string $q, int $max = 10): array
{
    $q = trim($q);
    if ($q === '' || !isset(FUENTES[$fuente])) {
        return [[], ''];
    }
    $isbn = preg_replace('/[^0-9Xx]/', '', $q);
    switch ($fuente) {
        case 'uchile':
            $indice = ['todo' => 'alma.all_for_ui', 'titulo' => 'alma.title', 'autor' => 'alma.creator', 'isbn' => 'alma.isbn'][$tipo] ?? 'alma.all_for_ui';
            $url    = 'https://uchile.alma.exlibrisgroup.com/view/sru/56UDC_INST?version=1.2&operation=searchRetrieve&recordSchema=marcxml&maximumRecords=' . $max
                . '&query=' . rawurlencode($indice . '=' . cql($tipo === 'isbn' ? $isbn : $q));
            return marc_xml_a_registros(http_get($url), 'Universidad de Chile', 'UCH', '001');
        case 'loc':
            $cqlq = ['todo' => cql($q), 'titulo' => 'dc.title=' . cql($q), 'autor' => 'dc.creator=' . cql($q), 'isbn' => 'bath.isbn=' . $isbn][$tipo] ?? cql($q);
            $url  = 'http://lx2.loc.gov:210/LCDB?version=1.1&operation=searchRetrieve&recordSchema=marcxml&maximumRecords=' . $max . '&query=' . rawurlencode($cqlq);
            return marc_xml_a_registros(http_get($url), 'Library of Congress', 'LOC', '001');
        case 'harvard':
            $param = ['todo' => 'q', 'titulo' => 'title', 'autor' => 'name', 'isbn' => 'identifier'][$tipo] ?? 'q';
            $json  = json_decode(http_get('https://api.lib.harvard.edu/v2/items.json?limit=' . $max . '&' . $param . '=' . rawurlencode($tipo === 'isbn' ? $isbn : $q)), true);
            if (!is_array($json)) {
                return [[], 'Harvard no respondió.'];
            }
            $mods = $json['items']['mods'] ?? [];
            $mods = isset($mods['titleInfo']) ? [$mods] : $mods; // un solo resultado viene sin lista
            return [array_values(array_filter(array_map('mods_a_registro', (array) $mods))), ''];
        case 'openlibrary':
            if ($tipo === 'isbn') {
                $ed = json_decode(http_get('https://openlibrary.org/isbn/' . $isbn . '.json'), true);
                return [is_array($ed) && !empty($ed['title']) ? [openlibrary_edicion_a_registro($ed, $isbn)] : [], ''];
            }
            $param = ['todo' => 'q', 'titulo' => 'title', 'autor' => 'author', 'isbn' => 'isbn'][$tipo] ?? 'q';
            $campos = 'key,title,subtitle,author_name,first_publish_year,publish_year,publisher,publish_place,isbn,number_of_pages_median,subject,cover_i,language';
            $json = json_decode(http_get('https://openlibrary.org/search.json?limit=' . $max . '&fields=' . $campos . '&' . $param . '=' . rawurlencode($tipo === 'isbn' ? $isbn : $q)), true);
            if (!is_array($json)) {
                return [[], 'Open Library no respondió.'];
            }
            return [array_map('openlibrary_a_registro', $json['docs'] ?? []), ''];
    }
    return [[], ''];
}

/** Respuesta SRU (MARCXML) a registros del catálogo. */
function marc_xml_a_registros(string $xml, string $nombre, string $prefijo, string $tag_id): array
{
    if ($xml === '') {
        return [[], "$nombre no respondió. Intenta de nuevo en un momento."];
    }
    $regs = [];
    foreach (marc_desde_xml($xml) as $campos) {
        $r     = marc_a_registro($campos);
        $id    = marc_primero($campos, $tag_id)['valor'] ?? '';
        $r['fuente']    = $nombre;
        $r['fuente_id'] = $id !== '' ? $prefijo . ':' . trim($id) : '';
        $r['portada_url'] = '';
        $regs[] = $r;
    }
    return [$regs, ''];
}

/** Primer valor de texto en una estructura MODS convertida a JSON (que a veces es lista, a veces objeto, a veces {"#text"}). */
function mods_txt($v): string
{
    if (is_string($v) || is_numeric($v)) {
        return trim((string) $v);
    }
    if (is_array($v)) {
        if (isset($v['#text'])) {
            return trim((string) $v['#text']);
        }
        foreach ($v as $x) {
            $t = mods_txt($x);
            if ($t !== '') {
                return $t;
            }
        }
    }
    return '';
}

/** Lista de elementos MODS (siempre como lista). */
function mods_lista($v): array
{
    return is_array($v) && !array_is_list($v) ? [$v] : (array) $v;
}

function mods_a_registro($m): ?array
{
    if (!is_array($m)) {
        return null;
    }
    $ti = mods_lista($m['titleInfo'] ?? [])[0] ?? [];
    $titulo = mods_txt($ti['title'] ?? '');
    if ($titulo === '') {
        return null;
    }
    $nombres = [];
    foreach (mods_lista($m['name'] ?? []) as $n) {
        $partes = array_map('mods_txt', mods_lista($n['namePart'] ?? []));
        $nombres[] = trim(implode(', ', array_filter($partes)));
    }
    $oi  = mods_lista($m['originInfo'] ?? [])[0] ?? [];
    $lug = '';
    foreach (mods_lista($oi['place'] ?? []) as $pl) {
        $t = mods_txt($pl['placeTerm'] ?? '');
        if ($t !== '' && !preg_match('/^[a-z]{2,3}$/', $t)) {
            $lug = $t;
        }
    }
    $anio = '';
    if (preg_match('/\d{4}/', mods_txt($oi['dateIssued'] ?? '') . ' ' . mods_txt($oi['copyrightDate'] ?? ''), $mm)) {
        $anio = $mm[0];
    }
    $materias = [];
    foreach (mods_lista($m['subject'] ?? []) as $s) {
        $partes = [];
        foreach (['topic', 'geographic', 'temporal', 'genre'] as $k) {
            foreach (mods_lista($s[$k] ?? []) as $x) {
                $partes[] = mods_txt($x);
            }
        }
        if (isset($s['name'])) {
            array_unshift($partes, mods_txt(mods_lista($s['name'])[0]['namePart'] ?? ''));
        }
        $partes = array_filter($partes);
        if ($partes) {
            $materias[] = implode(' — ', $partes);
        }
    }
    $isbn = $dewey = $id = '';
    foreach (mods_lista($m['identifier'] ?? []) as $x) {
        if (is_array($x) && ($x['@type'] ?? '') === 'isbn' && $isbn === '') {
            $isbn = preg_replace('/\s.*$/', '', mods_txt($x));
        }
    }
    foreach (mods_lista($m['classification'] ?? []) as $x) {
        if (is_array($x) && ($x['@authority'] ?? '') === 'ddc' && $dewey === '') {
            $dewey = mods_txt($x);
        }
    }
    foreach (mods_lista($m['recordInfo']['recordIdentifier'] ?? []) as $x) {
        $id = $id ?: mods_txt($x);
    }
    $desc = mods_txt(mods_lista($m['physicalDescription'] ?? [])[0]['extent'] ?? '');
    return [
        'coleccion' => 'biblioteca', 'formato' => 'libro',
        'titulo' => marc_limpiar($titulo), 'resto_titulo' => marc_limpiar(mods_txt($ti['subTitle'] ?? '')),
        'responsabilidad' => '', 'autor' => $nombres[0] ?? '', 'otros_autores' => implode("\n", array_slice($nombres, 1)),
        'edicion' => mods_txt($oi['edition'] ?? ''), 'lugar' => marc_limpiar($lug), 'editorial' => marc_limpiar(mods_txt($oi['publisher'] ?? '')),
        'anio' => $anio, 'impresion' => '', 'descripcion' => marc_limpiar($desc), 'serie' => '',
        'notas' => '', 'materias' => implode("\n", array_unique($materias)), 'dewey' => $dewey, 'cutter' => '',
        'isbn' => $isbn, 'issn' => '', 'periodicidad' => '', 'cobertura' => '', 'enlace' => '',
        'fuente' => 'Harvard Library' . ($id ? ' — https://id.lib.harvard.edu/alma/' . $id . '/catalog' : ''),
        'fuente_id' => $id ? 'HVD:' . $id : '', 'marc' => '', 'portada_url' => '',
    ];
}

function openlibrary_a_registro(array $d): array
{
    $isbn = '';
    foreach ((array) ($d['isbn'] ?? []) as $i) {
        if (strlen((string) $i) === 13) {
            $isbn = (string) $i;
            break;
        }
    }
    $isbn = $isbn ?: (string) (($d['isbn'] ?? [''])[0] ?? '');
    $autores = (array) ($d['author_name'] ?? []);
    return [
        'coleccion' => 'biblioteca', 'formato' => 'libro',
        'titulo' => (string) ($d['title'] ?? ''), 'resto_titulo' => (string) ($d['subtitle'] ?? ''),
        'responsabilidad' => implode(', ', $autores), 'autor' => (string) ($autores[0] ?? ''), 'otros_autores' => implode("\n", array_slice($autores, 1)),
        'edicion' => '', 'lugar' => (string) (($d['publish_place'] ?? [''])[0] ?? ''), 'editorial' => (string) (($d['publisher'] ?? [''])[0] ?? ''),
        'anio' => (string) ($d['first_publish_year'] ?? ''), 'impresion' => '',
        'descripcion' => !empty($d['number_of_pages_median']) ? $d['number_of_pages_median'] . ' páginas' : '', 'serie' => '',
        'notas' => '', 'materias' => implode("\n", array_slice((array) ($d['subject'] ?? []), 0, 6)), 'dewey' => '', 'cutter' => '',
        'isbn' => $isbn, 'issn' => '', 'periodicidad' => '', 'cobertura' => '', 'enlace' => '',
        'fuente' => 'Open Library — https://openlibrary.org' . ($d['key'] ?? ''),
        'fuente_id' => !empty($d['key']) ? 'OL:' . $d['key'] : '', 'marc' => '',
        'portada_url' => !empty($d['cover_i']) ? 'https://covers.openlibrary.org/b/id/' . (int) $d['cover_i'] . '-L.jpg' : '',
    ];
}

/** Una edición concreta de Open Library (búsqueda por ISBN): título, editorial y páginas de esa edición. */
function openlibrary_edicion_a_registro(array $ed, string $isbn): array
{
    $autores = [];
    foreach (array_slice((array) ($ed['authors'] ?? []), 0, 3) as $a) {
        $au = json_decode(http_get('https://openlibrary.org' . ($a['key'] ?? '') . '.json', 10), true);
        if (!empty($au['name'])) {
            $autores[] = (string) $au['name'];
        }
    }
    $anio = preg_match('/\d{4}/', (string) ($ed['publish_date'] ?? ''), $m) ? $m[0] : '';
    return [
        'coleccion' => 'biblioteca', 'formato' => 'libro',
        'titulo' => (string) $ed['title'], 'resto_titulo' => (string) ($ed['subtitle'] ?? ''),
        'responsabilidad' => (string) ($ed['by_statement'] ?? implode(', ', $autores)), 'autor' => $autores[0] ?? '', 'otros_autores' => implode("
", array_slice($autores, 1)),
        'edicion' => (string) ($ed['edition_name'] ?? ''), 'lugar' => (string) (($ed['publish_places'] ?? [''])[0] ?? ''), 'editorial' => (string) (($ed['publishers'] ?? [''])[0] ?? ''),
        'anio' => $anio, 'impresion' => '', 'descripcion' => !empty($ed['number_of_pages']) ? $ed['number_of_pages'] . ' páginas' : (string) ($ed['pagination'] ?? ''),
        'serie' => (string) (($ed['series'] ?? [''])[0] ?? ''), 'notas' => '', 'materias' => implode("
", array_slice((array) ($ed['subjects'] ?? []), 0, 6)),
        'dewey' => (string) (($ed['dewey_decimal_class'] ?? [''])[0] ?? ''), 'cutter' => '', 'isbn' => $isbn, 'issn' => '',
        'periodicidad' => '', 'cobertura' => '', 'enlace' => '',
        'fuente' => 'Open Library — https://openlibrary.org' . ($ed['key'] ?? ''), 'fuente_id' => !empty($ed['key']) ? 'OL:' . $ed['key'] : '', 'marc' => '',
        'portada_url' => !empty($ed['covers'][0]) && (int) $ed['covers'][0] > 0 ? 'https://covers.openlibrary.org/b/id/' . (int) $ed['covers'][0] . '-L.jpg' : '',
    ];
}

/** URL de portada en Open Library para un ISBN (o '' si no tiene). */
function portada_por_isbn(string $isbn): string
{
    $isbn = preg_replace('/[^0-9Xx]/', '', explode(',', $isbn)[0]);
    return $isbn !== '' ? 'https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg?default=false' : '';
}
