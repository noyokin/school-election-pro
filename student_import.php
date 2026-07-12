<?php
declare(strict_types=1);

function import_clean_value(mixed $value): string
{
    $text = trim((string) $value);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

    if ($text !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
        $encoding = mb_detect_encoding($text, ['Windows-1251', 'ISO-8859-1'], true);
        if ($encoding !== false) {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }
    }

    return trim($text);
}

function import_header_key(string $value): string
{
    $value = import_clean_value($value);
    $value = mb_strtolower(str_replace('ё', 'е', $value));

    return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
}

function import_header_aliases(): array
{
    return [
        'student_code' => [
            'код', 'кодученика', 'studentcode', 'studentid', 'login', 'логин',
            'идентификатор', 'табельныйномер',
        ],
        'full_name' => [
            'фио', 'ученик', 'имя', 'fullname', 'studentname', 'name',
        ],
        'class_name' => [
            'класс', 'classname', 'class', 'группа',
        ],
        'password' => [
            'пароль', 'password', 'pass', 'пин', 'pin',
        ],
    ];
}

function parse_student_import_file(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Файл превышает ограничение upload_max_filesize в PHP.',
            UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер формы.',
            UPLOAD_ERR_PARTIAL => 'Файл был загружен не полностью.',
            UPLOAD_ERR_NO_FILE => 'Выберите файл с учениками.',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере отсутствует временная папка.',
            UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать загруженный файл.',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением PHP.',
        ];

        throw new RuntimeException($messages[$error] ?? 'Неизвестная ошибка загрузки файла.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $size = (int) ($file['size'] ?? 0);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Загруженный файл не прошёл проверку безопасности.');
    }

    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Размер файла должен быть от 1 байта до 10 МБ.');
    }

    $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $rows = match ($extension) {
        'csv' => parse_csv_rows($tmpPath),
        'xlsx' => parse_xlsx_rows($tmpPath),
        default => throw new RuntimeException('Поддерживаются только файлы XLSX и CSV.'),
    };

    return map_student_import_rows($rows);
}

function parse_csv_rows(string $path): array
{
    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть CSV-файл.');
    }

    try {
        $sample = (string) fgets($handle);
        rewind($handle);

        $counts = [
            ';' => substr_count($sample, ';'),
            ',' => substr_count($sample, ','),
            "\t" => substr_count($sample, "\t"),
        ];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        if (($counts[$delimiter] ?? 0) === 0) {
            $delimiter = ';';
        }

        $rows = [];
        $rowLimit = 10000;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = array_map('import_clean_value', $row);

            if (count($rows) > $rowLimit) {
                throw new RuntimeException("В одном файле допускается не более $rowLimit строк.");
            }
        }

        return $rows;
    } finally {
        fclose($handle);
    }
}

function parse_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException(
            'Для импорта XLSX требуется расширение PHP ZipArchive. В XAMPP включите extension=zip или используйте CSV.'
        );
    }

    if (!class_exists('DOMDocument')) {
        throw new RuntimeException(
            'Для импорта XLSX требуется расширение PHP DOM/XML. Используйте CSV или включите расширение DOM.'
        );
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($path);

    if ($openResult !== true) {
        throw new RuntimeException('Не удалось открыть XLSX-файл как ZIP-архив.');
    }

    try {
        $sharedStrings = xlsx_shared_strings($zip);
        $sheetPath = xlsx_first_sheet_path($zip);
        $sheetXml = $zip->getFromName($sheetPath);

        if ($sheetXml === false) {
            throw new RuntimeException('В XLSX-файле не найден первый лист.');
        }

        $dom = new DOMDocument();
        if (!@$dom->loadXML($sheetXml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Не удалось прочитать XML первого листа XLSX.');
        }

        $xpath = new DOMXPath($dom);
        $rowNodes = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
        $rows = [];
        $rowLimit = 10000;

        if ($rowNodes === false) {
            return [];
        }

        foreach ($rowNodes as $rowNode) {
            $row = [];
            $maxIndex = -1;
            $cellNodes = $xpath->query('./*[local-name()="c"]', $rowNode);

            if ($cellNodes === false) {
                continue;
            }

            foreach ($cellNodes as $cellNode) {
                if (!$cellNode instanceof DOMElement) {
                    continue;
                }

                $reference = $cellNode->getAttribute('r');
                $columnIndex = xlsx_column_index($reference);

                if ($columnIndex < 0 || $columnIndex > 49) {
                    continue;
                }

                $row[$columnIndex] = xlsx_cell_value($xpath, $cellNode, $sharedStrings);
                $maxIndex = max($maxIndex, $columnIndex);
            }

            if ($maxIndex >= 0) {
                $normalized = [];
                for ($index = 0; $index <= $maxIndex; $index++) {
                    $normalized[] = import_clean_value($row[$index] ?? '');
                }
                $rows[] = $normalized;
            }

            if (count($rows) > $rowLimit) {
                throw new RuntimeException("В одном файле допускается не более $rowLimit строк.");
            }
        }

        return $rows;
    } finally {
        $zip->close();
    }
}

function xlsx_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');

    if ($xml === false) {
        return [];
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
        return [];
    }

    $xpath = new DOMXPath($dom);
    $items = $xpath->query('//*[local-name()="si"]');
    $strings = [];

    if ($items === false) {
        return [];
    }

    foreach ($items as $item) {
        $text = '';
        $textNodes = $xpath->query('.//*[local-name()="t"]', $item);

        if ($textNodes !== false) {
            foreach ($textNodes as $textNode) {
                $text .= $textNode->textContent;
            }
        }

        $strings[] = $text;
    }

    return $strings;
}

