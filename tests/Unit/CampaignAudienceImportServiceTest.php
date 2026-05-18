<?php

namespace Tests\Unit;

use App\Services\AdminCampaigns\CampaignAudienceImportService;
use App\Services\AdminCampaigns\CampaignRecipientResolverService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class CampaignAudienceImportServiceTest extends TestCase
{
    public function test_it_imports_unique_trimmed_city_values_from_csv_with_fuzzy_header(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'campaign-city-');
        file_put_contents($path, "member_city\n Ahmedabad \nPune\nAhmedabad\n\nMumbai\n");

        $result = $this->service()->import(new UploadedFile($path, 'cities.csv', 'text/csv', null, true), 'city');

        $this->assertSame(['Ahmedabad', 'Pune', 'Mumbai'], $result['values']);
        $this->assertSame(3, $result['count']);
        $this->assertSame(['member_city'], $result['matched_columns']['cities']);
    }

    public function test_it_imports_company_values_from_xlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'campaign-company-');
        $this->writeXlsx($path, [
            ['company_name'],
            ['Acme Industries'],
            ['Unity Ventures'],
            ['Acme Industries'],
            [''],
        ]);

        $result = $this->service()->import(new UploadedFile($path, 'companies.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), 'company');

        $this->assertSame(['Acme Industries', 'Unity Ventures'], $result['values']);
        $this->assertSame(2, $result['count']);
    }

    private function service(): CampaignAudienceImportService
    {
        return new CampaignAudienceImportService(new CampaignRecipientResolverService());
    }

    private function writeXlsx(string $path, array $rows): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');

        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $sheetRows .= '<row r="' . ($rowIndex + 1) . '">';
            foreach ($row as $columnIndex => $value) {
                $cell = chr(ord('A') + $columnIndex) . ($rowIndex + 1);
                $sheetRows .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
            }
            $sheetRows .= '</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>');
        $zip->close();
    }
}
