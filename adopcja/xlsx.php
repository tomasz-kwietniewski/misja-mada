<?php
/* ═══════════════════════════════════════════════════════════════
   Minimalny writer XLSX (zip + XML, bez zależności Composera).
   Wystarcza do eksportu-backupu: wiele arkuszy, komórki tekstowe
   i liczbowe (inline strings - bez sharedStrings/styli).
   Użycie:
     $bin = adopt_xlsx_build([
         ['name' => 'Arkusz 1', 'rows' => [['A1', 'B1'], [1, 2.5]]],
     ]);
   Wymaga rozszerzenia zip (jest na hostingu; lokalnie: -d extension=zip).
  ═══════════════════════════════════════════════════════════════ */

function adopt_xlsx_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Nazwa arkusza bezpieczna dla Excela (31 znaków, bez []:*?/\ ). */
function adopt_xlsx_sheet_name(string $name): string {
    $name = preg_replace('/[\[\]:*?\/\\\\]/', '-', $name);
    return mb_substr($name, 0, 31) ?: 'Arkusz';
}

function adopt_xlsx_col_letter(int $i): string {
    $s = '';
    $i++;
    while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
    return $s;
}

/** Buduje XML jednego arkusza (inline strings; liczby jako liczby). */
function adopt_xlsx_sheet_xml(array $rows): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $ri => $row) {
        $r = $ri + 1;
        $xml .= '<row r="' . $r . '">';
        foreach ($row as $ci => $val) {
            if ($val === null || $val === '') continue;
            $ref = adopt_xlsx_col_letter($ci) . $r;
            if (is_int($val) || is_float($val)) {
                $xml .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                      . adopt_xlsx_esc((string)$val) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

/** Składa cały plik xlsx (binarnie). $sheets = [['name' =>, 'rows' => [[..]]], ...] */
function adopt_xlsx_build(array $sheets): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Brak rozszerzenia zip (XLSX niedostępny - użyj CSV).');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $n = count($sheets);
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $types .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $types);

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>');

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach (array_values($sheets) as $i => $sh) {
        $sid = $i + 1;
        $wb .= '<sheet name="' . adopt_xlsx_esc(adopt_xlsx_sheet_name((string)$sh['name'])) . '" sheetId="' . $sid . '" r:id="rId' . $sid . '"/>';
        $rels .= '<Relationship Id="rId' . $sid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sid . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $sid . '.xml', adopt_xlsx_sheet_xml($sh['rows']));
    }
    $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $rels .= '</Relationships>';
    $wb .= '</sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
      . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
      . '<borders count="1"><border/></borders>'
      . '<cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="1"><xf/></cellXfs>'
      . '</styleSheet>');

    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