function xlsx_first_sheet_path(ZipArchive $zip): string
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relationsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($workbookXml === false || $relationsXml === false) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbook = new DOMDocument();
    $relations = new DOMDocument();

    if (!@$workbook->loadXML($workbookXml, LIBXML_NONET | LIBXML_COMPACT)
        || !@$relations->loadXML($relationsXml, LIBXML_NONET | LIBXML_COMPACT)) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbookXpath = new DOMXPath($workbook);
    $sheet = $workbookXpath->query('//*[local-name()="sheets"]/*[local-name()="sheet"][1]')?->item(0);

    if (!$sheet instanceof DOMElement) {
        return 'xl/worksheets/sheet1.xml';
    }

    $relationshipId = $sheet->getAttributeNS(
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'id'
    );

    if ($relationshipId === '') {
        return 'xl/worksheets/sheet1.xml';
    }

    $relationsXpath = new DOMXPath($relations);
    $relationNodes = $relationsXpath->query('//*[local-name()="Relationship"]');

    if ($relationNodes !== false) {
        foreach ($relationNodes as $relation) {
            if ($relation instanceof DOMElement && $relation->getAttribute('Id') === $relationshipId) {
                return xlsx_normalize_path($relation->getAttribute('Target'));
            }
        }
    }

    return 'xl/worksheets/sheet1.xml';
}

function xlsx_normalize_path(string $target): string
{
    $target = str_replace('\\', '/', rawurldecode($target));
    $target = ltrim($target, '/');

    if (!str_starts_with($target, 'xl/')) {
        $target = 'xl/' . $target;
    }

    $parts = [];
    foreach (explode('/', $target) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

function xlsx_column_index(string $reference): int
{
    if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
        return -1;
    }

    $letters = strtoupper($matches[1]);
    $index = 0;

    for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function xlsx_cell_value(DOMXPath $xpath, DOMElement $cell, array $sharedStrings): string
{
    $type = $cell->getAttribute('t');

    if ($type === 'inlineStr') {
        $text = '';
        $nodes = $xpath->query('.//*[local-name()="is"]//*[local-name()="t"]', $cell);
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $text .= $node->textContent;
            }
        }
        return $text;
    }

    $valueNode = $xpath->query('./*[local-name()="v"]', $cell)?->item(0);
    $value = $valueNode?->textContent ?? '';

    if ($type === 's') {
        return $sharedStrings[(int) $value] ?? '';
    }

    if ($type === 'b') {
        return $value === '1' ? '1' : '0';
    }

    return $value;
}

