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

    public function storeImport(Request $request): RedirectResponse
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
                        $row = [];
                        foreach ($columns as $column) {
                            $value = $contact->{$column};
                            if (in_array($column, ['emails', 'phones', 'addresses'], true)) {
                                $value = filled($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : '';
                            }
                            $row[] = $value;
                        }
                        fputcsv($output, $row);
                    }
                });

            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    public function show(string $id): View
    {
        $contactPost = ContactPost::query()->findOrFail($id);

        return view('admin.contacts.show', [
            'contactPost' => $contactPost,
        ]);
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
                    ] as $field) {
                        $query->orWhere($field, 'like', "%{$search}%");
                    }
                });
            })
            ->when($filters['company'] ?? null, fn ($query, string $company) => $query->where('company', $company))
            ->when($filters['job_title'] ?? null, fn ($query, string $jobTitle) => $query->where('job_title', $jobTitle))
            ->when(($filters['date_preset'] ?? null) === 'today', fn ($query) => $query->whereDate('created_at', now()->toDateString()))
            ->when(($filters['date_preset'] ?? null) === 'this_month', fn ($query) => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]))
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
