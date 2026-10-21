<?php
/**
 * Lector de .xlsx por streaming (ZipArchive + XMLReader), sin librerías externas.
 * Lee fila a fila, así que sirve para archivos grandes (Maestro SIESA, INVIMA)
 * sin cargar toda la hoja en memoria. Devuelve los valores ya calculados
 * (el valor en caché de las fórmulas), igual que read_excel() en R.
 */
final class XlsxReader {
    private ZipArchive $zip;
    private ?array $compartidas = null; // se cargan solo al leer filas (listar hojas es inmediato)
    private array $hojas = []; // nombre => ruta dentro del zip
    private array $fechas1904 = [false];
    private string $ruta;

    public function __construct(string $ruta) {
        if (!is_file($ruta)) throw new RuntimeException("No existe el archivo: $ruta");
        $this->ruta = realpath($ruta);
        $this->zip = new ZipArchive();
        if ($this->zip->open($ruta) !== true) throw new RuntimeException("No se pudo abrir como Excel: $ruta");
        $this->leerLibro();
    }

    public function hojas(): array {
        return array_keys($this->hojas);
    }

    /**
     * Recorre las filas de una hoja.
     * @return Generator<int, array<string, string>> número de fila => [letra de columna => valor]
     */
    public function filas(string $hoja): Generator {
        if (!isset($this->hojas[$hoja])) throw new RuntimeException("La hoja '$hoja' no existe en el archivo.");
        if ($this->compartidas === null) $this->leerCompartidas();
        $xml = $this->abrir($this->hojas[$hoja]);
        while ($xml->read()) {
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'row') continue;
            $num = (int) $xml->getAttribute('r');
            $fila = [];
            $dom = $xml->expand();
            foreach ($dom->childNodes as $c) {
                if ($c->nodeType !== XML_ELEMENT_NODE || $c->localName !== 'c') continue;
                $ref = $c->getAttribute('r');
                $col = rtrim($ref, '0123456789');
                $valor = $this->valorCelda($c);
                if ($valor !== null && $valor !== '') $fila[$col] = $valor;
            }
            if ($fila) yield $num => $fila;
        }
        $xml->close();
    }

    private function valorCelda(DOMElement $c): ?string {
        $tipo = $c->getAttribute('t');
        if ($tipo === 'inlineStr') {
            $txt = '';
            foreach ($c->getElementsByTagName('t') as $t) $txt .= $t->textContent;
            return $txt;
        }
        $v = null;
        foreach ($c->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'v') { $v = $n->textContent; break; }
        }
        if ($v === null) return null;
        return match ($tipo) {
            's'     => $this->compartidas[(int) $v] ?? '',
            'b'     => $v === '1' ? 'TRUE' : 'FALSE',
            default => $v, // 'n', 'str' (fórmula de texto) o 'e' (#N/A, #REF!...)
        };
    }

    private function leerLibro(): void {
        $rels = [];
        $xr = simplexml_load_string($this->zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach ($xr->Relationship as $r) {
            $t = (string) $r['Target'];
            $rels[(string) $r['Id']] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
        }
        $wb = simplexml_load_string($this->zip->getFromName('xl/workbook.xml'));
        $ns = $wb->getNamespaces(true);
        $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        foreach ($wb->sheets->sheet as $s) {
            $id = (string) $s->attributes($rNs)['id'];
            $this->hojas[(string) $s['name']] = $rels[$id] ?? '';
        }
    }

    private function leerCompartidas(): void {
        $this->compartidas = [];
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) return;
        $xml = $this->abrir('xl/sharedStrings.xml');
        while ($xml->read()) {
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'si') continue;
            $txt = '';
            foreach ($xml->expand()->getElementsByTagName('t') as $t) {
                // Excluir textos fonéticos (rPh), que no hacen parte del valor visible.
                if ($t->parentNode && $t->parentNode->localName === 'rPh') continue;
                $txt .= $t->textContent;
            }
            $this->compartidas[] = $txt;
        }
        $xml->close();
    }

    /**
     * Abre una parte del .xlsx leyéndola por partes desde el zip (sin descomprimirla completa en
     * memoria): una hoja de 300 MB, como la consulta de medicamentos de Mutual Ser, se recorre igual.
     */
    private function abrir(string $parte): XMLReader {
        $xml = new XMLReader();
        if (!@$xml->open('zip://' . str_replace('\\', '/', $this->ruta) . '#' . $parte, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            // Respaldo: descomprimir la parte en memoria.
            $xml->XML($this->zip->getFromName($parte), 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        }
        return $xml;
    }

    /** Convierte un número de serie de Excel a 'Y-m-d'. */
    public static function fechaSerial(string $v): ?string {
        if (!is_numeric($v)) return null;
        $dias = (int) floor((float) $v);
        return gmdate('Y-m-d', ($dias - 25569) * 86400);
    }
}

/** Normaliza un código leído de Excel: '1101.0' -> '1101', recorta espacios. */
function codigo_excel(?string $v): string {
    $v = trim((string) $v);
    if (preg_match('/^-?\d+\.0+$/', $v)) $v = substr($v, 0, strpos($v, '.'));
    return $v;
}
