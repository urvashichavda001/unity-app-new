<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Support\QrCode\NativeQrCode;
use Illuminate\Support\Facades\Log;
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
        $this->logPayload($payload, (string) $registration->id);

        $svg = $this->makeSvg($payload);
        $png = $this->makePng($payload);
        $basePath = 'event-qrcodes/'.$registration->event_id.'/'.$registration->id;
        $pngPath = $basePath.'.png';
        $svgPath = $basePath.'.svg';

        Storage::disk('public')->put($pngPath, $png);
        Storage::disk('public')->put($svgPath, $svg);

        $url = $this->url($pngPath);
        $qrData = ['path' => $pngPath, 'url' => $url, 'svg_path' => $svgPath, 'svg' => $svg];

        $updates = [
            'qr_code_path' => $pngPath,
            'qr_code_url' => $url,
            'qr_code_svg' => $svg,
        ];

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

    public function libraryName(): string
    {
        return 'App\\Support\\QrCode\\NativeQrCode';
    }

    public function libraryVersion(): string
    {
        return '1.0.0';
    }

    public function errorCorrectionLevel(): string
    {
        return 'Q';
    }

    public function size(): int
    {
        return 500;
    }

    public function quietZoneModules(): int
    {
        return 4;
    }

    private function buildQr(string $payload): NativeQrCode
    {
        $this->validatePayload($payload);

        return new NativeQrCode($payload, $this->errorCorrectionLevel());
    }

    private function makeSvg(string $payload): string
    {
        return $this->buildQr($payload)->svg($this->size(), $this->quietZoneModules());
    }

    private function makePng(string $payload): string
    {
        return $this->buildQr($payload)->png($this->size(), $this->quietZoneModules());
    }

    private function validatePayload(string $payload): void
    {
        if ($payload === '') {
            throw new \InvalidArgumentException('QR payload must not be empty.');
        }

        if (! mb_check_encoding($payload, 'UTF-8')) {
            throw new \InvalidArgumentException('QR payload must be valid UTF-8.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $payload) === 1) {
            throw new \InvalidArgumentException('QR payload contains unsupported control characters.');
        }
    }

    private function logPayload(string $payload, string $registrationId): void
    {
        Log::info('event_qr_payload_encoding', [
            'event_registration_id' => $registrationId,
            'library' => $this->libraryName(),
            'library_version' => $this->libraryVersion(),
            'format' => 'png+svg',
            'size_px' => $this->size(),
            'quiet_zone_modules' => $this->quietZoneModules(),
            'error_correction' => $this->errorCorrectionLevel(),
            'payload' => $payload,
            'payload_length_bytes' => strlen($payload),
            'payload_sha256' => hash('sha256', $payload),
        ]);
    }


}