function map_student_import_rows(array $rows): array
{
    $rows = array_values(array_filter($rows, static function (array $row): bool {
        foreach ($row as $value) {
            if (import_clean_value($value) !== '') {
                return true;
            }
        }
        return false;
    }));

    if ($rows === []) {
        throw new RuntimeException('Таблица не содержит данных.');
    }

    $aliases = import_header_aliases();
    $header = array_map(
        static fn(mixed $value): string => import_header_key((string) $value),
        $rows[0]
    );
    $mapping = [];

    foreach ($header as $columnIndex => $headerValue) {
        foreach ($aliases as $field => $fieldAliases) {
            if (in_array($headerValue, $fieldAliases, true)) {
                $mapping[$field] = $columnIndex;
            }
        }
    }

    $hasHeader = count($mapping) > 0;

    if ($hasHeader && count($mapping) < 4) {
        $missingLabels = [
            'student_code' => 'код ученика',
            'full_name' => 'ФИО',
            'class_name' => 'класс',
            'password' => 'пароль',
        ];
        $missing = [];
        foreach ($missingLabels as $field => $label) {
            if (!array_key_exists($field, $mapping)) {
                $missing[] = $label;
            }
        }

        throw new RuntimeException('В заголовке таблицы отсутствуют столбцы: ' . implode(', ', $missing) . '.');
    }

    if (!$hasHeader) {
        $mapping = [
            'student_code' => 0,
            'full_name' => 1,
            'class_name' => 2,
            'password' => 3,
        ];
    }

    $records = [];
    $startIndex = $hasHeader ? 1 : 0;

    for ($rowIndex = $startIndex, $count = count($rows); $rowIndex < $count; $rowIndex++) {
        $row = $rows[$rowIndex];
        $records[] = [
            'source_row' => $rowIndex + 1,
            'student_code' => import_clean_value($row[$mapping['student_code']] ?? ''),
            'full_name' => import_clean_value($row[$mapping['full_name']] ?? ''),
            'class_name' => import_clean_value($row[$mapping['class_name']] ?? ''),
            'password' => import_clean_value($row[$mapping['password']] ?? ''),
        ];
    }

    return $records;
}

function import_student_records(PDO $pdo, array $records, bool $updateExisting): array
{
    $find = $pdo->prepare('SELECT id FROM students WHERE student_code = ?');
    $insert = $pdo->prepare(
        'INSERT INTO students (student_code, full_name, class_name, password_hash)
         VALUES (?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE students
         SET full_name = ?, class_name = ?, password_hash = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );

    $result = [
        'added' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    $pdo->beginTransaction();

    try {
        foreach ($records as $record) {
            $rowNumber = (int) ($record['source_row'] ?? 0);
            $code = strtoupper(trim((string) ($record['student_code'] ?? '')));
            $name = trim((string) ($record['full_name'] ?? ''));
            $className = trim((string) ($record['class_name'] ?? ''));
            $password = (string) ($record['password'] ?? '');

            if ($code === '' || $name === '' || $className === '' || mb_strlen($password) < 4) {
                $result['skipped']++;
                if (count($result['errors']) < 8) {
                    $result['errors'][] = "Строка $rowNumber: заполнены не все поля или пароль короче 4 символов.";
                }
                continue;
            }

            $find->execute([$code]);
            $existingId = $find->fetchColumn();

            if ($existingId !== false) {
                if (!$updateExisting) {
                    $result['skipped']++;
                    continue;
                }

                $update->execute([
                    $name,
                    $className,
                    student_password_hash($password),
                    (int) $existingId,
                ]);
                $result['updated']++;
                continue;
            }

            $insert->execute([
                $code,
                $name,
                $className,
                student_password_hash($password),
            ]);
            $result['added']++;
        }

        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Persistent import jobs keep large previews out of PHP sessions and allow
 * password hashing to be split across multiple short HTTP requests.
 */
function student_import_jobs_directory(): string
{
    $directory = dirname(DB_PATH) . '/import-jobs';
    prepare_writable_directory($directory, 'Папка пакетного импорта');

    $htaccess = $directory . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX);
    }

    return $directory;
}

function student_import_job_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new RuntimeException('Некорректный идентификатор импорта.');
    }

    return student_import_jobs_directory() . '/' . $token . '.json';
}

function student_import_records_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new RuntimeException('Некорректный идентификатор импорта.');
    }

    return student_import_jobs_directory() . '/' . $token . '.ndjson';
}

function student_import_save_job(string $token, array $job): void
{
    $path = student_import_job_path($token);
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temporary = $path . '.tmp';

    if (@file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось сохранить состояние импорта. Проверьте права папки data/import-jobs.');
    }

    @chmod($path, 0660);
}

function student_import_write_records(string $token, array $records): void
{
    $path = student_import_records_path($token);
    $temporary = $path . '.tmp';
    $handle = @fopen($temporary, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось создать поток данных импорта.');
    }

    try {
        foreach ($records as $record) {
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $line . "\n") === false) {
                throw new RuntimeException('Не удалось записать поток данных импорта.');
            }
        }
    } finally {
        fclose($handle);
    }

    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось активировать поток данных импорта.');
    }
    @chmod($path, 0660);
}

