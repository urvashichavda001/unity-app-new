<?php

namespace App\Services\AdminCampaigns;

use App\Models\Circle;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

class CampaignAudienceImportService
{
    private const MAX_ROWS = 10000;

    private const COLUMN_MAP = [
        'city' => [
            'filters' => ['cities'],
            'columns' => ['city'],
        ],
        'company' => [
            'filters' => ['companies'],
            'columns' => ['company_name', 'company'],
        ],
        'membership_status' => [
            'filters' => ['membership_statuses'],
            'columns' => ['membership_status', 'member_status', 'status'],
        ],
        'specific_members' => [
            'filters' => ['user_ids'],
            'columns' => ['email', 'phone', 'user_id', 'member_id', 'id'],
        ],
        'category' => [
            'filters' => ['business_category_ids'],
            'columns' => ['business_category_id', 'business_category', 'business_category_name', 'category_id', 'category'],
        ],
        'circle' => [
            'filters' => ['circle_ids'],
            'columns' => ['circle_id', 'circle_name', 'circle'],
        ],
        'custom_filter' => [
            'filters' => ['cities', 'companies', 'membership_statuses', 'user_ids', 'business_category_ids', 'circle_ids'],
            'columns' => [],
        ],
    ];

    public function __construct(private readonly CampaignRecipientResolverService $recipientResolver)
    {
    }

    public function import(UploadedFile $file, string $audienceType): array
    {
        if (! isset(self::COLUMN_MAP[$audienceType])) {
            throw new InvalidArgumentException('Unsupported audience type for import.');
        }

        [$headers, $rows] = $this->readFile($file);
        $detectedColumns = array_values($headers);
        $matched = $this->matchedColumns($headers, $audienceType);

        if ($matched === []) {
            throw new InvalidArgumentException('No matching audience columns were found in the uploaded file.');
        }

        $importedFilters = [];
        foreach ($matched as $filter => $columns) {
            $rawValues = $this->extractValues($rows, $columns);
            $importedFilters[$filter] = $this->resolveFilterValues($filter, $rawValues);
        }

        $importedFilters = collect($importedFilters)
            ->filter(fn (array $payload): bool => ! empty($payload['values']))
            ->all();

        if ($importedFilters === []) {
            throw new InvalidArgumentException('No audience values were found after removing duplicates and empty rows.');
        }

        $primaryFilter = array_key_first($importedFilters);
        $primary = $importedFilters[$primaryFilter];
        $count = collect($importedFilters)->sum(fn (array $payload): int => count($payload['values']));

        return [
            'columns' => $detectedColumns,
            'matched_columns' => collect($matched)->map(fn (array $columns): array => collect($columns)->map(fn ($column): string => (string) ($headers[$column] ?? $column))->values()->all())->all(),
            'values' => $primary['values'],
            'labels' => $primary['labels'],
            'count' => $count,
            'filter' => $primaryFilter,
            'filters' => $importedFilters,
        ];
    }

    private function matchedColumns(array $headers, string $audienceType): array
    {
        $normalizedHeaders = collect($headers)->mapWithKeys(fn (string $header, string|int $key): array => [
            $key => $this->normalizeHeader($header),
        ])->all();

        $filters = $audienceType === 'custom_filter'
            ? self::COLUMN_MAP['custom_filter']['filters']
            : self::COLUMN_MAP[$audienceType]['filters'];

        $matched = [];
        foreach ($filters as $filter) {
            $candidates = $this->candidateColumnsForFilter($filter);
            foreach ($normalizedHeaders as $key => $header) {
                if ($this->headerMatches($header, $candidates)) {
                    $matched[$filter][] = $key;
                }
            }
        }

        return $matched;
    }

    private function candidateColumnsForFilter(string $filter): array
    {
        return match ($filter) {
            'cities' => self::COLUMN_MAP['city']['columns'],
            'companies' => self::COLUMN_MAP['company']['columns'],
            'membership_statuses' => self::COLUMN_MAP['membership_status']['columns'],
            'user_ids' => self::COLUMN_MAP['specific_members']['columns'],
            'business_category_ids' => self::COLUMN_MAP['category']['columns'],
            'circle_ids' => self::COLUMN_MAP['circle']['columns'],
            default => [],
        };
    }

    private function headerMatches(string $header, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeHeader($candidate);
            if ($header === $candidate || Str::contains($header, $candidate) || Str::contains($candidate, $header)) {
                return true;
            }
        }

