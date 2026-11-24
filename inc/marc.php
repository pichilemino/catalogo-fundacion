<?php
/**
 * Lector de registros MARC en texto, como los que envía por correo el catálogo de la Biblioteca Nacional de Chile
 * («Número de registro: 1 / FMT BK / LDR … / 24510 |a Título / |c Autor.»). También sirve la vista «Etiquetas MARC»
 * copiada desde el catálogo: cada línea es una etiqueta de tres caracteres, dos indicadores y subcampos con «|».
 */
declare(strict_types=1);

/** Separa el texto en registros y cada registro en campos. Devuelve una lista de registros MARC (listas de campos). */
function marc_leer_texto(string $texto): array
{
    $texto     = str_replace("\r\n", "\n", $texto);
    $bloques   = preg_split('/^\s*N[úu]mero de registro:\s*\d+\s*$/mu', $texto);
    $registros = [];
    foreach ($bloques as $bloque) {
        $campos = [];
        foreach (explode("\n", $bloque) as $linea) {
            if (!preg_match('/^\s{0,8}([0-9A-Z]{3})(.{0,2}?)\s{2,}(\S.*)$/u', rtrim($linea), $m)) {
                continue;
            }
            $tag = $m[1];
            if (!preg_match('/^(\d{3}|LDR|FMT|SYS|DRL)$/', $tag)) {
                continue; // campos locales del sistema de la BN (CAT, OWN, PSTS…)
            }
            $campo = ['tag' => $tag, 'ind' => str_pad(str_replace(' ', '#', $m[2]), 2, '#'), 'valor' => trim($m[3]), 'sub' => []];
            if (str_starts_with($campo['valor'], '|')) {
                preg_match_all('/\|([a-z0-9])\s?(.*?)(?=\s\|[a-z0-9]\s|$)/u', $campo['valor'], $sm, PREG_SET_ORDER);
                foreach ($sm as $s) {
                    $campo['sub'][] = [$s[1], trim($s[2])];
                }
            }
            $campos[] = $campo;
        }
        if (array_filter($campos, fn ($c) => $c['tag'] === '245')) {
            $registros[] = $campos;
        }
    }
    return $registros;
}

/** Subcampos de un campo, filtrados por código ('ab' = a y b), unidos por espacio. */
function marc_sub(array $campo, string $codigos): string
{
    $partes = [];
    foreach ($campo['sub'] as [$c, $v]) {
        if (str_contains($codigos, $c)) {
            $partes[] = $v;
        }
    }
    return trim(implode(' ', $partes));
}

/** Todos los campos con alguna de estas etiquetas. */
function marc_campos(array $reg, string ...$tags): array
{
    return array_values(array_filter($reg, fn ($c) => in_array($c['tag'], $tags, true)));
}

function marc_primero(array $reg, string ...$tags): ?array
{
    return marc_campos($reg, ...$tags)[0] ?? null;
}

/** Quita la puntuación final que usa la catalogación (« /», « :», «.», «,»). */
function marc_limpiar(string $t): string
{
    return trim((string) preg_replace('/\s*[\/:;,=]\s*$|(?<![A-Z]\.[A-Z])(?<!\s[A-Z])\.$/u', '', trim($t)));
}

