<?php

namespace App\Console\Commands;

use App\Services\Events\EventQrService;
use App\Support\QrCode\NativeQrCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditEventQrCode extends Command
{
    protected $signature = 'qr:audit {content=https://google.com : Exact QR content to encode}';

    protected $description = 'Generate audit QR PNG and SVG files for scanner compatibility checks.';

    public function handle(EventQrService $service): int
    {
        $content = (string) $this->argument('content');
        $qr = new NativeQrCode($content, $service->errorCorrectionLevel());
        $basePath = 'event-qrcodes/audit/'.now()->format('YmdHis').'-'.substr(hash('sha256', $content), 0, 12);
        $pngPath = $basePath.'.png';
        $svgPath = $basePath.'.svg';

        Storage::disk('public')->put($pngPath, $qr->png($service->size(), $service->quietZoneModules()));
        Storage::disk('public')->put($svgPath, $qr->svg($service->size(), $service->quietZoneModules()));

        $this->line('Encoded content: '.$content);
        $this->line('Library: '.$service->libraryName().' '.$service->libraryVersion());
        $this->line('Formats: PNG + SVG');
        $this->line('Error correction: '.$service->errorCorrectionLevel());
        $this->line('Pixel size: '.$service->size());
        $this->line('Quiet zone modules: '.$service->quietZoneModules());
        $this->line('QR version: '.$qr->version());
        $this->line('QR modules: '.$qr->moduleSize().'x'.$qr->moduleSize());
        $this->line('PNG: '.$service->url($pngPath));
        $this->line('SVG: '.$service->url($svgPath));

        return self::SUCCESS;
    }
}