function student_import_offset_for_cursor(string $recordsPath, int $cursor): int
{
    if ($cursor <= 0) {
        return 0;
    }
    $handle = @fopen($recordsPath, 'rb');
    if ($handle === false) {
        return 0;
    }
    try {
        for ($index = 0; $index < $cursor && fgets($handle) !== false; $index++) {
            // Advance to the stored cursor only during one-time v1 conversion.
        }
        return max(0, (int) ftell($handle));
    } finally {
        fclose($handle);
    }
}

function student_import_load_job(string $token): array
{
    $path = student_import_job_path($token);
    if (!is_file($path)) {
        throw new RuntimeException('Задание импорта не найдено или уже завершено.');
    }

    $content = @file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Не удалось прочитать задание импорта.');
    }

    $job = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job)) {
        throw new RuntimeException('Файл задания импорта повреждён.');
    }

    // Convert unfinished 4.0.7 jobs once. Afterwards only the small metadata
    // JSON is loaded, while records stay in a sequential NDJSON stream.
    if (isset($job['records']) && is_array($job['records'])) {
        $records = $job['records'];
        student_import_write_records($token, $records);
        $job['version'] = 2;
        $job['total'] = count($records);
        $job['preview_records'] = array_slice($records, 0, 100);
        $job['byte_offset'] = student_import_offset_for_cursor(
            student_import_records_path($token),
            max(0, (int) ($job['cursor'] ?? 0))
        );
        unset($job['records']);
        student_import_save_job($token, $job);
    }

    if ((int) ($job['version'] ?? 0) < 2 || !is_file(student_import_records_path($token))) {
        throw new RuntimeException('Поток данных импорта отсутствует или повреждён.');
    }

    return $job;
}

function student_import_delete_job(string $token): void
{
    try {
        foreach ([student_import_job_path($token), student_import_records_path($token)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    } catch (Throwable) {
        // Cleanup must not hide the original result.
    }
}

function student_import_existing_codes(PDO $pdo, array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map(
        static fn(mixed $code): string => strtoupper(trim((string) $code)),
        $codes
    ))));

    $existing = [];
    foreach (array_chunk($codes, 400) as $chunk) {
        if ($chunk === []) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $statement = $pdo->prepare("SELECT student_code FROM students WHERE student_code IN ($placeholders)");
        $statement->execute($chunk);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $existing[strtoupper((string) $code)] = true;
        }
    }

    return $existing;
}

function student_import_validate_records(PDO $pdo, array $records): array
{
    $existingCodes = student_import_existing_codes(
        $pdo,
        array_map(static fn(array $record): string => (string) ($record['student_code'] ?? ''), $records)
    );

    $seen = [];
    $valid = 0;
    $invalid = 0;
    $prepared = [];

    foreach ($records as $record) {
        $errors = [];
        $code = strtoupper(trim((string) ($record['student_code'] ?? '')));
        $name = trim((string) ($record['full_name'] ?? ''));
        $className = trim((string) ($record['class_name'] ?? ''));
        $password = (string) ($record['password'] ?? '');

        if ($code === '' || $name === '' || $className === '') {
            $errors[] = 'пустые обязательные поля';
        }
        if (mb_strlen($password) < 4) {
            $errors[] = 'пароль короче 4 символов';
        }
        if ($code !== '' && isset($seen[$code])) {
            $errors[] = 'дубликат кода в файле';
        }
        if ($code !== '') {
            $seen[$code] = true;
        }

        $record['student_code'] = $code;
        $record['full_name'] = $name;
        $record['class_name'] = $className;
        $record['password'] = $password;
        $record['exists'] = isset($existingCodes[$code]);
        $record['errors'] = $errors;

        if ($errors === []) {
            $valid++;
        } else {
            $invalid++;
        }
        $prepared[] = $record;
    }

    return ['records' => $prepared, 'valid' => $valid, 'invalid' => $invalid];
}

function student_import_create_job(PDO $pdo, array $records): array
{
    $validated = student_import_validate_records($pdo, $records);
    $token = bin2hex(random_bytes(16));
    student_import_write_records($token, $validated['records']);

    $job = [
        'version' => 2,
        'created_at' => time(),
        'status' => 'preview',
        'cursor' => 0,
        'byte_offset' => 0,
        'total' => count($validated['records']),
        'valid' => $validated['valid'],
        'invalid' => $validated['invalid'],
        'update_existing' => false,
        'preview_records' => array_slice($validated['records'], 0, 100),
        'result' => [
            'added' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ],
    ];
    student_import_save_job($token, $job);

    return ['token' => $token] + $job;
}