/** Convierte un registro MARC en los campos del catálogo. */
function marc_a_registro(array $reg): array
{
    $c245 = marc_primero($reg, '245');
    $c008 = marc_primero($reg, '008');
    $fmt  = marc_primero($reg, 'FMT');
    $ldr  = marc_primero($reg, 'LDR');
    $serial = ($fmt && in_array($fmt['valor'], ['SE', 'SR'], true)) || ($ldr && in_array(substr($ldr['valor'], 7, 1), ['s', 'b'], true));

    // Autores: el principal (100/110/111) y los secundarios (700/710/711), con su función.
    $princ = marc_primero($reg, '100', '110', '111');
    $autor = $princ ? marc_limpiar(marc_sub($princ, 'abcdq')) : '';
    $otros = [];
    foreach (marc_campos($reg, '700', '710', '711') as $c) {
        $rol     = marc_limpiar(marc_sub($c, 'e'));
        $otros[] = marc_limpiar(marc_sub($c, 'abcdq')) . ($rol ? " ($rol)" : '');
    }

    // Publicación: 264 con segundo indicador 1 (publicación) o el 260 antiguo; la impresión (264 3 o 260 ef) va aparte.
    $pub = null;
    foreach (marc_campos($reg, '264') as $c) {
        if ($c['ind'][1] === '1') {
            $pub = $c;
            break;
        }
    }
    $pub ??= marc_primero($reg, '260');
    $imp = null;
    foreach (marc_campos($reg, '264') as $c) {
        if ($c['ind'][1] === '3') {
            $imp = marc_limpiar(marc_sub($c, 'ab'));
        }
    }
    $c260 = marc_primero($reg, '260');
    if (!$imp && $c260 && marc_sub($c260, 'f')) {
        $imp = trim(marc_sub($c260, 'e') . ' ' . marc_sub($c260, 'f'), ' ()');
    }
    $anio = '';
    if ($c008 && preg_match('/^\d{6}\w(\d{4})/', $c008['valor'], $m)) {
        $anio = $m[1];
    } elseif ($pub && preg_match('/\d{4}/', marc_sub($pub, 'c'), $m)) {
        $anio = $m[0];
    }

    // Materias: 600, 610, 611, 630, 650, 651 y 655, con sus subdivisiones unidas por «—».
    $materias = [];
    foreach (marc_campos($reg, '600', '610', '611', '630', '650', '651', '655') as $c) {
        $partes = [];
        foreach ($c['sub'] as [$cod, $v]) {
            if (str_contains('abcdtvxyz', $cod)) {
                $partes[] = marc_limpiar($v);
            }
        }
        $materias[] = implode(' — ', array_unique($partes));
    }

    $c082  = marc_primero($reg, '082', '080', '090');
    $notas = array_map(fn ($c) => marc_sub($c, 'a'), marc_campos($reg, '500', '504', '505', '520', '546'));
    $serie = marc_primero($reg, '490', '830');
    $sys   = marc_primero($reg, 'SYS');
    $drl   = marc_primero($reg, 'DRL');
    $c300  = marc_primero($reg, '300');

    // El texto MARC original se guarda sin los campos de gestión de la BN.
    $marc = implode("\n", array_map(fn ($c) => str_pad($c['tag'] . str_replace('#', ' ', $c['ind']), 8) . $c['valor'],
        array_filter($reg, fn ($c) => !in_array($c['tag'], ['FMT', 'SYS', 'DRL'], true))));

    return [
        'coleccion'       => $serial ? 'hemeroteca' : 'biblioteca',
        'formato'         => $serial ? 'periodico' : 'libro',
        'titulo'          => marc_limpiar(marc_sub($c245, 'a')),
        'resto_titulo'    => marc_limpiar(marc_sub($c245, 'bnp')),
        'responsabilidad' => marc_limpiar(marc_sub($c245, 'c')),
        'autor'           => $autor,
        'otros_autores'   => implode("\n", $otros),
        'edicion'         => marc_limpiar(marc_sub(marc_primero($reg, '250') ?? ['sub' => []], 'ab')),
        'lugar'           => $pub ? marc_limpiar(marc_sub($pub, 'a')) : '',
        'editorial'       => $pub ? marc_limpiar(marc_sub($pub, 'b')) : '',
        'anio'            => $anio,
        'impresion'       => (string) $imp,
        'descripcion'     => $c300 ? marc_limpiar(marc_sub($c300, 'abce')) : '',
        'serie'           => $serie ? marc_limpiar(marc_sub($serie, 'av')) : '',
        'notas'           => implode("\n", array_filter($notas)),
        'materias'        => implode("\n", array_unique(array_filter($materias))),
        'dewey'           => $c082 ? marc_limpiar(marc_sub($c082, 'a')) : '',
        'cutter'          => $c082 ? marc_limpiar(marc_sub($c082, 'b')) : '',
        'isbn'            => implode(', ', array_map(fn ($c) => preg_replace('/\s.*$/', '', marc_sub($c, 'a')), marc_campos($reg, '020'))),
        'issn'            => implode(', ', array_map(fn ($c) => marc_sub($c, 'a'), marc_campos($reg, '022'))),
        'periodicidad'    => ($c310 = marc_primero($reg, '310')) ? marc_sub($c310, 'a') : '',
        'cobertura'       => ($c362 = marc_primero($reg, '362')) ? marc_sub($c362, 'a') : '',
        'enlace'          => '',
        'fuente'          => 'Biblioteca Nacional de Chile' . ($drl ? ' — ' . marc_sub($drl, 'a') : ''),
        'fuente_id'       => $sys ? 'BNC:' . trim($sys['valor']) : '',
        'marc'            => $marc,
    ];
}
