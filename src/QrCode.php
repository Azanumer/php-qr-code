<?php
/**
 * Dependency-free QR Code generator (pure PHP, no extensions beyond GD for PNG).
 *
 * Encodes byte-mode QR codes, versions 1–10, error correction levels L/M/Q/H.
 * Full pipeline: GF(256) arithmetic, Reed-Solomon EC, block interleaving,
 * function patterns, automatic mask selection (penalty rules N1–N4),
 * format info + version info, PNG (GD) and SVG output.
 *
 * Usage:
 *   $qr = QrCode::encode('https://example.com', 'M');
 *   $qr->toPngFile('/tmp/qr.png', 8);   // 8px per module
 *   echo $qr->toSvg(6);
 */

class QrCode
{
    /** Byte-mode capacities: [version][ecl] => max bytes. */
    private static $CAPACITY = array(
        1  => array('L' => 17,  'M' => 14,  'Q' => 11,  'H' => 7),
        2  => array('L' => 32,  'M' => 26,  'Q' => 20,  'H' => 14),
        3  => array('L' => 53,  'M' => 42,  'Q' => 32,  'H' => 24),
        4  => array('L' => 78,  'M' => 62,  'Q' => 46,  'H' => 34),
        5  => array('L' => 106, 'M' => 84,  'Q' => 60,  'H' => 44),
        6  => array('L' => 134, 'M' => 106, 'Q' => 74,  'H' => 58),
        7  => array('L' => 154, 'M' => 122, 'Q' => 86,  'H' => 64),
        8  => array('L' => 192, 'M' => 152, 'Q' => 108, 'H' => 84),
        9  => array('L' => 230, 'M' => 180, 'Q' => 130, 'H' => 98),
        10 => array('L' => 271, 'M' => 213, 'Q' => 151, 'H' => 119),
    );

    /** Block structure: [version][ecl] => [ecCodewordsPerBlock, [[count, dataCw], ...]]. */
    private static $BLOCKS = array(
        1  => array('L' => array(7,  array(array(1, 19))),            'M' => array(10, array(array(1, 16))), 'Q' => array(13, array(array(1, 13))), 'H' => array(17, array(array(1, 9)))),
        2  => array('L' => array(10, array(array(1, 34))),           'M' => array(16, array(array(1, 28))), 'Q' => array(22, array(array(1, 22))), 'H' => array(28, array(array(1, 16)))),
        3  => array('L' => array(15, array(array(1, 55))),          'M' => array(26, array(array(1, 44))), 'Q' => array(18, array(array(2, 17))), 'H' => array(22, array(array(2, 13)))),
        4  => array('L' => array(20, array(array(1, 80))),          'M' => array(18, array(array(2, 32))), 'Q' => array(26, array(array(2, 24))), 'H' => array(16, array(array(4, 9)))),
        5  => array('L' => array(26, array(array(1, 108))),         'M' => array(24, array(array(2, 43))), 'Q' => array(18, array(array(2, 15), array(2, 16))), 'H' => array(22, array(array(2, 11), array(2, 12)))),
        6  => array('L' => array(18, array(array(2, 68))),           'M' => array(16, array(array(4, 27))), 'Q' => array(24, array(array(4, 19))), 'H' => array(28, array(array(4, 15)))),
        7  => array('L' => array(20, array(array(2, 78))),          'M' => array(18, array(array(4, 31))), 'Q' => array(18, array(array(2, 14), array(4, 15))), 'H' => array(26, array(array(4, 13), array(1, 14)))),
        8  => array('L' => array(24, array(array(2, 97))),           'M' => array(18, array(array(2, 38), array(2, 39))), 'Q' => array(22, array(array(4, 18), array(2, 19))), 'H' => array(26, array(array(4, 14), array(2, 15)))),
        9  => array('L' => array(30, array(array(2, 116))),         'M' => array(20, array(array(3, 36), array(2, 37))), 'Q' => array(20, array(array(4, 16), array(4, 17))), 'H' => array(24, array(array(4, 12), array(4, 13)))),
        10 => array('L' => array(18, array(array(2, 68), array(2, 69))), 'M' => array(26, array(array(4, 43), array(1, 44))), 'Q' => array(18, array(array(6, 19), array(2, 20))), 'H' => array(28, array(array(6, 15), array(2, 16)))),
    );

