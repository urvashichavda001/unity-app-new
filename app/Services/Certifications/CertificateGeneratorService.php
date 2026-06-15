<?php

namespace App\Services\Certifications;

use App\Models\CertificationSubmission;
use Illuminate\Support\Facades\DB;
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

            $this->populateCertificateMetadata($submission, $now);
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

            $this->populateCertificateMetadata($submission, now());
            $submission->save();

            return $submission->refresh();
        });
    }

    public function regeneratePdf(CertificationSubmission $submission): CertificationSubmission
    {
        return $this->ensureCertificate($submission);
    }

    private function populateCertificateMetadata(CertificationSubmission $submission, $generatedAt): void
    {
        if (! $submission->certificate_number) {
            $submission->certificate_number = $this->nextCertificateNumber($submission);
        }

        if (! $submission->issued_at) {
            $submission->issued_at = $generatedAt;
        }

        $submission->forceFill([
            'certificate_file_path' => null,
            'certificate_download_url' => $this->downloadUrl($submission),
            'certificate_generated_at' => $generatedAt,
        ]);
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

    private function downloadUrl(CertificationSubmission $submission): string
    {
        return url('/admin/certificates/' . $submission->id . '/view');
    }
}
