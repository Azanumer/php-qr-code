<?php
/**
 * QR code example.
 *
 * Usage: php examples/qr-example.php
 * Writes qr-url.png and qr-url.svg next to this file (needs ext-gd for PNG).
 */

require __DIR__ . '/../src/QrCode.php';

$text = 'https://github.com/Azanumer';

$qr = QrCode::encode($text, 'M');

printf("Version %d, %dx%d modules\n", $qr->getVersion(), $qr->getSize(), $qr->getSize());

// PNG (requires ext-gd).
if (function_exists('imagecreatetruecolor')) {
    $qr->toPngFile(__DIR__ . '/qr-url.png', 8);
    echo "Wrote qr-url.png\n";
} else {
    echo "ext-gd missing — skipping PNG\n";
}

// SVG (no extensions needed).
file_put_contents(__DIR__ . '/qr-url.svg', $qr->toSvg(8));
echo "Wrote qr-url.svg\n";

// Tiny ASCII preview (top-left corner).
$matrix = $qr->getMatrix();
for ($y = 0; $y < 10; $y++) {
    for ($x = 0; $x < 20; $x++) {
        echo $matrix[$y][$x] ? '##' : '  ';
    }
    echo "\n";
}

// Oversized text raises a clear error.
try {
    QrCode::encode(str_repeat('x', 500), 'H');
} catch (InvalidArgumentException $e) {
    echo 'Expected error: ' . $e->getMessage() . "\n";
}