    private static $ALIGN = array(
        1 => array(), 2 => array(6, 18), 3 => array(6, 22), 4 => array(6, 26),
        5 => array(6, 30), 6 => array(6, 34), 7 => array(6, 22, 38),
        8 => array(6, 24, 42), 9 => array(6, 26, 46), 10 => array(6, 28, 50),
    );

    private static $REMAINDER = array(1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0);

    private static $ECL_BITS = array('L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2);

    private static $EXP;
    private static $LOG;

    private $version;
    private $ecl;
    private $size;
    private $modules;   // [y][x] => 0/1
    private $isFunction; // [y][x] => bool

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Encode text (UTF-8 byte mode) into a QR code.
     *
     * @param string $text Text to encode.
     * @param string $ecl  Error correction level: L, M, Q or H.
     * @throws InvalidArgumentException on bad level or oversized text.
     */
    public static function encode($text, $ecl = 'M')
    {
        $ecl = strtoupper($ecl);
        if (!isset(self::$ECL_BITS[$ecl])) {
            throw new InvalidArgumentException('Invalid error correction level: ' . $ecl);
        }

        $len = strlen($text);
        $version = 0;
        for ($v = 1; $v <= 10; $v++) {
            if ($len <= self::$CAPACITY[$v][$ecl]) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('Text too long for version 10 at level ' . $ecl . ' (max ' . self::$CAPACITY[10][$ecl] . ' bytes).');
        }

        $qr = new self($version, $ecl);
        $qr->build($text);
        return $qr;
    }

    /** @return int QR version (1–10). */
    public function getVersion() { return $this->version; }

    /** @return int Symbol size in modules. */
    public function getSize() { return $this->size; }

    /** @return array 2D matrix [y][x] of 0/1. */
    public function getMatrix() { return $this->modules; }

    /** @return bool True if the module at (x, y) is dark. */
    public function isDark($x, $y) { return $this->modules[$y][$x] === 1; }

    /**
     * Render PNG via GD.
     *
     * @param int   $scale  Pixels per module.
     * @param int   $margin Quiet-zone width in modules (4 per spec).
     * @param array $dark   RGB array for dark modules.
     * @param array $light  RGB array for light modules.
     * @return resource GD image.
     */
    public function toPng($scale = 8, $margin = 4, array $dark = array(0, 0, 0), array $light = array(255, 255, 255))
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD extension is required for PNG output.');
        }
        $px = ($this->size + $margin * 2) * $scale;
        $img = imagecreatetruecolor($px, $px);
        $cLight = imagecolorallocate($img, $light[0], $light[1], $light[2]);
        $cDark = imagecolorallocate($img, $dark[0], $dark[1], $dark[2]);
        imagefilledrectangle($img, 0, 0, $px - 1, $px - 1, $cLight);

        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x] === 1) {
                    $x0 = ($x + $margin) * $scale;
                    $y0 = ($y + $margin) * $scale;
                    imagefilledrectangle($img, $x0, $y0, $x0 + $scale - 1, $y0 + $scale - 1, $cDark);
                }
            }
        }
        return $img;
    }

    /** Save PNG directly to a file. */
    public function toPngFile($path, $scale = 8, $margin = 4)
    {
        $img = $this->toPng($scale, $margin);
        imagepng($img, $path);
        imagedestroy($img);
    }

    /** Render as SVG markup (crisp, resolution-independent). */
    public function toSvg($scale = 8, $margin = 4)
    {
        $dim = ($this->size + $margin * 2) * $scale;
        $out = array();
        $out[] = '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">';
        $out[] = '<rect width="' . $dim . '" height="' . $dim . '" fill="#FFFFFF"/>';
        $out[] = '<path fill="#000000" d="';
        for ($y = 0; $y < $this->size; $y++) {
            $rowStart = -1;
            for ($x = 0; $x <= $this->size; $x++) {
                $dark = ($x < $this->size && $this->modules[$y][$x] === 1);
                if ($dark && $rowStart < 0) {
                    $rowStart = $x;
                } elseif (!$dark && $rowStart >= 0) {
                    $out[] = 'M' . (($rowStart + $margin) * $scale) . ' ' . (($y + $margin) * $scale)
                        . 'h' . (($x - $rowStart) * $scale) . 'v' . $scale . 'h' . (-($x - $rowStart) * $scale) . 'z';
                    $rowStart = -1;
                }
            }
        }
        $out[] = '"/></svg>';
        return implode('', $out);
    }

    // ------------------------------------------------------------------
    // Build pipeline
    // ------------------------------------------------------------------

    private function __construct($version, $ecl)
    {
        $this->version = $version;
        $this->ecl = $ecl;
        $this->size = $version * 4 + 17;
        $this->modules = array();
        $this->isFunction = array();
        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, 0);
            $this->isFunction[$y] = array_fill(0, $this->size, false);
        }
        self::initTables();
    }

    private function build($text)
    {
        $data = $this->buildDataCodewords($text);
        $data = $this->addErrorCorrection($data);

        $this->drawFunctionPatterns();

        // Raw data placement (no mask yet).
        $this->placeData($data);

        // Try all 8 masks, keep the lowest-penalty one.
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $baseModules = $this->modules;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->modules = $baseModules;
            $this->applyMask($mask);
            $penalty = $this->penaltyScore();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
        }

        // Rebuild with the winning mask, then draw format/version info.
        $this->modules = $baseModules;
        $this->applyMask($bestMask);
        $this->drawFormatBits($bestMask);
        $this->drawVersion();
    }

    // ------------------------------------------------------------------
    // GF(256) + Reed-Solomon
    // ------------------------------------------------------------------

    private static function initTables()
    {
        if (self::$EXP !== null) {
            return;
        }
        self::$EXP = array_fill(0, 512, 0);
        self::$LOG = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$EXP[$i] = $x;
            self::$LOG[$x] = $i;
            $x <<= 1;
            if ($x >= 256) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$EXP[$i] = self::$EXP[$i - 255];
        }
    }

    private static function gfMul($a, $b)
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$EXP[self::$LOG[$a] + self::$LOG[$b]];
    }

    /** Generator polynomial for $degree EC codewords. */
    private static function rsDivisor($degree)
    {
        $result = array_fill(0, $degree - 1, 0);
        $result[] = 1;
        for ($i = 0; $i < $degree; $i++) {
            $root = self::$EXP[$i]; // field element 2^i, NOT the integer i
            for ($j = 0; $j < count($result); $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < count($result)) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
        }
        return $result;
    }

    /** Reed-Solomon remainder of $data divided by the generator polynomial. */
    private static function rsRemainder($data, $divisor)
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $result; // full degree-length remainder, no pop
    }

    // ------------------------------------------------------------------
    // Data encoding
    // ------------------------------------------------------------------

    /** Append $numBits of $value (MSB first) to the bit list. */
    private static function appendBits(&$bits, $value, $numBits)
    {
        if ($numBits < 0 || $numBits > 31 || ($value >> $numBits) !== 0) {
            throw new InvalidArgumentException('Value out of range for bit length.');
        }
        for ($i = $numBits - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /** Encode text as the final interleaved codeword sequence. */
    private function buildDataCodewords($text)
    {
        $bits = array();
        self::appendBits($bits, 0x4, 4); // byte mode indicator

        $countBits = ($this->version <= 9) ? 8 : 16;
        self::appendBits($bits, strlen($text), $countBits);

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            self::appendBits($bits, ord($text[$i]), 8);
        }

        list($ecPerBlock, $groups) = self::$BLOCKS[$this->version][$this->ecl];
        $totalDataCw = 0;
        foreach ($groups as $g) {
            $totalDataCw += $g[0] * $g[1];
        }
        $capacityBits = $totalDataCw * 8;

        // Terminator (up to 4 zero bits), then pad to a byte boundary.
        $term = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $term; $i++) {
            $bits[] = 0;
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // Pack into bytes.
        $data = array();
        for ($i = 0; $i < count($bits); $i += 8) {
            $b = 0;
            for ($j = 0; $j < 8; $j++) {
                $b = ($b << 1) | $bits[$i + $j];
            }
            $data[] = $b;
        }

        // Pad bytes 0xEC, 0x11 alternating.
        for ($pad = 0xEC; count($data) < $totalDataCw; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }

        return $data;
    }

    /** Split into blocks, compute EC per block, interleave. */
    private function addErrorCorrection($data)
    {
        list($ecPerBlock, $groups) = self::$BLOCKS[$this->version][$this->ecl];
        $divisor = self::rsDivisor($ecPerBlock);

        $blocks = array();
        $offset = 0;
        foreach ($groups as $g) {
            for ($b = 0; $b < $g[0]; $b++) {
                $blockData = array_slice($data, $offset, $g[1]);
                $offset += $g[1];
                $blocks[] = array($blockData, self::rsRemainder($blockData, $divisor));
            }
        }

        // Interleave data codewords, then EC codewords.
        $result = array();
        $maxData = 0;
        foreach ($blocks as $b) {
            $maxData = max($maxData, count($b[0]));
        }
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $b) {
                if ($i < count($b[0])) {
                    $result[] = $b[0][$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($blocks as $b) {
                $result[] = $b[1][$i];
            }
        }

        // Remainder bits (zeros) are implicit in placement loop bound.
        return $result;
    }

    // ------------------------------------------------------------------
    // Matrix: function patterns
    // ------------------------------------------------------------------

    private function setFunction($x, $y, $dark)
    {
        $this->modules[$y][$x] = $dark ? 1 : 0;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFinderPattern($cx, $cy)
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
                    continue;
                }
                $d = max(abs($dx), abs($dy));
                $this->setFunction($x, $y, $d !== 2 && $d !== 4);
            }
        }
    }

    private function drawAlignmentPattern($cx, $cy)
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function drawFunctionPatterns()
    {
        $s = $this->size;

        // Timing patterns.
        for ($i = 0; $i < $s; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        // Finder patterns (3 corners).
        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($s - 4, 3);
        $this->drawFinderPattern(3, $s - 4);

        // Alignment patterns.
        $pos = self::$ALIGN[$this->version];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                // Skip the three finder corners.
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                $this->drawAlignmentPattern($pos[$i], $pos[$j]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Matrix: format + version info
    // ------------------------------------------------------------------

    private function drawFormatBits($mask)
    {
        // 15 bits: 5 data bits, 10 BCH, masked with 101010000010010.
        $data = (self::$ECL_BITS[$this->ecl] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        $s = $this->size;
        // First copy around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $this->getBit($bits, $i));
        }
        $this->setFunction(8, 7, $this->getBit($bits, 6));
        $this->setFunction(8, 8, $this->getBit($bits, 7));
        $this->setFunction(7, 8, $this->getBit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $this->getBit($bits, $i));
        }
        // Second copy (split top-right / bottom-left).
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($s - 1 - $i, 8, $this->getBit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $s - 15 + $i, $this->getBit($bits, $i));
        }
        // Dark module (always dark).
        $this->setFunction(8, 4 * $this->version + 9, true);
    }

    private function drawVersion()
    {
        if ($this->version < 7) {
            return;
        }
        // 18 bits: 6 data, 12 BCH.
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;

        $s = $this->size;
        for ($i = 0; $i < 18; $i++) {
            $bit = $this->getBit($bits, $i);
            $a = $s - 11 + ($i % 3);
            $b = (int)($i / 3);
            $this->setFunction($a, $b, $bit); // bottom-left copy
            $this->setFunction($b, $a, $bit); // top-right copy
        }
    }

    private function getBit($x, $i)
    {
        return (($x >> $i) & 1) === 1;
    }

    // ------------------------------------------------------------------
    // Matrix: data placement + masking
    // ------------------------------------------------------------------

    private function placeData($data)
    {
        $s = $this->size;
        $i = 0;
        $totalBits = count($data) * 8 + self::$REMAINDER[$this->version];

        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // skip vertical timing column
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $totalBits) {
                        $dark = false;
                        if ($i < count($data) * 8) {
                            $dark = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        }
                        $this->modules[$y][$x] = $dark ? 1 : 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask($mask)
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = false;
                switch ($mask) {
                    case 0: $invert = ($x + $y) % 2 === 0; break;
                    case 1: $invert = $y % 2 === 0; break;
                    case 2: $invert = $x % 3 === 0; break;
                    case 3: $invert = ($x + $y) % 3 === 0; break;
                    case 4: $invert = ((int)($x / 3) + (int)($y / 2)) % 2 === 0; break;
                    case 5: $invert = (($x * $y) % 2 + ($x * $y) % 3) === 0; break;
                    case 6: $invert = ((($x * $y) % 2 + ($x * $y) % 3) % 2) === 0; break;
                    case 7: $invert = ((($x + $y) % 2 + ($x * $y) % 3) % 2) === 0; break;
                }
                if ($invert) {
                    $this->modules[$y][$x] ^= 1;
                }
            }
        }
    }

    /** Penalty score N1–N4 (lower is better). */
    private function penaltyScore()
    {
        $s = $this->size;
        $m = $this->modules;
        $penalty = 0;

        // N1: runs of 5+ same colour in rows and columns.
        for ($y = 0; $y < $s; $y++) {
            $runColor = $m[$y][0];
            $runLen = 1;
            for ($x = 1; $x < $s; $x++) {
                if ($m[$y][$x] === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $penalty += 3 + ($runLen - 5);
                    }
                    $runColor = $m[$y][$x];
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }
        for ($x = 0; $x < $s; $x++) {
            $runColor = $m[0][$x];
            $runLen = 1;
            for ($y = 1; $y < $s; $y++) {
                if ($m[$y][$x] === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $penalty += 3 + ($runLen - 5);
                    }
                    $runColor = $m[$y][$x];
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }

        // N2: 2x2 blocks of same colour.
        for ($y = 0; $y < $s - 1; $y++) {
            for ($x = 0; $x < $s - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }

        // N3: finder-like patterns 10111010000 / 00001011101.
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x <= $s - 11; $x++) {
                if ($this->finderLike($m[$y], $x)) {
                    $penalty += 40;
                }
            }
        }
        for ($x = 0; $x < $s; $x++) {
            for ($y = 0; $y <= $s - 11; $y++) {
                $col = array();
                for ($k = 0; $k < 11; $k++) {
                    $col[] = $m[$y + $k][$x];
                }
                if ($this->finderLike($col, 0)) {
                    $penalty += 40;
                }
            }
        }

        // N4: dark-module ratio.
        $dark = 0;
        $total = $s * $s;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if ($m[$y][$x] === 1) {
                    $dark++;
                }
            }
        }
        $k = (int)(abs($dark * 20 - $total * 10) / $total);
        $penalty += $k * 10;

        return $penalty;
    }

    /** Check for 10111010000 or 00001011101 starting at $off. */
    private function finderLike($line, $off)
    {
        $p1 = array(1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0);
        $p2 = array(0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1);
        foreach (array($p1, $p2) as $p) {
            $match = true;
            for ($k = 0; $k < 11; $k++) {
                if ($line[$off + $k] !== $p[$k]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }
        return false;
    }
}