        return false;
    }

    private function extractValues(array $rows, array $columns): array
    {
        $values = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return collect($values)->unique(fn (string $value): string => Str::lower($value))->values()->all();
    }

    private function resolveFilterValues(string $filter, array $values): array
    {
        return match ($filter) {
            'user_ids' => $this->resolveMemberValues($values),
            'circle_ids' => $this->resolveCircleValues($values),
            'business_category_ids' => $this->resolveCategoryValues($values),
            default => $this->valuesAndLabels($values),
        };
    }

    private function resolveMemberValues(array $values): array
    {
        $ids = collect();
        foreach ($values as $value) {
            $query = User::query()->select(['id', 'display_name', 'first_name', 'last_name', 'email', 'phone']);
            $query->where(function ($builder) use ($value): void {
                if (Schema::hasColumn('users', 'id') && Str::isUuid($value)) {
                    $builder->orWhere('id', $value);
                }
                if (Schema::hasColumn('users', 'email')) {
                    $builder->orWhereRaw('LOWER(email) = ?', [Str::lower($value)]);
                }
                if (Schema::hasColumn('users', 'phone')) {
                    $builder->orWhere('phone', $value);
                }
            });
            $user = $query->first();
            if ($user) {
                $ids->push([
                    'value' => (string) $user->id,
                    'label' => $user->adminDisplayName() . ' (' . ($user->email ?: $user->phone ?: $user->id) . ')',
                ]);
            }
        }

        return $this->optionPayload($ids->all());
    }

    private function resolveCircleValues(array $values): array
    {
        $options = collect();
        foreach ($values as $value) {
            $circle = Circle::query()
                ->select(['id', 'name'])
                ->where(function ($query) use ($value): void {
                    if (Str::isUuid($value)) {
                        $query->orWhere('id', $value);
                    }
                    $query->orWhereRaw('LOWER(name) = ?', [Str::lower($value)]);
                })
                ->first();
            if ($circle) {
                $options->push(['value' => (string) $circle->id, 'label' => $circle->name]);
            }
        }

        return $this->optionPayload($options->all());
    }

    private function resolveCategoryValues(array $values): array
    {
        $resolved = $this->recipientResolver->resolveBusinessCategoryValues($values);

        return $this->optionPayload(collect($resolved)->map(fn (array $category): array => [
            'value' => (string) $category['id'],
            'label' => (string) $category['name'],
        ])->all());
    }

    private function valuesAndLabels(array $values): array
    {
        return $this->optionPayload(collect($values)->map(fn (string $value): array => [
            'value' => $value,
            'label' => $value,
        ])->all());
    }

    private function optionPayload(array $options): array
    {
        $unique = collect($options)->filter(fn (array $option): bool => filled($option['value'] ?? null))
            ->unique(fn (array $option): string => Str::lower((string) $option['value']))
            ->values();

        return [
            'values' => $unique->pluck('value')->values()->all(),
            'labels' => $unique->pluck('label', 'value')->all(),
            'options' => $unique->all(),
        ];
    }

    private function readFile(UploadedFile $file): array
    {
        $extension = Str::lower($file->getClientOriginalExtension() ?: $file->extension());

        return match ($extension) {
            'csv' => $this->readCsv($file->getRealPath()),
            'xlsx' => $this->readXlsx($file->getRealPath()),
            'xls' => $this->readLegacySpreadsheet($file->getRealPath()),
            default => throw new InvalidArgumentException('Only CSV, XLSX, and XLS files are supported.'),
        };
    }

    private function readCsv(string $path): array
    {
        $file = new \SplFileObject($path);
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);

        $headers = [];
        $rows = [];
        foreach ($file as $line) {
            if ($line === [null] || $line === false) {
                continue;
            }
            $line = array_map(fn ($value): string => trim((string) $value), $line);
            if ($headers === []) {
                $headers = $this->normalizeHeaders($line);
                continue;
            }
            $rows[] = $this->combineRow($headers, $line);
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return [$headers, $rows];
    }

    private function readLegacySpreadsheet(string $path): array
    {
        $contents = file_get_contents($path, false, null, 0, 8);
        if (str_starts_with((string) $contents, "\xD0\xCF\x11\xE0")) {
            throw new InvalidArgumentException('Legacy binary XLS files are not supported on this server. Please upload CSV or XLSX.');
        }

        return $this->readCsv($path);
    }

    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Unable to open the XLSX file.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetPath = $this->firstWorksheetPath($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();

        if ($sheetXml === false) {
            throw new InvalidArgumentException('Unable to read the first worksheet.');
        }

        $xml = simplexml_load_string($sheetXml);
        if (! $xml) {
            throw new InvalidArgumentException('Unable to parse the first worksheet.');
        }

        $headers = [];
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $index = $this->columnIndexFromReference($ref);
                $cells[$index] = $this->xlsxCellValue($cell, $sharedStrings);
            }
            ksort($cells);
            $line = [];
            for ($i = 0, $max = $cells === [] ? -1 : max(array_keys($cells)); $i <= $max; $i++) {
                $line[] = trim((string) ($cells[$i] ?? ''));
            }
            if (collect($line)->filter()->isEmpty()) {
                continue;
            }
            if ($headers === []) {
                $headers = $this->normalizeHeaders($line);
                continue;
            }
            $rows[] = $this->combineRow($headers, $line);
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return [$headers, $rows];
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $shared = simplexml_load_string($xml);
        if (! $shared) {
            return [];
        }

        $strings = [];
        foreach ($shared->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;
                continue;
            }
            $text = '';
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbookXml = simplexml_load_string($workbook);
        $relsXml = simplexml_load_string($rels);
        if (! $workbookXml || ! $relsXml || ! isset($workbookXml->sheets->sheet[0])) {
            return 'xl/worksheets/sheet1.xml';
        }

        $attributes = $workbookXml->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($attributes['id'] ?? '');
        foreach ($relsXml->Relationship as $relationship) {
            if ((string) $relationship['Id'] === $relationshipId) {
                $target = ltrim((string) $relationship['Target'], '/');
                return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        if ($type === 's') {
            return (string) ($sharedStrings[(int) $cell->v] ?? '');
        }
        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }

        return (string) ($cell->v ?? '');
    }

    private function normalizeHeaders(array $headers): array
    {
        return collect($headers)->map(fn ($header, int $index): string => trim((string) $header) ?: 'column_' . ($index + 1))->all();
    }

    private function combineRow(array $headers, array $line): array
    {
        $row = [];
        foreach ($headers as $index => $header) {
            $row[$index] = $line[$index] ?? null;
        }

        return $row;
    }

    private function normalizeHeader(string $header): string
    {
        return trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', Str::lower($header))), '_');
    }

    private function columnIndexFromReference(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = Str::upper($matches[0] ?? 'A');
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - ord('A') + 1);
        }

        return $index - 1;
    }
}
