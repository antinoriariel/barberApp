<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Generador de códigos QR en SVG, sin dependencias (el proyecto no usa librerías en producción y la CSP no permite
 * scripts externos). Alcance acotado a lo que necesita la app: texto corto (una URL) en modo byte, corrección de
 * errores nivel L y versiones 1 a 6 (hasta 134 bytes). Elige la máscara con menor penalización, como pide la norma
 * ISO/IEC 18004, para que lo lean bien las cámaras.
 */
final class Qr
{
    /** Por versión (1-6), nivel L: [codewords de datos totales, codewords de corrección por bloque, cantidad de bloques]. */
    private const BLOCKS = [1 => [19, 7, 1], 2 => [34, 10, 1], 3 => [55, 15, 1], 4 => [80, 20, 1], 5 => [108, 26, 1], 6 => [136, 18, 2]];

    /** @var array<int, array<int, bool>> módulos (fila → columna → oscuro) */
    private array $dark = [];
    /** @var array<int, array<int, bool>> módulos reservados para patrones fijos (no llevan datos ni máscara) */
    private array $fixed = [];
    private int $size;

    /**
     * Privado: solo lo usa encode(), que ya eligió la versión mínima donde entra el texto. Calcula el lado de la
     * matriz (17 + 4 × versión módulos: 21 en la v1, 41 en la v6); las matrices $dark y $fixed se llenan después.
     */
    private function __construct(private readonly int $version)
    {
        $this->size = 17 + 4 * $version;
    }

