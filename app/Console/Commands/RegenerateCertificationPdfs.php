<?php

namespace App\Console\Commands;

use App\Models\CertificationSubmission;
use App\Services\Certifications\CertificateGeneratorService;
use Illuminate\Console\Command;

class RegenerateCertificationPdfs extends Command
{
    protected $signature = 'certifications:regenerate-pdfs {--dry-run : Show records that would be regenerated without writing files}';

    protected $description = 'Refresh frontend certificate URLs and metadata for all approved certification submissions.';

    public function handle(CertificateGeneratorService $certificateGenerator): int
    {
        $query = CertificationSubmission::query()
            ->where('status', CertificationSubmission::STATUS_APPROVED)
            ->orderBy('created_at');

        $total = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$total} approved certification metadata record(s) would be refreshed.");
            $query->limit(25)->get(['id', 'certificate_number', 'full_name', 'email', 'certification_type'])->each(function (CertificationSubmission $submission) {
                $this->line("{$submission->id} | {$submission->certificate_number} | {$submission->certification_type} | {$submission->full_name} | {$submission->email}");
            });

            return self::SUCCESS;
        }

        $processed = 0;

        $query->chunkById(100, function ($submissions) use ($certificateGenerator, &$processed) {
            foreach ($submissions as $submission) {
                $certificateGenerator->regeneratePdf($submission);
                $processed++;
                $this->line("Refreshed certificate metadata for {$submission->id}");
            }
        });

        $this->info("Refreshed {$processed} approved certification metadata record(s).");

        return self::SUCCESS;
    }
}
