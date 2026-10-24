# PHP QR Code

Dependency-free QR code generator in pure PHP. No Composer packages, no API calls — one class that does the whole QR pipeline itself.

## What it does

- Byte-mode encoding (UTF-8 safe), QR versions 1–10, error correction levels L / M / Q / H
- Full spec pipeline: GF(256) arithmetic, Reed-Solomon error correction, block interleaving, function patterns (finders, timing, alignment), automatic mask selection via penalty rules N1–N4, format info + version info (v7+)
- Outputs: **PNG** via GD (`toPng()` / `toPngFile()`), **SVG** markup (`toSvg()`, no extensions needed), raw matrix (`getMatrix()`)

## Usage

```php
require 'src/QrCode.php';

$qr = QrCode::encode('https://example.com', 'M');
$qr->toPngFile('qr.png', 8);          // 8px per module, 4-module quiet zone
file_put_contents('qr.svg', $qr->toSvg(6));

echo $qr->getVersion();               // e.g. 2
var_dump($qr->isDark(0, 0));          // top-left finder module
```

`encode()` picks the smallest version that fits and throws `InvalidArgumentException` when the text is too long for version 10 at the chosen level.

## Example

```bash
php examples/qr-example.php   # writes qr-url.png + qr-url.svg, prints an ASCII preview
```

## License

MIT
