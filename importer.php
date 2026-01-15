<?php
declare(strict_types=1);

/**
 * importer.php
 * Importador directo sin namespaces ni autoload PSR-4.
 * - CSV funciona sin dependencias
 * - XLSX usa PhpSpreadsheet si existe vendor/autoload.php y la librería está instalada
 */
class Importer
{
    private array $headerMap = [
        'Computer Name'      => 'computer_name',
        'Remote Office'      => 'remote_office',
        'Domain'             => 'domain',
        'Severity'           => 'severity',
        'Patch ID'           => 'patch_id',
        'Release Date'       => 'release_date',
        'Deployed Date'      => 'deployed_date',
        'Patch Name'         => 'patch_name',
        'Bulletin ID'        => 'bulletin_id',
        'Patch Description'  => 'patch_description',
        'Remarks'            => 'remarks',
        'Patch Status'       => 'patch_status',
        'Agent Version'      => 'agent_version',
        'KB Number'          => 'kb_number',
        'Operating System'   => 'operating_system',
        'Deployed Using'     => 'deployed_using',
        'Deployed By'        => 'deployed_by',
        'Approve Status'     => 'approve_status',
        'Size'               => 'size',
        'Last Contact Time'  => 'last_contact_time',
    ];

    private array $typeMap = [
        'patch_id'          => 'int',
        'release_date'      => 'date',     // "Nov 5, 2025"
        'deployed_date'     => 'datetime', // "Nov 9, 2025 11:14 PM"
        'last_contact_time' => 'datetime', // "Dec 23, 2025 12:34 AM"
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function import(string $filePath, ?string $originalName = null): array
    {
        $name = $originalName ?? basename($filePath);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === 'csv') return $this->importCsv($filePath);
        if ($ext === 'xlsx') return $this->importXlsx($filePath);

        throw new RuntimeException("Formato no soportado: .$ext (usa CSV o XLSX).");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function importCsv(string $csvPath): array
    {
        if (!is_readable($csvPath)) {
            throw new RuntimeException("No puedo leer el CSV: $csvPath");
        }

        $fh = fopen($csvPath, 'r');
        if ($fh === false) throw new RuntimeException("No pude abrir el CSV.");

        $firstLine = fgets($fh);
        if ($firstLine === false) {
            fclose($fh);
            return [];
        }

        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine); // BOM
        $headers = array_map('trim', str_getcsv($firstLine));

        $data = [];
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;

            $item = [];
            foreach ($headers as $i => $originalHeader) {
                if (!array_key_exists($i, $row)) continue;

                $jsonKey = $this->headerMap[$originalHeader] ?? null;
                if ($jsonKey === null) continue;

                $item[$jsonKey] = $this->castValue($jsonKey, $row[$i]);
            }

            if ($this->isNonEmptyRow($item)) $data[] = $item;
        }

        fclose($fh);
        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function importXlsx(string $xlsxPath): array
    {
        if (!is_readable($xlsxPath)) {
            throw new RuntimeException("No puedo leer el XLSX: $xlsxPath");
        }

        // Usa vendor/autoload.php si existe (aunque tú no quieras autoload PSR-4)
        $vendorAutoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException(
                "Para XLSX necesitas PhpSpreadsheet (vendor/autoload.php + phpoffice/phpspreadsheet). " .
                "Si solo usarás CSV, sube CSV."
            );
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsxPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true); // A,B,C...

        if (count($rows) < 2) return [];

        $headerRow = array_shift($rows);

        $headers = [];
        foreach ($headerRow as $col => $title) {
            $headers[$col] = trim((string)$title);
        }

        $data = [];
        foreach ($rows as $r) {
            $item = [];
            foreach ($headers as $col => $originalHeader) {
                $jsonKey = $this->headerMap[$originalHeader] ?? null;
                if ($jsonKey === null) continue;

                $item[$jsonKey] = $this->castValue($jsonKey, $r[$col] ?? null);
            }
            if ($this->isNonEmptyRow($item)) $data[] = $item;
        }

        return $data;
    }

    private function isNonEmptyRow(array $item): bool
    {
        foreach ($item as $v) {
            if ($v !== null && $v !== '') return true;
        }
        return false;
    }

    private function castValue(string $key, mixed $value): mixed
    {
        if (is_string($value)) $value = trim($value);

        if (!isset($this->typeMap[$key])) {
            return ($value === '' ? null : $value);
        }

        return match ($this->typeMap[$key]) {
            'int'      => $this->castInt($value),
            'date'     => $this->parseDateLike($value, 'date'),
            'datetime' => $this->parseDateLike($value, 'datetime'),
            default    => ($value === '' ? null : $value),
        };
    }

    private function castInt(mixed $value): ?int
    {
        if ($value === null) return null;
        $s = trim((string)$value);
        if ($s === '') return null;

        $digits = preg_replace('/[^\d]/', '', $s);
        if (!$digits) return null;

        return (int)$digits;
    }

    private function parseDateLike(mixed $value, string $kind): ?string
    {
        if ($value === null) return null;
        $s = trim((string)$value);
        if ($s === '') return null;

        $formats = ($kind === 'date')
            ? ['M j, Y', 'M d, Y', 'Y-m-d']
            : ['M j, Y g:i A', 'M d, Y g:i A', 'Y-m-d H:i:s', \DateTimeInterface::ATOM];

        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $s);
            if ($dt instanceof DateTime) {
                return ($kind === 'date')
                    ? $dt->format('Y-m-d')
                    : $dt->format('Y-m-d\TH:i:s');
            }
        }

        return $s; // si no matchea
    }
}
