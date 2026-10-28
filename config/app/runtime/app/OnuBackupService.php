<?php

declare(strict_types=1);

final class OnuBackupService
{
    public const MAX_FILE_BYTES = 2_097_152;
    public const MAX_ROWS = 10_000;
    public const PREVIEW_TTL_SECONDS = 900;

    public function __construct(private LinnmsRepository $repository)
    {
    }

    
    public function exportRows(int $userId): array
    {
        $rows = $this->repository->getOnus($userId, null, false, null);
        $byMac = [];

        foreach ($rows as $row) {
            $mac = self::normalizeMac((string)($row['onu_serial'] ?? ''));
            if ($mac === null) continue;

            $candidate = [
                'mac_address' => self::formatMac($mac),
                'nama' => trim((string)($row['onu_name'] ?? '')),
                'alamat' => trim((string)($row['onu_location'] ?? '')),
                '_updated_at' => (string)($row['updated_at'] ?? ''),
            ];

            if (!isset($byMac[$mac]) || $candidate['_updated_at'] > $byMac[$mac]['_updated_at']) {
                $byMac[$mac] = $candidate;
            }
        }

        ksort($byMac, SORT_STRING);
        return array_values(array_map(
            static fn(array $row): array => [
                'mac_address' => $row['mac_address'],
                'nama' => $row['nama'],
                'alamat' => $row['alamat'],
            ],
            $byMac
        ));
    }

    
    public function parseUploadedFile(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage($error));
        }

        $size = (int)($file['size'] ?? 0);
        if ($size < 1) throw new InvalidArgumentException('File CSV kosong.');
        if ($size > self::MAX_FILE_BYTES) throw new InvalidArgumentException('Ukuran file CSV maksimal 2 MB.');

        $name = trim((string)($file['name'] ?? ''));
        if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            throw new InvalidArgumentException('File harus menggunakan ekstensi .csv.');
        }

        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !is_uploaded_file($path)) {
            throw new InvalidArgumentException('File upload tidak valid.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('File CSV tidak dapat dibaca.');

        return $this->parseCsvHandle($handle);
    }

    public function parseContent(string $name, string $content): array
    {
        $size = strlen($content);
        if ($size < 1) throw new InvalidArgumentException('File CSV kosong.');
        if ($size > self::MAX_FILE_BYTES) throw new InvalidArgumentException('Ukuran file CSV maksimal 2 MB.');
        if (strtolower((string)pathinfo(trim($name), PATHINFO_EXTENSION)) !== 'csv') {
            throw new InvalidArgumentException('File harus menggunakan ekstensi .csv.');
        }
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) throw new RuntimeException('File CSV tidak dapat dibaca.');
        try {
            if (fwrite($handle, $content) !== $size || rewind($handle) === false) {
                throw new RuntimeException('File CSV tidak dapat dibaca.');
            }
        } catch (Throwable $error) {
            fclose($handle);
            throw $error;
        }
        return $this->parseCsvHandle($handle);
    }

    private function parseCsvHandle($handle): array
    {
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($header)) throw new InvalidArgumentException('File CSV tidak memiliki header.');
            $header = array_map(static function (mixed $value): string {
                $text = trim((string)$value);
                $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
                return strtolower($text);
            }, $header);
            if ($header !== ['mac_address', 'nama', 'alamat']) {
                throw new InvalidArgumentException('Header CSV harus tepat: mac_address,nama,alamat.');
            }

            $records = [];
            $line = 1;
            while (($columns = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($line > self::MAX_ROWS + 1) {
                    throw new InvalidArgumentException('Jumlah data CSV maksimal ' . self::MAX_ROWS . ' baris.');
                }
                if ($columns === [null] || $columns === []) continue;

                $raw = implode('', array_map(static fn(mixed $value): string => (string)$value, $columns));
                if (!mb_check_encoding($raw, 'UTF-8')) {
                    $records[] = $this->invalidRecord($line, '', '', '', 'Encoding baris bukan UTF-8.');
                    continue;
                }
                if (count($columns) !== 3) {
                    $records[] = $this->invalidRecord(
                        $line,
                        (string)($columns[0] ?? ''),
                        (string)($columns[1] ?? ''),
                        (string)($columns[2] ?? ''),
                        'Jumlah kolom harus 3.'
                    );
                    continue;
                }

                $macAddress = trim((string)$columns[0]);
                $nameValue = trim((string)$columns[1]);
                $address = trim((string)$columns[2]);
                $mac = self::normalizeMac($macAddress);
                $rowError = null;
                if ($mac === null) $rowError = 'MAC tidak valid.';
                elseif (mb_strlen($nameValue) > 255) $rowError = 'Nama maksimal 255 karakter.';
                elseif (mb_strlen($address) > 255) $rowError = 'Alamat maksimal 255 karakter.';
                elseif ($nameValue === '' && $address === '') $rowError = 'Nama dan alamat sama-sama kosong.';

                $records[] = [
                    'line' => $line,
                    'mac_address' => $macAddress,
                    'mac_normalized' => $mac,
                    'nama' => $nameValue,
                    'alamat' => $address,
                    'error' => $rowError,
                ];
            }
        } finally {
            fclose($handle);
        }

        if ($records === []) throw new InvalidArgumentException('File CSV tidak memiliki data ONU.');
        return $records;
    }

    
    public function analyze(int $userId, array $records): array
    {
        $fileCounts = [];
        foreach ($records as $record) {
            $mac = $record['mac_normalized'];
            if ($mac !== null) $fileCounts[$mac] = ($fileCounts[$mac] ?? 0) + 1;
        }

        $currentByMac = [];
        foreach ($this->repository->getOnus($userId, null, false, null) as $onu) {
            $mac = self::normalizeMac((string)($onu['onu_serial'] ?? ''));
            if ($mac === null) continue;
            $currentByMac[$mac][] = $onu;
        }

        $summary = [
            'total' => count($records),
            'ready' => 0,
            'unchanged' => 0,
            'not_found' => 0,
            'duplicate' => 0,
            'ambiguous' => 0,
            'invalid' => 0,
        ];
        $rows = [];
        $updates = [];

        foreach ($records as $record) {
            $status = 'invalid';
            $message = $record['error'] ?? '';
            $mac = $record['mac_normalized'];

            if ($message !== '') {
                $summary['invalid']++;
            } elseif ($mac !== null && ($fileCounts[$mac] ?? 0) > 1) {
                $status = 'duplicate';
                $message = 'MAC duplikat di dalam file.';
                $summary['duplicate']++;
            } elseif ($mac === null || !isset($currentByMac[$mac])) {
                $status = 'not_found';
                $message = 'MAC tidak ditemukan pada data ONU akun ini.';
                $summary['not_found']++;
            } elseif (count($currentByMac[$mac]) !== 1) {
                $status = 'ambiguous';
                $message = 'MAC ditemukan pada lebih dari satu ONU.';
                $summary['ambiguous']++;
            } else {
                $onu = $currentByMac[$mac][0];
                $oldName = trim((string)($onu['onu_name'] ?? ''));
                $oldAddress = trim((string)($onu['onu_location'] ?? ''));
                $newName = $record['nama'] !== '' ? $record['nama'] : $oldName;
                $newAddress = $record['alamat'] !== '' ? $record['alamat'] : $oldAddress;

                if ($newName === $oldName && $newAddress === $oldAddress) {
                    $status = 'unchanged';
                    $message = 'Data sudah sama.';
                    $summary['unchanged']++;
                } else {
                    $status = 'ready';
                    $message = 'Siap diperbarui.';
                    $summary['ready']++;
                    $updates[] = [
                        'id' => (int)$onu['id'],
                        'name' => $newName,
                        'address' => $newAddress,
                        'set_name_manual' => $record['nama'] !== '',
                    ];
                }
            }

            $rows[] = $record + ['status' => $status, 'message' => $message];
        }

        return ['summary' => $summary, 'rows' => $rows, 'updates' => $updates];
    }

    public static function normalizeMac(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[0-9A-Fa-f:.\-\s]+$/', $value) !== 1) return null;
        $normalized = strtoupper((string)preg_replace('/[:.\-\s]+/', '', $value));
        return preg_match('/^[0-9A-F]{12}$/', $normalized) === 1 ? $normalized : null;
    }

    private static function formatMac(string $normalized): string
    {
        return implode(':', str_split($normalized, 2));
    }

    
    private function invalidRecord(int $line, string $mac, string $name, string $address, string $error): array
    {
        return [
            'line' => $line,
            'mac_address' => trim($mac),
            'mac_normalized' => self::normalizeMac($mac),
            'nama' => trim($name),
            'alamat' => trim($address),
            'error' => $error,
        ];
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran file CSV terlalu besar.',
            UPLOAD_ERR_PARTIAL => 'Upload file CSV tidak selesai.',
            UPLOAD_ERR_NO_FILE => 'Pilih file CSV yang akan diimpor.',
            default => 'File CSV gagal diunggah.',
        };
    }
}