function student_import_start_job(string $token, bool $updateExisting): array
{
    $job = student_import_load_job($token);
    if ((int) ($job['created_at'] ?? 0) < time() - 7200) {
        student_import_delete_job($token);
        throw new RuntimeException('Предпросмотр устарел. Загрузите файл повторно.');
    }

    $job['status'] = 'running';
    $job['update_existing'] = $updateExisting;
    $job['cursor'] = max(0, (int) ($job['cursor'] ?? 0));
    $job['byte_offset'] = max(0, (int) ($job['byte_offset'] ?? 0));
    student_import_save_job($token, $job);

    return $job;
}

function student_import_error_records(string $token): array
{
    $job = student_import_load_job($token);
    $handle = @fopen(student_import_records_path($token), 'rb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось прочитать поток импорта.');
    }

    $errors = [];
    try {
        while (($line = fgets($handle)) !== false) {
            $record = json_decode(trim($line), true);
            if (is_array($record) && !empty($record['errors'])) {
                $errors[] = $record;
            }
        }
    } finally {
        fclose($handle);
    }

    return $errors;
}

function student_import_process_job_batch(PDO $pdo, string $token, int $batchSize = 12): array
{
    $batchSize = max(5, min(25, $batchSize));
    $job = student_import_load_job($token);
    if (($job['status'] ?? '') !== 'running') {
        throw new RuntimeException('Импорт не запущен или уже завершён.');
    }

    $total = max(0, (int) ($job['total'] ?? 0));
    $cursor = max(0, (int) ($job['cursor'] ?? 0));
    $result = is_array($job['result'] ?? null) ? $job['result'] : [
        'added' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    $handle = @fopen(student_import_records_path($token), 'rb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть поток пакетного импорта.');
    }
    if (fseek($handle, max(0, (int) ($job['byte_offset'] ?? 0))) !== 0) {
        fclose($handle);
        throw new RuntimeException('Не удалось продолжить импорт с сохранённой позиции.');
    }

    $find = $pdo->prepare('SELECT id FROM students WHERE student_code = ?');
    $insert = $pdo->prepare(
        'INSERT INTO students (student_code, full_name, class_name, password_hash) VALUES (?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE students SET full_name = ?, class_name = ?, password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );

    $processedThisRequest = 0;
    $pdo->beginTransaction();

    try {
        while ($cursor < $total && $processedThisRequest < $batchSize && ($line = fgets($handle)) !== false) {
            $record = json_decode(trim($line), true);
            $cursor++;
            $processedThisRequest++;

            if (!is_array($record) || !empty($record['errors'])) {
                $result['skipped']++;
                continue;
            }

            $code = strtoupper(trim((string) ($record['student_code'] ?? '')));
            $name = trim((string) ($record['full_name'] ?? ''));
            $className = trim((string) ($record['class_name'] ?? ''));
            $password = (string) ($record['password'] ?? '');

            try {
                $find->execute([$code]);
                $existingId = $find->fetchColumn();

                if ($existingId !== false) {
                    if (empty($job['update_existing'])) {
                        $result['skipped']++;
                        continue;
                    }
                    $update->execute([$name, $className, student_password_hash($password), (int) $existingId]);
                    $result['updated']++;
                    continue;
                }

                $insert->execute([$code, $name, $className, student_password_hash($password)]);
                $result['added']++;
            } catch (Throwable $rowException) {
                $result['skipped']++;
                if (count($result['errors']) < 20) {
                    $row = (int) ($record['source_row'] ?? $cursor);
                    $result['errors'][] = "Строка $row: " . database_error_message($rowException);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($handle);
        throw $exception;
    }

    $byteOffset = max(0, (int) ftell($handle));
    fclose($handle);

    $job['cursor'] = $cursor;
    $job['byte_offset'] = $byteOffset;
    $job['result'] = $result;
    $done = $cursor >= $total;
    $job['status'] = $done ? 'done' : 'running';
    student_import_save_job($token, $job);

    if ($done) {
        try {
            $pdo->exec('PRAGMA optimize');
        } catch (Throwable) {
            // Optimization is best-effort only.
        }
    }

    return [
        'done' => $done,
        'cursor' => $cursor,
        'total' => $total,
        'percent' => $total > 0 ? min(100, round(($cursor / $total) * 100, 1)) : 100,
        'result' => $result,
    ];
}
