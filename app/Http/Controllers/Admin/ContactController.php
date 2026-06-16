<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    private const CSV_COLUMNS = [
        'full_name',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'company',
        'job_title',
        'nickname',
        'notes',
        'emails',
        'phones',
        'addresses',
    ];

    public function index(Request $request): View
    {
        $filters = $request->only(['search', 'company', 'job_title', 'from_date', 'to_date', 'date_preset']);

        $contactPosts = $this->filteredQuery($filters)
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.contacts.index', [
            'contactPosts' => $contactPosts,
            'filters' => $filters,
            'companies' => $this->filterOptions('company'),
            'jobTitles' => $this->filterOptions('job_title'),
        ]);
    }

    public function import(): View
    {
        return view('admin.contacts.import');
    }

    public function importStore(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $path = $request->file('csv_file')->getRealPath();
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return back()->withErrors(['csv_file' => 'Unable to read the uploaded CSV file.'])->withInput();
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return back()->withErrors(['csv_file' => 'CSV file is empty.'])->withInput();
        }

        $header = array_map(fn ($column) => Str::of((string) $column)->trim()->lower()->toString(), $header);
        $unknownColumns = array_diff($header, self::CSV_COLUMNS);
        if ($unknownColumns !== []) {
            fclose($handle);
            return back()->withErrors(['csv_file' => 'Unsupported CSV columns: '.implode(', ', $unknownColumns).'.'])->withInput();
        }

        $imported = 0;
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $rowData = $this->combineCsvRow($header, $row);

            if ($this->isEmptyCsvRow($rowData)) {
                continue;
            }

            $fullName = trim((string) ($rowData['full_name'] ?? ''));
            if ($fullName === '') {
                fclose($handle);
                return back()->withErrors(['csv_file' => "Row {$rowNumber}: Full name is required."])->withInput();
            }

            $email = trim((string) ($rowData['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fclose($handle);
                return back()->withErrors(['csv_file' => "Row {$rowNumber}: Email must be a valid email address."])->withInput();
            }

            foreach (['emails', 'phones', 'addresses'] as $jsonField) {
                $parsed = $this->parseJsonCsvValue($rowData[$jsonField] ?? null, $rowNumber, $jsonField);
                if (is_string($parsed)) {
                    fclose($handle);
                    return back()->withErrors(['csv_file' => $parsed])->withInput();
                }
                $rowData[$jsonField] = $parsed;
            }

            $payload = array_intersect_key($rowData, array_flip(self::CSV_COLUMNS));
            $payload['full_name'] = $fullName;
            $payload['email'] = $email !== '' ? $email : null;
            $payload['user_id'] = null;

            if ($payload['email'] !== null) {
                $contact = ContactPost::query()->where('email', $payload['email'])->first();
                if ($contact) {
                    $contact->fill($payload);
                    $contact->save();
                    $imported++;
                    continue;
                }
            }

            $payload['id'] = (string) Str::uuid();
            ContactPost::query()->create($payload);
            $imported++;
        }

        fclose($handle);

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', "Contacts imported successfully. Total imported: {$imported}");
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['search', 'company', 'job_title', 'from_date', 'to_date', 'date_preset']);
        $fileName = 'contacts-'.now()->format('Y-m-d-His').'.csv';
        $columns = [
            'id',
            'user_id',
            'full_name',
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'phone',
            'company',
            'job_title',
            'nickname',
            'notes',
            'emails',
            'phones',
            'addresses',
            'created_at',
            'updated_at',
        ];

        return response()->streamDownload(function () use ($filters, $columns): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, $columns);

            $this->filteredQuery($filters)
                ->latest('created_at')
                ->chunk(500, function ($contacts) use ($output, $columns): void {
                    foreach ($contacts as $contact) {
                        fputcsv($output, $this->contactCsvRow($contact, $columns));
                    }
                });

            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    public function show(Request $request, string $id): View
    {
        $contactPost = ContactPost::with('user')->findOrFail($id);
        $filters = $this->showFilters($request);
        $detailData = $this->filteredDetailData($contactPost, $filters);

        return view('admin.contacts.show', array_merge([
            'contactPost' => $contactPost,
            'detailFilters' => $filters,
        ], $detailData));
    }

    public function exportShow(Request $request, string $id): StreamedResponse
    {
        $contactPost = ContactPost::query()->findOrFail($id);
        $filters = $this->showFilters($request);
        $detailData = $this->filteredDetailData($contactPost, $filters);
        $fileName = 'contact-detail-'.$contactPost->id.'.csv';
        $columns = [
            'id',
            'user_id',
            'full_name',
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'phone',
            'company',
            'job_title',
            'nickname',
            'notes',
            'emails',
            'phones',
            'addresses',
            'created_at',
            'updated_at',
        ];

        return response()->streamDownload(function () use ($columns, $contactPost, $detailData): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, $columns);
            fputcsv($output, $this->contactCsvRow($contactPost, $columns, [
                'emails' => $detailData['filteredEmails'],
                'phones' => $detailData['filteredPhones'],
                'addresses' => $detailData['filteredAddresses'],
            ]));
            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    private function showFilters(Request $request): array
    {
        return [
            'search' => trim((string) $request->query('search', '')),
            'data_type' => $request->query('data_type', 'all') ?: 'all',
            'from_date' => $request->query('from_date'),
            'to_date' => $request->query('to_date'),
            'quick' => $request->query('quick', 'any') ?: 'any',
        ];
    }

    private function filteredDetailData(ContactPost $contactPost, array $filters): array
    {
        $dateMatches = $this->contactMatchesDetailDateFilters($contactPost, $filters);
        $dataType = $filters['data_type'] ?? 'all';
        $search = $filters['search'] ?? '';

        $emails = $dateMatches && in_array($dataType, ['all', 'emails'], true)
            ? $this->filterDetailItems($this->normalizeDetailItems($contactPost->emails, ['email', 'value', 'address'], 'Email'), $search)
            : [];
        $phones = $dateMatches && in_array($dataType, ['all', 'phones'], true)
            ? $this->filterDetailItems($this->normalizeDetailItems($contactPost->phones, ['phone', 'phone_number', 'number', 'value', 'mobile'], 'Phone'), $search)
            : [];
        $addresses = $dateMatches && in_array($dataType, ['all', 'addresses'], true)
            ? $this->filterDetailItems($this->normalizeDetailItems($contactPost->addresses, ['address', 'value', 'full_address', 'line', 'formatted'], 'Address'), $search)
            : [];

        $notesMatches = $dateMatches
            && in_array($dataType, ['all', 'notes'], true)
            && ($search === '' || str_contains(mb_strtolower((string) $contactPost->notes), mb_strtolower($search)));

        return [
            'filteredEmails' => $emails,
            'filteredPhones' => $phones,
            'filteredAddresses' => $addresses,
            'showBasicSection' => $dataType === 'all' && $search === '' && $dateMatches,
            'showDateSection' => $dataType === 'all' && $search === '' && $dateMatches,
            'showNotesSection' => $notesMatches,
            'detailDateMatches' => $dateMatches,
            'hasMatchingContactDetails' => $notesMatches || $emails !== [] || $phones !== [] || $addresses !== [],
        ];
    }

    private function contactMatchesDetailDateFilters(ContactPost $contactPost, array $filters): bool
    {
        $createdAt = $contactPost->created_at;
        if (! $createdAt) {
            return blank($filters['from_date'] ?? null)
                && blank($filters['to_date'] ?? null)
                && in_array($filters['quick'] ?? 'any', ['any', ''], true);
        }

        if (($filters['quick'] ?? 'any') === 'today' && ! $createdAt->isSameDay(now())) {
            return false;
        }

        if (($filters['quick'] ?? 'any') === 'this_week' && ! $createdAt->betweenIncluded(now()->startOfWeek(), now()->endOfWeek())) {
            return false;
        }

        if (($filters['quick'] ?? 'any') === 'this_month' && ! ($createdAt->month === now()->month && $createdAt->year === now()->year)) {
            return false;
        }

        if (filled($filters['from_date'] ?? null) && $createdAt->toDateString() < $filters['from_date']) {
            return false;
        }

        if (filled($filters['to_date'] ?? null) && $createdAt->toDateString() > $filters['to_date']) {
            return false;
        }

        return true;
    }

    private function normalizeDetailItems(mixed $items, array $valueKeys, string $typeFallback): array
    {
        if (blank($items)) {
            return [];
        }

        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = json_last_error() === JSON_ERROR_NONE ? $decoded : [$items];
        }

        if (! is_array($items)) {
            return [];
        }

        if (! array_is_list($items)) {
            $items = [$items];
        }

        return collect($items)
            ->map(function ($item) use ($valueKeys, $typeFallback) {
                if (is_scalar($item)) {
                    return ['type' => $typeFallback, 'value' => (string) $item];
                }

                if (! is_array($item)) {
                    return null;
                }

                $type = $item['type'] ?? $item['label'] ?? $item['name'] ?? $typeFallback;
                $value = null;
                foreach ($valueKeys as $key) {
                    if (filled($item[$key] ?? null)) {
                        $value = $item[$key];
                        break;
                    }
                }

                if ($value === null && count($item) === 1) {
                    $value = collect($item)->first();
                }

                if (is_array($value)) {
                    $value = collect($value)->filter(fn ($part) => filled($part))->implode(', ');
                }

                if (blank($value)) {
                    return null;
                }

                return [
                    'type' => Str::of((string) $type)->replace(['_', '-'], ' ')->title()->toString(),
                    'value' => (string) $value,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function filterDetailItems(array $items, string $search): array
    {
        if ($search === '') {
            return $items;
        }

        $needle = mb_strtolower($search);

        return collect($items)
            ->filter(fn (array $item) => str_contains(mb_strtolower($item['type'].' '.$item['value']), $needle))
            ->values()
            ->all();
    }

    private function contactCsvRow(ContactPost $contact, array $columns, array $overrides = []): array
    {
        $row = [];
        foreach ($columns as $column) {
            $value = $overrides[$column] ?? $contact->{$column};
            if (in_array($column, ['emails', 'phones', 'addresses'], true)) {
                $value = filled($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : '';
            }
            $row[] = $value;
        }

        return $row;
    }

    private function filteredQuery(array $filters)
    {
        return ContactPost::query()
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    foreach ([
                        'full_name',
                        'first_name',
                        'middle_name',
                        'last_name',
                        'email',
                        'phone',
                        'company',
                        'job_title',
                        'nickname',
                        'notes',
                    ] as $field) {
                        $query->orWhere($field, 'ILIKE', "%{$search}%");
                    }

                    foreach (['emails', 'phones', 'addresses'] as $jsonField) {
                        $query->orWhereRaw("{$jsonField}::text ILIKE ?", ["%{$search}%"]);
                    }
                });
            })
            ->when($filters['company'] ?? null, fn ($query, string $company) => $query->where('company', $company))
            ->when($filters['job_title'] ?? null, fn ($query, string $jobTitle) => $query->where('job_title', $jobTitle))
            ->when(($filters['date_preset'] ?? null) === 'today', fn ($query) => $query->whereDate('created_at', now()->toDateString()))
            ->when(($filters['date_preset'] ?? null) === 'this_week', fn ($query) => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]))
            ->when(($filters['date_preset'] ?? null) === 'this_month', fn ($query) => $query->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year))
            ->when($filters['from_date'] ?? null, fn ($query, string $fromDate) => $query->whereDate('created_at', '>=', $fromDate))
            ->when($filters['to_date'] ?? null, fn ($query, string $toDate) => $query->whereDate('created_at', '<=', $toDate));
    }

    private function filterOptions(string $column)
    {
        return ContactPost::query()
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column);
    }

    private function combineCsvRow(array $header, array $row): array
    {
        $row = array_pad($row, count($header), null);
        $data = [];

        foreach ($header as $index => $column) {
            $value = $row[$index] ?? null;
            $data[$column] = is_string($value) ? trim($value) : $value;
        }

        return $data;
    }

    private function isEmptyCsvRow(array $rowData): bool
    {
        return collect($rowData)->every(fn ($value) => blank($value));
    }

    private function parseJsonCsvValue(mixed $value, int $rowNumber, string $field): array|string|null
    {
        if (blank($value)) {
            return null;
        }

        $decoded = json_decode((string) $value, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return 'Row '.$rowNumber.': '.$field.' must be valid JSON.';
        }

        return $decoded;
    }
}
