<?php

namespace App\Services\Certifications;

use App\Models\CertificationSubmission;
use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CertificateGeneratorService
{
    public function approveSubmission(CertificationSubmission $submission, ?string $adminNote, ?string $adminId): CertificationSubmission
    {
        return DB::transaction(function () use ($submission, $adminNote, $adminId) {
            /** @var CertificationSubmission $submission */
            $submission = CertificationSubmission::query()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $now = now();

            $submission->forceFill([
                'status' => CertificationSubmission::STATUS_APPROVED,
                'admin_note' => $adminNote,
                'approved_by' => $adminId,
                'approved_at' => $now,
                'rejected_by' => null,
                'rejected_at' => null,
            ]);

            if (! $submission->certificate_number) {
                $submission->certificate_number = $this->nextCertificateNumber($submission);
            }

            if (! $submission->issued_at) {
                $submission->issued_at = $now;
            }

            if ($this->shouldWriteCertificatePdf($submission)) {
                $this->writeCertificatePdf($submission, $now);
            } elseif ($submission->certificate_file_path) {
                $submission->certificate_download_url = $this->downloadUrl($submission);
            }

            $submission->save();

            return $submission->refresh();
        });
    }


    public function ensureCertificate(CertificationSubmission $submission): CertificationSubmission
    {
        return DB::transaction(function () use ($submission) {
            /** @var CertificationSubmission $submission */
            $submission = CertificationSubmission::query()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $now = now();

            if (! $submission->certificate_number) {
                $submission->certificate_number = $this->nextCertificateNumber($submission);
            }

            if (! $submission->issued_at) {
                $submission->issued_at = $now;
            }

            if ($this->shouldWriteCertificatePdf($submission)) {
                $this->writeCertificatePdf($submission, $now);
            } elseif ($submission->certificate_file_path) {
                $submission->certificate_download_url = $this->downloadUrl($submission);
            }

            $submission->save();

            return $submission->refresh();
        });
    }

    public function regeneratePdf(CertificationSubmission $submission): CertificationSubmission
    {
        return DB::transaction(function () use ($submission) {
            /** @var CertificationSubmission $submission */
            $submission = CertificationSubmission::query()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $now = now();

            if (! $submission->certificate_number) {
                $submission->certificate_number = $this->nextCertificateNumber($submission);
            }

            if (! $submission->issued_at) {
                $submission->issued_at = $now;
            }

            $this->writeCertificatePdf($submission, $now);
            $submission->save();

            return $submission->refresh();
        });
    }

    private function nextCertificateNumber(CertificationSubmission $submission): string
    {
        $typePrefix = $submission->certification_type === CertificationSubmission::TYPE_LEADERSHIP ? 'LEAD' : 'ENT';
        $year = now()->format('Y');
        $prefix = $typePrefix . '-' . $year . '-';

        $numbers = CertificationSubmission::query()
            ->where('certificate_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->pluck('certificate_number')
            ->all();

        $max = 0;
        foreach ($numbers as $number) {
            $suffix = (int) Str::afterLast((string) $number, '-');
            $max = max($max, $suffix);
        }

        do {
            $candidate = $prefix . str_pad((string) (++$max), 6, '0', STR_PAD_LEFT);
        } while (CertificationSubmission::query()->where('certificate_number', $candidate)->exists());

        return $candidate;
    }

    private function shouldWriteCertificatePdf(CertificationSubmission $submission): bool
    {
        return ! $submission->certificate_file_path
            || ! $submission->certificate_download_url
            || ! $submission->certificate_generated_at
            || ! Storage::disk('public')->exists($submission->certificate_file_path);
    }

    private function writeCertificatePdf(CertificationSubmission $submission, $generatedAt): void
    {
        $fileName = $submission->certificate_number . '.pdf';
        $relativePath = 'certificates/' . $fileName;
        $pdfBytes = $this->renderPdf($submission);

        Storage::disk('public')->makeDirectory('certificates');
        Storage::disk('public')->put($relativePath, $pdfBytes);

        $fullPath = Storage::disk('public')->path($relativePath);
        Log::info('Certification certificate generated', [
            'certification_submission_id' => (string) $submission->id,
            'certificate_number' => $submission->certificate_number,
            'certificate_file_path' => $relativePath,
            'storage_path' => $fullPath,
            'file_exists' => Storage::disk('public')->exists($relativePath),
            'certificate_download_url' => $this->downloadUrl($submission),
        ]);

        $submission->forceFill([
            'certificate_file_path' => $relativePath,
            'certificate_download_url' => $this->downloadUrl($submission),
            'certificate_generated_at' => $generatedAt,
        ]);
    }

    private function downloadUrl(CertificationSubmission $submission): string
    {
        return url('/api/v1/admin/certifications/' . $submission->id . '/download');
    }

    private function renderPdf(CertificationSubmission $submission): string
    {
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.certificates.certification', [
                'submission' => $submission,
                'logoSrc' => $this->certificateLogoSrc(),
            ])->setPaper('a4', 'landscape')->output();
        }

        return $this->renderBasicPdf($submission);
    }

    private function renderBasicPdf(CertificationSubmission $submission): string
    {
        $issuedDate = optional($submission->issued_at ?: now())->format('d M Y');
        $approvedDate = optional($submission->approved_at ?: now())->format('d M Y');
        $details = [
            ['Certification Type', $this->certificationTypeLabel($submission), 'Business Name', $submission->business_name ?: 'N/A'],
            ['Certification Level', $submission->certification_level ?: 'N/A', 'Score', (string) (int) $submission->total_score],
            ['Percentage', (int) $submission->percentage . '%', 'Issued Date', $issuedDate],
            ['Certificate Number', (string) $submission->certificate_number, 'Approved Date', $approvedDate],
        ];

        $content = "q\n1 0.992 0.961 rg\n0 0 842 595 re f\nQ\n";
        $content .= "q\n0.043 0.106 0.227 RG\n8 w\n28 28 786 539 re S\nQ\n";
        $content .= "q\n0.788 0.635 0.153 RG\n3 w\n44 44 754 507 re S\n1 w\n64 64 714 467 re S\nQ\n";
        $content .= "q\n0.788 0.635 0.153 RG\n2 w\n180 432 482 0 m 662 432 l S\nQ\n";
        $content .= $this->pdfText('PeersGlobal', 312, 500, 30, 'F1', '0.169 0.039 0.408');
        $content .= $this->pdfText('Community of Collaboration', 330, 478, 10, 'F1', '0.420 0.447 0.502');
        $content .= $this->pdfText('CERTIFICATE OF ACHIEVEMENT', 312, 454, 12, 'F1', '0.420 0.447 0.502');
        $content .= $this->pdfText($this->certificateTitle($submission), 292, 408, 27, 'F1', '0.043 0.106 0.227');
        $content .= $this->pdfText('This certificate is proudly presented to', 304, 370, 14, 'F1', '0.216 0.255 0.318');
        $content .= $this->pdfText((string) $submission->full_name, 250, 329, 34, 'F2', '0.067 0.094 0.153');
        $content .= "q\n0.788 0.635 0.153 RG\n2 w\n242 314 358 0 m 600 314 l S\nQ\n";
        $content .= $this->pdfText($this->certificateDescription($submission), 86, 286, 10, 'F1', '0.216 0.255 0.318');

        $content .= "q\n1 1 1 rg\n0.898 0.906 0.922 RG\n1 w\n112 168 618 90 re B\nQ\n";
        $y = 235;
        foreach ($details as $detail) {
            $content .= $this->pdfText($detail[0], 128, $y, 9, 'F1', '0.420 0.447 0.502');
            $content .= $this->pdfText($detail[1], 230, $y, 10, 'F1', '0.067 0.094 0.153');
            $content .= $this->pdfText($detail[2], 430, $y, 9, 'F1', '0.420 0.447 0.502');
            $content .= $this->pdfText($detail[3], 535, $y, 10, 'F1', '0.067 0.094 0.153');
            $y -= 20;
        }

        $content .= "q\n1 0.973 0.839 rg\n0.788 0.635 0.153 RG\n3 w\n382 73 78 78 re B\nQ\n";
        $content .= $this->pdfText('CERTIFIED', 394, 118, 11, 'F1', '0.043 0.106 0.227');
        $content .= $this->pdfText('APPROVED', 395, 101, 9, 'F1', '0.043 0.106 0.227');
        $content .= "q\n0.067 0.094 0.153 RG\n1.5 w\n115 104 165 0 m 280 104 l S\n562 104 165 0 m 727 104 l S\nQ\n";
        $content .= $this->pdfText('Program Director', 153, 86, 10, 'F1', '0.067 0.094 0.153');
        $content .= $this->pdfText('PeersGlobal', 168, 70, 8, 'F1', '0.420 0.447 0.502');
        $content .= $this->pdfText('Authorized Signatory', 592, 86, 10, 'F1', '0.067 0.094 0.153');
        $content .= $this->pdfText('Certification Department', 591, 70, 8, 'F1', '0.420 0.447 0.502');

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R /F2 6 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream\nendobj\n",
            "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function pdfText(string $text, int $x, int $y, int $size, string $font, string $color): string
    {
        return "BT\n{$color} rg\n/{$font} {$size} Tf\n{$x} {$y} Td\n(" . $this->escapePdfText($text) . ") Tj\nET\n";
    }

    private function certificateTitle(CertificationSubmission $submission): string
    {
        return $submission->certification_type === CertificationSubmission::TYPE_LEADERSHIP
            ? 'Leadership Certification'
            : 'Entrepreneur Certification';
    }

    private function certificationTypeLabel(CertificationSubmission $submission): string
    {
        return $submission->certification_type === CertificationSubmission::TYPE_LEADERSHIP
            ? 'Leadership'
            : 'Entrepreneur';
    }

    private function certificateDescription(CertificationSubmission $submission): string
    {
        if ($submission->certification_type === CertificationSubmission::TYPE_ENTREPRENEUR) {
            return 'Awarded for completing the Entrepreneur Certification and demonstrating entrepreneurial mindset, business awareness, innovation, and growth.';
        }

        return 'Awarded for completing the Leadership Certification and demonstrating leadership values, responsibility, collaboration, and commitment to growth.';
    }

    private function certificateLogoSrc(): ?string
    {
        foreach ($this->logoPathCandidates() as $path) {
            if (is_file($path) && is_readable($path)) {
                return $this->base64ImageSrc($path);
            }
        }

        $file = File::find('019bd9d7-7e13-71fc-8395-0e1dd20a268b');
        if ($file && $file->s3_key) {
            $disk = config('filesystems.default', 'public');
            if (Storage::disk($disk)->exists($file->s3_key)) {
                $mime = $file->mime_type ?: Storage::disk($disk)->mimeType($file->s3_key) ?: 'image/png';

                return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk($disk)->get($file->s3_key));
            }
        }

        return null;
    }

    private function logoPathCandidates(): array
    {
        return [
            public_path('assets/images/logo.png'),
            public_path('images/logo.png'),
            public_path('admin/assets/logo.png'),
            public_path('storage/logo.png'),
            public_path('favicon.png'),
        ];
    }

    private function base64ImageSrc(string $path): string
    {
        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    private function escapePdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
