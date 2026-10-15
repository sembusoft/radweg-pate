<?php
declare(strict_types=1);
/**
 * MiniPdf – kleiner PDF-Erzeuger ohne Abhängigkeiten (für den Mängelbericht).
 *
 * Kann: A4-Seiten, Helvetica normal/fett (Standardschrift, nicht eingebettet, WinAnsi →
 * deutsche Umlaute, ß, €, „“), Textumbruch mit Seitenwechsel, Linien, Flächen, JPEG-Bilder
 * (DCTDecode, unverändert eingebettet), Link-Flächen, Seitenfuß „Seite x von n“.
 * Kann nicht: andere Schriften, PNG/WebP (vorher in JPEG wandeln), Tabellen.
 * Einheiten: Punkt (1/72 Zoll), Ursprung oben links (intern umgerechnet).
 */
final class MiniPdf {
    public const W = 595.28, H = 841.89;
    private array $pages = [];       // je Seite: Inhaltsstrom
    private array $links = [];       // je Seite: [[x,y,w,h,url]]
    private array $images = [];      // [name => [data,w,h,cs]]
    public float $margin = 42, $y = 42;
    private int $page = -1;
    private string $font = 'F1'; private float $size = 10;
    public string $footer = '';

    // Laufweiten Helvetica / Helvetica-Bold (1/1000 em), Zeichen 32–126; Rest siehe width().
    private const HV = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const HB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    public function pageNo(): int { return $this->page; }
    public function addPage(): void {
        $this->page++; $this->pages[$this->page] = ''; $this->links[$this->page] = [];
        $this->y = $this->margin;
    }
    /** UTF-8 → WinAnsi (cp1252); nicht darstellbare Zeichen werden ersetzt. */
    public static function enc(string $s): string {
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return $out === false ? preg_replace('/[^\x20-\x7e]/', '?', $s) : $out;
    }
    private static function esc(string $s): string { return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']); }
    private function out(string $s): void { $this->pages[$this->page] .= $s . "\n"; }
    private static function n(float $v): string { return rtrim(rtrim(sprintf('%.2F', $v), '0'), '.'); }

    public function font(bool $bold, float $size): void { $this->font = $bold ? 'F2' : 'F1'; $this->size = $size; }
    public function color(array $rgb): void { $this->out(sprintf('%s %s %s rg', ...array_map(fn($c) => self::n($c / 255), $rgb))); }
    public function strokeColor(array $rgb): void { $this->out(sprintf('%s %s %s RG', ...array_map(fn($c) => self::n($c / 255), $rgb))); }

    /** Breite eines (WinAnsi-)Texts in Punkt bei aktueller Schrift. */
    public function width(string $winAnsi): float {
        $t = $this->font === 'F2' ? self::HB : self::HV; $w = 0;
        for ($i = 0, $n = strlen($winAnsi); $i < $n; $i++) {
            $c = ord($winAnsi[$i]);
            if ($c >= 32 && $c <= 126) $w += $t[$c - 32];
            elseif (in_array($c, [0xC4, 0xD6, 0xDC], true)) $w += $c === 0xD6 ? 778 : 722;   // Ä Ö Ü
            elseif (in_array($c, [0x96, 0x80], true)) $w += 556;                              // – €
            elseif ($c === 0x97 || $c === 0x85) $w += 1000;                                   // — …
            elseif (in_array($c, [0x84, 0x93, 0x94, 0x82, 0x91, 0x92], true)) $w += 400;      // Anführungszeichen
            else $w += $this->font === 'F2' ? 611 : 556;                                      // ä ö ü ß é … (großzügig)
        }
        return $w * $this->size / 1000;
    }
    /** Text an Position (x, y = Grundlinie von oben). */
    public function text(float $x, float $y, string $utf8): void {
        $this->out(sprintf('BT /%s %s Tf %s %s Td (%s) Tj ET', $this->font, self::n($this->size), self::n($x), self::n(self::H - $y), self::esc(self::enc($utf8))));
    }
    /** Zeilen umbrechen (Wortgrenzen; überlange Wörter hart teilen). Gibt UTF-8-Zeilen zurück. */
    public function wrap(string $utf8, float $maxW): array {
        $lines = [];
        foreach (preg_split('/\R/u', $utf8) as $para) {
            $line = '';
            foreach (preg_split('/ +/u', trim($para)) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($this->width(self::enc($try)) <= $maxW) { $line = $try; continue; }
                if ($line !== '') $lines[] = $line;
                $line = $word;
                while ($this->width(self::enc($line)) > $maxW && mb_strlen($line) > 1) {
                    $cut = mb_strlen($line);
                    while ($cut > 1 && $this->width(self::enc(mb_substr($line, 0, $cut))) > $maxW) $cut--;
                    $lines[] = mb_substr($line, 0, $cut); $line = mb_substr($line, $cut);
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }
    /** Fließtext ab aktueller Position mit Seitenwechsel. */
    public function para(string $utf8, float $x = -1, float $maxW = -1, float $lead = 1.35): void {
        $x = $x < 0 ? $this->margin : $x;
        $maxW = $maxW < 0 ? self::W - $this->margin - $x : $maxW;
        foreach ($this->wrap($utf8, $maxW) as $l) {
            $this->need($this->size * $lead);
            $this->y += $this->size;
            $this->text($x, $this->y, $l);
            $this->y += $this->size * ($lead - 1);
        }
    }
    /** Platz sicherstellen, sonst neue Seite. */
    public function need(float $h): void { if ($this->y + $h > self::H - $this->margin - 18) $this->addPage(); }
    public function rect(float $x, float $y, float $w, float $h, string $op = 'f'): void {
        $this->out(sprintf('%s %s %s %s re %s', self::n($x), self::n(self::H - $y - $h), self::n($w), self::n($h), $op));
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $w = .6): void {
        $this->out(sprintf('%s w %s %s m %s %s l S', self::n($w), self::n($x1), self::n(self::H - $y1), self::n($x2), self::n(self::H - $y2)));
    }
    public function link(float $x, float $y, float $w, float $h, string $url): void { $this->links[$this->page][] = [$x, $y, $w, $h, $url]; }

    /** JPEG einbetten; gibt [w, h] in Pixeln zurück oder null (kein JPEG). */
    public function addJpeg(string $data): ?array {
        $info = @getimagesizefromstring($data);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_JPEG) return null;
        $cs = ($info['channels'] ?? 3) === 1 ? '/DeviceGray' : ((($info['channels'] ?? 3) === 4) ? '/DeviceCMYK' : '/DeviceRGB');
        $name = 'I' . (count($this->images) + 1);
        $this->images[$name] = [$data, (int)$info[0], (int)$info[1], $cs];
        return [$name, (int)$info[0], (int)$info[1]];
    }
    public function image(string $name, float $x, float $y, float $w, float $h): void {
        $this->out(sprintf('q %s 0 0 %s %s %s cm /%s Do Q', self::n($w), self::n($h), self::n($x), self::n(self::H - $y - $h), $name));
    }

    /** Fertiges PDF als Zeichenkette. */
    public function output(string $title = ''): string {
        $objs = []; // Index = Objektnummer - 1
        $add = function (string $body) use (&$objs): int { $objs[] = $body; return count($objs); };
        $catalog = $add(''); $pagesId = $add('');
        $f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $xo = [];
        foreach ($this->images as $name => [$data, $w, $h, $cs]) {
            $extra = $cs === '/DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
            $xo[$name] = $add("<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace $cs /BitsPerComponent 8 /Filter /DCTDecode$extra /Length " . strlen($data) . " >>\nstream\n$data\nendstream");
        }
        $res = '<< /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R >> /XObject << ' . implode(' ', array_map(fn($k, $v) => "/$k $v 0 R", array_keys($xo), $xo)) . ' >> >>';
        $resId = $add($res);
        $kids = []; $total = count($this->pages);
        foreach ($this->pages as $i => $content) {
            if ($this->footer !== '' || $total > 1) {
                $this->page = $i; $this->font(false, 8); $this->color([110, 120, 115]);
                $foot = trim($this->footer . '  ·  Seite ' . ($i + 1) . ' von ' . $total, ' ·');
                $this->text($this->margin, self::H - 24, $foot);
                $content = $this->pages[$i];
            }
            $stream = gzcompress($content, 6);
            $cId = $add('<< /Length ' . strlen($stream) . " /Filter /FlateDecode >>\nstream\n$stream\nendstream");
            $annots = [];
            foreach ($this->links[$i] as [$x, $y, $w, $h, $url]) {
                $annots[] = $add(sprintf('<< /Type /Annot /Subtype /Link /Rect [%s %s %s %s] /Border [0 0 0] /A << /S /URI /URI (%s) >> >>',
                    self::n($x), self::n(self::H - $y - $h), self::n($x + $w), self::n(self::H - $y), self::esc($url)));
            }
            $kids[] = $add("<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 " . self::n(self::W) . ' ' . self::n(self::H) . "] /Resources $resId 0 R /Contents $cId 0 R"
                . ($annots ? ' /Annots [' . implode(' ', array_map(fn($a) => "$a 0 R", $annots)) . ']' : '') . ' >>');
        }
        $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => "$k 0 R", $kids)) . "] /Count $total >>";
        $objs[$catalog - 1] = "<< /Type /Catalog /Pages $pagesId 0 R >>";
        $info = $add('<< /Producer (Radwegpate) /Title (' . self::esc(self::enc($title)) . ') /CreationDate (D:' . gmdate('YmdHis') . "Z) >>");
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($objs as $i => $body) { $off[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
        $pdf .= 'trailer << /Size ' . (count($objs) + 1) . " /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
