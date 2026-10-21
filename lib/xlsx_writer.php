<?php
/**
 * Generador de .xlsx sin librerías externas (ZipArchive). Se usa para las plantillas de
 * catálogos y, en la fase 6, para los 6 archivos de cargue (nombre y hoja exactos).
 *
 * Tipos de celda: string -> texto (conserva ceros a la izquierda, p.ej. '002');
 * int/float -> número; null -> vacía.
 */
final class XlsxWriter {
    /** @param array<int, array{nombre: string, encabezados: string[], filas: iterable<array>}> $hojas */
    public static function guardar(string $ruta, array $hojas): void {
        $zip = new ZipArchive();
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("No se pudo crear $ruta");

        $sheetsXml = ''; $relsXml = ''; $ctXml = '';
        foreach (array_values($hojas) as $i => $h) {
            $n = $i + 1;
            $zip->addFromString("xl/worksheets/sheet$n.xml", self::hoja($h['encabezados'], $h['filas']));
            $sheetsXml .= '<sheet name="' . self::esc(mb_substr($h['nombre'], 0, 31)) . "\" sheetId=\"$n\" r:id=\"rId$n\"/>";
            $relsXml   .= "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
            $ctXml     .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $nEst = count($hojas) + 1;
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $ctXml . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>$sheetsXml</sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relsXml
            . "<Relationship Id=\"rId$nEst\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>");
        // Estilo 0: normal. Estilo 1: encabezado en negrita con fondo gris.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7EBF0"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();
    }

    private static function hoja(array $encabezados, iterable $filas): string {
        $cols = count($encabezados);
        $anchos = array_map(fn($e) => min(60, max(10, mb_strlen($e) + 2)), $encabezados);
        $cuerpo = self::fila(1, $encabezados, 1);
        $r = 1;
        foreach ($filas as $f) {
            $f = array_values($f);
            foreach ($f as $j => $v) if ($j < $cols && is_string($v)) $anchos[$j] = min(60, max($anchos[$j], mb_strlen($v) + 2));
            $cuerpo .= self::fila(++$r, $f, 0);
        }
        $colsXml = '';
        foreach ($anchos as $j => $w) $colsXml .= '<col min="' . ($j + 1) . '" max="' . ($j + 1) . "\" width=\"$w\" customWidth=\"1\"/>";
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . "<cols>$colsXml</cols><sheetData>$cuerpo</sheetData>"
            . ($cols ? '<autoFilter ref="A1:' . self::col($cols - 1) . max(1, $r) . '"/>' : '')
            . '</worksheet>';
    }

    private static function fila(int $r, array $valores, int $estilo): string {
        $x = "<row r=\"$r\">";
        foreach (array_values($valores) as $j => $v) {
            if ($v === null || $v === '') continue;
            $ref = self::col($j) . $r;
            $s = $estilo ? " s=\"$estilo\"" : '';
            if (is_int($v) || is_float($v)) $x .= "<c r=\"$ref\"$s><v>$v</v></c>";
            else $x .= "<c r=\"$ref\" t=\"inlineStr\"$s><is><t xml:space=\"preserve\">" . self::esc((string) $v) . '</t></is></c>';
        }
        return $x . '</row>';
    }

    public static function col(int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function esc(string $v): string {
        // Quita caracteres de control no válidos en XML (p.ej. tabulaciones sueltas se conservan).
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
