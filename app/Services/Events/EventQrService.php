<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventQrService
{
    public function generateToken(): string
    {
        return hash_hmac('sha256', Str::random(80).'|'.Str::uuid(), config('app.key'));
    }

    public function payload(string $qrToken): string
    {
        return url('/api/v1/events/checkin/qr/'.$qrToken);
    }

    public function generateAndStore(EventRegistration $registration): array
    {
        $payload = $this->payload($registration->qr_token);
        $png = $this->makePng($payload);
        $relativePath = 'event-qrcodes/'.$registration->event_id.'/'.$registration->id.'.png';

        Storage::disk('public')->put($relativePath, $png);

        $url = $this->url($relativePath);
        $qrData = ['path' => $relativePath, 'url' => $url];

        $updates = [
            'qr_code_path' => $relativePath,
            'qr_code_url' => $url,
        ];

        if (Schema::hasColumn('event_registrations', 'qr_code_svg')) {
            $updates['qr_code_svg'] = null;
        }

        if (Schema::hasColumn('event_registrations', 'qr_generated_at')) {
            $updates['qr_generated_at'] = now();
        }

        $registration->update($updates);

        return $qrData;
    }

    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return url(Storage::disk('public')->url($path));
    }

    private function makePng(string $payload): string
    {
        $writer = new PngWriter();
        $qrCode = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 1024,
            margin: 40,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255),
        );

        return $writer->write($qrCode)->getString();
    }
}
