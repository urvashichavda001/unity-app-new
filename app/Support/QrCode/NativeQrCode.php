<?php

namespace App\Support\QrCode;

use InvalidArgumentException;

class NativeQrCode
{
    private const ECC_FORMAT_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    private const ECC_CODEWORDS_PER_BLOCK = [
        'L' => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28],
        'M' => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26],
        'Q' => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30],
        'H' => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28],
    ];

    private const NUM_ERROR_CORRECTION_BLOCKS = [
        'L' => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8],
        'M' => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16],
        'Q' => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20],
        'H' => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25],
    ];

    private int $version;

    private int $size;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $functionModules = [];

    public function __construct(private readonly string $data, private readonly string $ecc = 'Q')
    {
        if ($data === '') {
            throw new InvalidArgumentException('QR data must not be empty.');
        }

        if (! isset(self::ECC_CODEWORDS_PER_BLOCK[$ecc])) {
            throw new InvalidArgumentException('Unsupported QR error correction level.');
        }

        $this->version = $this->minimumVersion(strlen($data));
        $this->size = ($this->version * 4) + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->functionModules = array_fill(0, $this->size, array_fill(0, $this->size, false));

        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addErrorCorrection($this->encodeData()));
        $this->applyMask(0);
        $this->drawFormatBits(0);
        $this->drawVersionBits();
    }

    public function version(): int
    {
        return $this->version;
    }

    public function moduleSize(): int
    {
        return $this->size;
    }

    public function svg(int $pixelSize = 500, int $quietZone = 4): string
    {
        $viewBoxSize = $this->size + ($quietZone * 2);
        $path = [];

        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $path[] = 'M'.($x + $quietZone).','.($y + $quietZone).'h1v1h-1z';
                }
            }
        }

        $pathData = implode('', $path);
        $safeData = htmlspecialchars($this->data, ENT_QUOTES, 'UTF-8');
        $safeEcc = htmlspecialchars($this->ecc, ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$pixelSize}" height="{$pixelSize}" viewBox="0 0 {$viewBoxSize} {$viewBoxSize}" shape-rendering="crispEdges" role="img" aria-label="Event check-in QR code">
  <rect width="100%" height="100%" fill="#fff"/>
  <metadata data-error-correction="{$safeEcc}">{$safeData}</metadata>
  <path fill="#000" d="{$pathData}"/>
