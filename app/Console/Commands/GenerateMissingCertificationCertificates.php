<?php

namespace App\Console\Commands;

use App\Models\CertificationSubmission;
use App\Services\Certifications\CertificateGeneratorService;
use Illuminate\Console\Command;

class GenerateMissingCertificationCertificates extends Command
{
    protected $signature = 'certifications:generate-missing-certificates {--dry-run : Show records that would be processed without generating files}';

    protected $description = 'Generate missing certificate numbers and frontend certificate URLs for approved certification submissions.';

    public function handle(CertificateGeneratorService $certificateGenerator): int
    {
        $query = CertificationSubmission::query()
            ->where('status', CertificationSubmission::STATUS_APPROVED)
            ->where(function ($query) {
                $query->whereNull('certificate_number')
                    ->orWhereNull('certificate_download_url')
                    ->orWhere('certificate_download_url', 'not like', '%/admin/certificates/%/view%')
                    ->orWhereNull('certificate_generated_at')
                    ->orWhereNull('issued_at');
            })
            ->orderBy('created_at');

        $total = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$total} approved certification submission(s) need certificate metadata generation.");
            $query->limit(25)->get(['id', 'full_name', 'email', 'certification_type'])->each(function (CertificationSubmission $submission) {
                $this->line("{$submission->id} | {$submission->certification_type} | {$submission->full_name} | {$submission->email}");
            });

            return self::SUCCESS;
        }

        $processed = 0;

        $query->chunkById(100, function ($submissions) use ($certificateGenerator, &$processed) {
            foreach ($submissions as $submission) {
                $certificateGenerator->ensureCertificate($submission);
                $processed++;
                $this->line("Generated certificate metadata for {$submission->id}");
            }
        });

        $this->info("Generated or refreshed {$processed} certification certificate metadata record(s).");

        return self::SUCCESS;
    }
}
