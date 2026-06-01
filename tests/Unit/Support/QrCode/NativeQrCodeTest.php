<?php

namespace Tests\Unit\Support\QrCode;

use App\Support\QrCode\NativeQrCode;
use Tests\TestCase;

class NativeQrCodeTest extends TestCase
{
    public function test_generates_centered_svg_with_quiet_zone_and_metadata(): void
    {
        $qr = new NativeQrCode('https://google.com', 'Q');
        $svg = $qr->svg(500, 4);

        $this->assertStringContainsString('width="500" height="500"', $svg);
        $this->assertStringContainsString('viewBox="0 0 33 33"', $svg);
        $this->assertStringContainsString('fill="#fff"', $svg);
        $this->assertStringContainsString('fill="#000"', $svg);
        $this->assertStringContainsString('data-error-correction="Q"', $svg);
        $this->assertStringContainsString('https://google.com', $svg);
        $this->assertStringContainsString('M4,4h1v1h-1z', $svg);
        $this->assertStringNotContainsString('transform=', $svg);
    }

    public function test_generates_opaque_png_at_requested_scale_without_resampling(): void
    {
        $qr = new NativeQrCode('https://google.com', 'Q');
        $png = $qr->png(500, 4);
        $image = imagecreatefromstring($png);

        $this->assertNotFalse($image);
        $this->assertSame(528, imagesx($image));
        $this->assertSame(528, imagesy($image));
        $this->assertSame(33, $qr->moduleSize() + 8);
        $this->assertSame(0xFFFFFF, imagecolorat($image, 0, 0) & 0xFFFFFF);
        $this->assertSame(0x000000, imagecolorat($image, 4 * 15, 4 * 15) & 0xFFFFFF);

        imagedestroy($image);
    }

    public function test_rejects_empty_payloads(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NativeQrCode('', 'Q');
    }
}