</svg>
SVG;
    }

    public function png(int $pixelSize = 500, int $quietZone = 4): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new InvalidArgumentException('The GD extension is required to render QR PNG output.');
        }

        $sourceSize = $this->size + ($quietZone * 2);
        $scale = max(1, (int) ceil($pixelSize / $sourceSize));
        $imageSize = $sourceSize * $scale;
        $image = imagecreatetruecolor($imageSize, $imageSize);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefill($image, 0, 0, $white);

        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    imagefilledrectangle(
                        $image,
                        ($x + $quietZone) * $scale,
                        ($y + $quietZone) * $scale,
                        (($x + $quietZone + 1) * $scale) - 1,
                        (($y + $quietZone + 1) * $scale) - 1,
                        $black
                    );
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /** @return list<int> */
    private function encodeData(): array
    {
        $capacity = $this->dataCapacityCodewords();
        $bits = [0, 1, 0, 0];
        $countBits = $this->version <= 9 ? 8 : 16;
        $length = strlen($this->data);

        for ($i = $countBits - 1; $i >= 0; $i--) {
            $bits[] = ($length >> $i) & 1;
        }

        foreach (unpack('C*', $this->data) ?: [] as $byte) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }
        }

        $maxBits = $capacity * 8;
        $terminator = min(4, $maxBits - count($bits));
        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }

        for ($pad = 0; count($codewords) < $capacity; $pad ^= 1) {
            $codewords[] = $pad === 0 ? 0xEC : 0x11;
        }

        return $codewords;
    }

    private function minimumVersion(int $byteLength): int
    {
        for ($version = 1; $version <= 20; $version++) {
            $countBits = $version <= 9 ? 8 : 16;
            $neededBits = 4 + $countBits + ($byteLength * 8);
            $capacityBits = $this->dataCapacityCodewords($version) * 8;

            if ($neededBits <= $capacityBits) {
                return max(2, $version);
            }
        }

        throw new InvalidArgumentException('QR data is too long for the native QR renderer.');
    }

    private function dataCapacityCodewords(?int $version = null): int
    {
        $version ??= $this->version;
        $rawCodewords = intdiv($this->numRawDataModules($version), 8);
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$this->ecc][$version];
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$this->ecc][$version];

        return $rawCodewords - ($blockEccLen * $numBlocks);
    }

    private function numRawDataModules(int $version): int
    {
        $result = ((16 * $version) + 128) * $version + 64;

        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= ((25 * $numAlign) - 10) * $numAlign - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    /** @param list<int> $dataCodewords @return list<int> */
    private function addErrorCorrection(array $dataCodewords): array
    {
        $version = $this->version;
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$this->ecc][$version];
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$this->ecc][$version];
        $rawCodewords = intdiv($this->numRawDataModules($version), 8);
        $numShortBlocks = $numBlocks - ($rawCodewords % $numBlocks);
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);
        $generator = $this->reedSolomonGenerator($blockEccLen);
        $blocks = [];
        $offset = 0;

        for ($i = 0; $i < $numBlocks; $i++) {
            $dataLength = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $data = array_slice($dataCodewords, $offset, $dataLength);
            $offset += $dataLength;
            $ecc = $this->reedSolomonRemainder($data, $generator);
            $blocks[] = ['data' => $data, 'ecc' => $ecc];
        }

        $result = [];
        $maxDataLength = max(array_map(fn (array $block): int => count($block['data']), $blocks));
        for ($i = 0; $i < $maxDataLength; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block['data'])) {
                    $result[] = $block['data'][$i];
                }
            }
        }

        for ($i = 0; $i < $blockEccLen; $i++) {
            foreach ($blocks as $block) {
                $result[] = $block['ecc'][$i];
            }
        }

        return $result;
    }

    /** @return list<int> */
    private function reedSolomonGenerator(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = $this->gfMultiply($root, 0x02);
        }

        return $result;
    }

    /** @param list<int> $data @param list<int> $generator @return list<int> */
    private function reedSolomonRemainder(array $data, array $generator): array
    {
        $result = array_fill(0, count($generator), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $result[0];
            array_shift($result);
            $result[] = 0;

            foreach ($generator as $i => $coefficient) {
                $result[$i] ^= $this->gfMultiply($coefficient, $factor);
            }
        }

        return $result;
    }

    private function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }

        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);

        $positions = $this->alignmentPatternPositions();
        foreach ($positions as $x) {
            foreach ($positions as $y) {
                if (($x === 6 && $y === 6) || ($x === 6 && $y === $this->size - 7) || ($x === $this->size - 7 && $y === 6)) {
                    continue;
                }
                $this->drawAlignmentPattern($x, $y);
            }
        }

        $this->setFunctionModule(8, $this->size - 8, true);

        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $this->setFunctionModule(8, $i, false);
                $this->setFunctionModule($i, 8, false);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, false);
            $this->setFunctionModule(8, $this->size - 1 - $i, false);
        }
    }

    private function drawFinderPattern(int $centerX, int $centerY): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $centerX + $dx;
                $y = $centerY + $dy;
                if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
                    continue;
                }
                $distance = max(abs($dx), abs($dy));
                $this->setFunctionModule($x, $y, $distance !== 2 && $distance !== 4);
            }
        }
    }

    private function drawAlignmentPattern(int $centerX, int $centerY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule($centerX + $dx, $centerY + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPatternPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $numAlign = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : (int) ceil(($this->size - 13) / (($numAlign * 2) - 2)) * 2;
        $result = [6];

        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }

        return $result;
    }

    private function setFunctionModule(int $x, int $y, bool $isBlack): void
    {
        $this->modules[$y][$x] = $isBlack;
        $this->functionModules[$y][$x] = true;
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $bitIndex = 0;
        $direction = -1;
        $x = $this->size - 1;
        $y = $this->size - 1;

        while ($x > 0) {
            if ($x === 6) {
                $x--;
            }

            while (true) {
                for ($i = 0; $i < 2; $i++) {
                    $xx = $x - $i;
                    if (! $this->functionModules[$y][$xx] && $bitIndex < count($data) * 8) {
                        $this->modules[$y][$xx] = (($data[intdiv($bitIndex, 8)] >> (7 - ($bitIndex % 8))) & 1) !== 0;
                        $bitIndex++;
                    }
                }

                $y += $direction;
                if ($y < 0 || $y >= $this->size) {
                    $y -= $direction;
                    $direction = -$direction;
                    break;
                }
            }

            $x -= 2;
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if (! $this->functionModules[$y][$x] && $this->maskBit($mask, $x, $y)) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    private function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            7 => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            default => false,
        };
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::ECC_FORMAT_BITS[$this->ecc] << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule(8, $i, (($bits >> $i) & 1) !== 0);
        }
        $this->setFunctionModule(8, 7, (($bits >> 6) & 1) !== 0);
        $this->setFunctionModule(8, 8, (($bits >> 7) & 1) !== 0);
        $this->setFunctionModule(7, 8, (($bits >> 8) & 1) !== 0);
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, (($bits >> $i) & 1) !== 0);
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, (($bits >> $i) & 1) !== 0);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, (($bits >> $i) & 1) !== 0);
        }
        $this->setFunctionModule(8, $this->size - 8, true);
    }

    private function drawVersionBits(): void
    {
        if ($this->version < 7) {
            return;
        }

        $remainder = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $bit);
            $this->setFunctionModule($b, $a, $bit);
        }
    }
}