    /**
     * SVG del QR de $text, cuadrado, con margen de 4 módulos y fondo blanco. $px es el lado en píxeles del atributo
     * width/height (el SVG escala igual por viewBox).
     * @throws \InvalidArgumentException si el texto no entra en la versión 6
     */
    public static function svg(string $text, int $px = 240): string
    {
        $qr = self::encode($text);
        $n = $qr->size + 8;
        $path = '';
        for ($y = 0; $y < $qr->size; $y++) {
            for ($x = 0; $x < $qr->size; $x++) {
                if ($qr->dark[$y][$x]) {
                    $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . $px . '" height="' . $px
            . '" shape-rendering="crispEdges" role="img" aria-label="Código QR"><rect width="100%" height="100%" fill="#fff"/>'
            . '<path d="' . $path . '" fill="#000"/></svg>';
    }

    /** Matriz de módulos (fila → columna → oscuro) de $text; la usan los tests. @return array<int, array<int, bool>> */
    public static function matrix(string $text): array
    {
        return self::encode($text)->dark;
    }

    /** Arma el QR completo: elige versión, codifica, agrega corrección, ubica todo y aplica la mejor máscara. */
    private static function encode(string $text): self
    {
        $len = strlen($text);
        $version = null;
        foreach (self::BLOCKS as $v => [$data]) {
            if (4 + 8 + 8 * $len <= $data * 8) { // modo (4 bits) + largo (8 bits) + datos
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new \InvalidArgumentException('Texto demasiado largo para el QR.');
        }
        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->placeData($qr->codewords($text));

        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) { // se prueba cada máscara y se queda la de menor penalización
            $try = clone $qr;
            $try->applyMask($mask);
            $try->drawFormat($mask);
            $score = $try->penalty();
            if ($score < $bestScore) {
                [$best, $bestScore] = [$try, $score];
            }
        }
        return $best;
    }

    /** Codewords finales: datos (modo byte + relleno) divididos en bloques con su corrección Reed-Solomon, intercalados. */
    private function codewords(string $text): array
    {
        [$dataTotal, $ecLen, $numBlocks] = self::BLOCKS[$this->version];
        $bits = '0100' . sprintf('%08b', strlen($text));
        foreach (str_split($text) as $ch) {
            $bits .= sprintf('%08b', ord($ch));
        }
        $bits .= str_repeat('0', min(4, $dataTotal * 8 - strlen($bits))); // terminador
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);              // completar el byte
        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($data) < $dataTotal; $pad++) {
            $data[] = $pad % 2 === 0 ? 0xEC : 0x11;                        // bytes de relleno de la norma
        }

        $per = intdiv($dataTotal, $numBlocks); // en versiones 1-6 nivel L los bloques son del mismo tamaño
        $gen = self::rsGenerator($ecLen);
        $blocks = [];
        $ecs = [];
        for ($b = 0; $b < $numBlocks; $b++) {
            $blocks[$b] = array_slice($data, $b * $per, $per);
            $ecs[$b] = self::rsRemainder($blocks[$b], $gen);
        }
        $out = [];
        for ($i = 0; $i < $per; $i++) {
            foreach ($blocks as $blk) {
                $out[] = $blk[$i];
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecs as $ec) {
                $out[] = $ec[$i];
            }
        }
        return $out;
    }

    /** Multiplicación en GF(256) con el polinomio 0x11D. */
    private static function gfMul(int $a, int $b): int
    {
        $r = 0;
        for ($i = 7; $i >= 0; $i--) {
            $r = ($r << 1) ^ (($r >> 7) * 0x11D);
            $r ^= (($b >> $i) & 1) * $a;
        }
        return $r & 0xFF;
    }

    /** Coeficientes del polinomio generador Reed-Solomon de grado $degree (sin el término principal). */
    private static function rsGenerator(int $degree): array
    {
        $poly = array_fill(0, $degree, 0);
        $poly[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $poly[$j] = self::gfMul($poly[$j], $root);
                if ($j + 1 < $degree) {
                    $poly[$j] ^= $poly[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $poly;
    }

    /** Resto de dividir los datos por el generador: los codewords de corrección del bloque. */
    private static function rsRemainder(array $data, array $gen): array
    {
        $res = array_fill(0, count($gen), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($res);
            $res[] = 0;
            foreach ($gen as $i => $coef) {
                $res[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $res;
    }

    /** Marca un módulo fijo (x = columna, y = fila). */
    private function setFixed(int $x, int $y, bool $dark): void
    {
        $this->dark[$y][$x] = $dark;
        $this->fixed[$y][$x] = true;
    }

    /** Patrones fijos: buscadores, separadores, temporización, alineación (versiones 2+), módulo oscuro y zona de formato. */
    private function drawFunctionPatterns(): void
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                $this->dark[$y][$x] = false;
                $this->fixed[$y][$x] = false;
            }
        }
        for ($i = 0; $i < $s; $i++) { // temporización
            $this->setFixed(6, $i, $i % 2 === 0);
            $this->setFixed($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) { // buscadores con su separador blanco
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) {
                        $d = max(abs($dx), abs($dy));
                        $this->setFixed($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        if ($this->version >= 2) { // en versiones 2-6 hay un único patrón de alineación, abajo a la derecha
            $c = $s - 7;
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $this->setFixed($c + $dx, $c + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
        $this->drawFormat(0); // reserva la zona de formato (se reescribe con la máscara elegida)
    }

    /** Escribe la información de formato (nivel L + máscara) en sus dos copias, y el módulo oscuro fijo. */
    private function drawFormat(int $mask): void
    {
        $data = (0b01 << 3) | $mask; // nivel L = 01
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->setFixed(8, $i, $bit($i));
        }
        $this->setFixed(8, 7, $bit(6));
        $this->setFixed(8, 8, $bit(7));
        $this->setFixed(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFixed(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFixed($s - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFixed(8, $s - 15 + $i, $bit($i));
        }
        $this->setFixed(8, $s - 8, true); // módulo oscuro
    }

    /** Ubica los bits en zigzag de a dos columnas, de abajo a la derecha hacia arriba, salteando la columna 6. */
    private function placeData(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $c) {
            $bits .= sprintf('%08b', $c);
        }
        $total = strlen($bits);
        $i = 0;
        $s = $this->size;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->fixed[$y][$x]) {
                        $this->dark[$y][$x] = $i < $total && $bits[$i] === '1'; // los restos quedan en blanco
                        $i++;
                    }
                }
            }
        }
    }

    /** Invierte los módulos de datos donde la fórmula de la máscara da verdadero. */
    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fixed[$y][$x]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($flip) {
                    $this->dark[$y][$x] = !$this->dark[$y][$x];
                }
            }
        }
    }

    /** Penalización de la norma: rachas largas, bloques 2x2, patrones parecidos a un buscador y desbalance claro/oscuro. */
    private function penalty(): int
    {
        $s = $this->size;
        $score = 0;
        $lines = [];
        for ($i = 0; $i < $s; $i++) {
            $row = '';
            $col = '';
            for ($j = 0; $j < $s; $j++) {
                $row .= $this->dark[$i][$j] ? '1' : '0';
                $col .= $this->dark[$j][$i] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            if (preg_match_all('/0{5,}|1{5,}/', $line, $m)) { // N1: rachas de 5 o más
                foreach ($m[0] as $run) {
                    $score += 3 + strlen($run) - 5;
                }
            }
            $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101')); // N3
        }
        for ($y = 0; $y < $s - 1; $y++) { // N2: bloques 2x2 del mismo color
            for ($x = 0; $x < $s - 1; $x++) {
                $c = $this->dark[$y][$x];
                if ($c === $this->dark[$y][$x + 1] && $c === $this->dark[$y + 1][$x] && $c === $this->dark[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $darkCount = 0;
        foreach ($this->dark as $row) {
            $darkCount += count(array_filter($row));
        }
        $score += 10 * intdiv(abs($darkCount * 20 - $s * $s * 10), $s * $s); // N4
        return $score;
    }
}
