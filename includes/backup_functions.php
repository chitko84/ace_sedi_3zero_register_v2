<?php

function backup_project_root(): string
{
    return dirname(__DIR__);
}

function backup_root(): string
{
    return backup_project_root() . DIRECTORY_SEPARATOR . 'backups';
}

function backup_timestamp(): string
{
    return date('Y-m-d_H-i-s');
}

function backup_public_name(string $path): string
{
    $root = realpath(backup_root());
    $real = realpath($path);

    if ($root === false || $real === false || strpos($real, $root) !== 0) {
        return basename($path);
    }

    return trim(str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root))), '/');
}

function backup_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float)$bytes;
    $idx = 0;

    while ($size >= 1024 && $idx < count($units) - 1) {
        $size /= 1024;
        $idx++;
    }

    return number_format($size, $idx === 0 ? 0 : 2) . ' ' . $units[$idx];
}

function backup_ensure_directories(): void
{
    $dirs = [
        backup_root(),
        backup_root() . DIRECTORY_SEPARATOR . 'database',
        backup_root() . DIRECTORY_SEPARATOR . 'files',
        backup_root() . DIRECTORY_SEPARATOR . 'csv',
        backup_root() . DIRECTORY_SEPARATOR . 'full',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("Could not create backup directory: {$dir}");
        }
    }

    $htaccess = backup_root() . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
}

function backup_safe_filename(string $file): string
{
    return preg_replace('/[^A-Za-z0-9._\/-]/', '', str_replace('\\', '/', $file));
}

function backup_resolve_file(string $relative): string
{
    backup_ensure_directories();

    $relative = backup_safe_filename($relative);
    $root = realpath(backup_root());
    $path = realpath(backup_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));

    if (
        $root === false ||
        $path === false ||
        strpos($path, $root) !== 0 ||
        !is_file($path) ||
        basename($path) === '.htaccess'
    ) {
        throw new RuntimeException('Invalid backup file.');
    }

    return $path;
}

function backup_download_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Backup file is not readable.');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}

function backup_mysql_literal(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . $conn->real_escape_string((string)$value) . "'";
}

function backup_can_exec(): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

function backup_find_mysqldump(): ?string
{
    $candidates = ['mysqldump'];

    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    }

    foreach ($candidates as $candidate) {
        if ($candidate !== 'mysqldump' && !is_file($candidate)) {
            continue;
        }

        $cmd = escapeshellarg($candidate) . ' --version';
        $output = [];
        $code = 1;
        @exec($cmd, $output, $code);

        if ($code === 0) {
            return $candidate;
        }
    }

    return null;
}

function backup_try_mysqldump(array $config, string $outputFile): bool
{
    if (!backup_can_exec()) {
        return false;
    }

    $bin = backup_find_mysqldump();
    if ($bin === null) {
        return false;
    }

    $cmd = escapeshellarg($bin)
        . ' --host=' . escapeshellarg($config['host'])
        . ' --user=' . escapeshellarg($config['user'])
        . ($config['password'] !== '' ? ' --password=' . escapeshellarg($config['password']) : '')
        . ' --single-transaction --routines --triggers --events --default-character-set=utf8mb4 '
        . escapeshellarg($config['database'])
        . ' --result-file=' . escapeshellarg($outputFile);

    $output = [];
    $code = 1;
    @exec($cmd, $output, $code);

    return $code === 0 && is_file($outputFile) && filesize($outputFile) > 0;
}

function backup_export_database_with_php(mysqli $conn, string $outputFile): void
{
    $fh = fopen($outputFile, 'wb');
    if (!$fh) {
        throw new RuntimeException('Could not write database backup file.');
    }

    fwrite($fh, "-- 3ZERO Club Registration System database backup\n");
    fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($fh, "SET NAMES utf8mb4;\n\n");

    $tables = [];
    $res = $conn->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
    while ($row = $res->fetch_array(MYSQLI_NUM)) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $safeTable = str_replace('`', '``', $table);
        fwrite($fh, "\n-- Table structure for `{$safeTable}`\n");
        fwrite($fh, "DROP TABLE IF EXISTS `{$safeTable}`;\n");

        $create = $conn->query("SHOW CREATE TABLE `{$safeTable}`")->fetch_assoc();
        fwrite($fh, ($create['Create Table'] ?? '') . ";\n\n");

        $data = $conn->query("SELECT * FROM `{$safeTable}`");
        if ($data->num_rows === 0) {
            continue;
        }

        fwrite($fh, "-- Data for `{$safeTable}`\n");
        while ($row = $data->fetch_assoc()) {
            $columns = array_map(fn($col) => '`' . str_replace('`', '``', $col) . '`', array_keys($row));
            $values = array_map(fn($value) => backup_mysql_literal($conn, $value), array_values($row));
            fwrite($fh, "INSERT INTO `{$safeTable}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
        }
        fwrite($fh, "\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
}

function backup_create_database(mysqli $conn, array $config): string
{
    backup_ensure_directories();

    $file = backup_root() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database_backup_' . backup_timestamp() . '.sql';

    if (!backup_try_mysqldump($config, $file)) {
        backup_export_database_with_php($conn, $file);
    }

    return $file;
}

function backup_table_columns(mysqli $conn, string $table, array $preferred): array
{
    $existing = [];
    $res = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    while ($row = $res->fetch_assoc()) {
        $existing[] = $row['Field'];
    }

    return array_values(array_intersect($preferred, $existing));
}

function backup_csv_columns(mysqli $conn, string $table): array
{
    $columns = [
        'users' => ['id', 'name', 'email', 'phone_number', 'date_of_birth', 'role', 'department', 'program_of_study', 'intake', 'country', 'gender', 'area_of_interest', 'expected_graduation_year', 'created_at'],
        'clubs' => ['id', 'club_identifier', 'group_name', 'cluster', 'focus_area', 'cluster_advisor', 'key_person_name', 'key_person_student_id', 'deputy_key_person_name', 'deputy_key_person_student_id', 'date_of_registration', 'status', 'created_at', 'updated_at'],
        'club_members' => ['id', 'club_id', 'full_name', 'student_id', 'programme', 'nationality', 'phone', 'email', 'school_centre', 'intake_month_year', 'expected_graduation_year', 'current_semester', 'member_type', 'created_at'],
    ];

    if (!isset($columns[$table])) {
        throw new RuntimeException('Invalid CSV table.');
    }

    return backup_table_columns($conn, $table, $columns[$table]);
}

function backup_csv_filename(string $table): string
{
    $prefix = [
        'users' => 'users_export_',
        'clubs' => 'clubs_export_',
        'club_members' => 'club_members_export_',
    ][$table] ?? 'export_';

    return $prefix . backup_timestamp() . '.csv';
}

function backup_write_csv(mysqli $conn, string $table, string $targetFile): void
{
    $columns = backup_csv_columns($conn, $table);
    if (!$columns) {
        throw new RuntimeException('No exportable columns found.');
    }

    $safeTable = str_replace('`', '``', $table);
    $select = implode(', ', array_map(fn($col) => '`' . str_replace('`', '``', $col) . '`', $columns));
    $result = $conn->query("SELECT {$select} FROM `{$safeTable}` ORDER BY 1 ASC");

    $out = fopen($targetFile, 'wb');
    if (!$out) {
        throw new RuntimeException('Could not write CSV file.');
    }

    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $columns);

    while ($row = $result->fetch_assoc()) {
        fputcsv($out, array_map(fn($col) => $row[$col] ?? '', $columns));
    }

    fclose($out);
}

function backup_stream_csv(mysqli $conn, string $table): void
{
    $filename = backup_csv_filename($table);
    $path = tempnam(sys_get_temp_dir(), 'zero_csv_');
    if ($path === false) {
        throw new RuntimeException('Could not create temporary CSV file.');
    }

    backup_write_csv($conn, $table, $path);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($path);
    @unlink($path);
}

function backup_create_csv_file(mysqli $conn, string $table): string
{
    backup_ensure_directories();

    $path = backup_root() . DIRECTORY_SEPARATOR . 'csv' . DIRECTORY_SEPARATOR . backup_csv_filename($table);
    backup_write_csv($conn, $table, $path);

    return $path;
}

function backup_require_zip(): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is not enabled. Enable the PHP zip extension to create ZIP backups.');
    }
}

function backup_should_skip_path(string $path): bool
{
    $name = basename($path);
    $lower = strtolower($name);

    if (in_array($name, ['.git', 'backups', 'cache', 'tmp', 'temp'], true)) {
        return true;
    }

    if (in_array($lower, ['error_log', 'debug.log'], true)) {
        return true;
    }

    return preg_match('/\.(tmp|temp|log)$/i', $name) === 1;
}

function backup_add_path_to_zip(ZipArchive $zip, string $path, string $base): void
{
    if (backup_should_skip_path($path)) {
        return;
    }

    if (is_file($path)) {
        $relative = trim(str_replace('\\', '/', substr($path, strlen($base))), '/');
        $zip->addFile($path, $relative);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        $parts = explode(DIRECTORY_SEPARATOR, substr($itemPath, strlen($base)));
        if (array_filter($parts, fn($part) => backup_should_skip_path($part))) {
            continue;
        }

        $relative = trim(str_replace('\\', '/', substr($itemPath, strlen($base))), '/');
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } elseif ($item->isFile()) {
            $zip->addFile($itemPath, $relative);
        }
    }
}

function backup_create_project_files_zip(): string
{
    backup_require_zip();
    backup_ensure_directories();

    $root = backup_project_root();
    $file = backup_root() . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'project_files_backup_' . backup_timestamp() . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create project files ZIP.');
    }

    $include = ['admin', 'user', 'includes', 'uploads', 'assets', 'PHPMailer'];
    foreach ($include as $item) {
        $path = $root . DIRECTORY_SEPARATOR . $item;
        if (file_exists($path)) {
            backup_add_path_to_zip($zip, $path, $root);
        }
    }

    foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $item) {
        if (is_file($item) && !backup_should_skip_path($item)) {
            backup_add_path_to_zip($zip, $item, $root);
        }
    }

    $zip->close();
    return $file;
}

function backup_create_full_zip(mysqli $conn, array $config): string
{
    backup_require_zip();
    backup_ensure_directories();

    $db = backup_create_database($conn, $config);
    $files = backup_create_project_files_zip();
    $csvs = [
        backup_create_csv_file($conn, 'users'),
        backup_create_csv_file($conn, 'clubs'),
        backup_create_csv_file($conn, 'club_members'),
    ];

    $full = backup_root() . DIRECTORY_SEPARATOR . 'full' . DIRECTORY_SEPARATOR . 'full_system_backup_' . backup_timestamp() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($full, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create full backup ZIP.');
    }

    $zip->addFile($db, 'database/' . basename($db));
    $zip->addFile($files, 'files/' . basename($files));
    foreach ($csvs as $csv) {
        $zip->addFile($csv, 'csv/' . basename($csv));
    }
    $zip->close();

    return $full;
}

function backup_list_files(): array
{
    backup_ensure_directories();

    $files = [];
    $root = backup_root();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->getFilename() === '.htaccess') {
            continue;
        }

        $path = $item->getPathname();
        $files[] = [
            'relative' => backup_public_name($path),
            'name' => $item->getFilename(),
            'size' => $item->getSize(),
            'created' => $item->getMTime(),
            'type' => basename(dirname($path)),
        ];
    }

    usort($files, fn($a, $b) => $b['created'] <=> $a['created']);
    return $files;
}

function backup_delete_file(string $relative): void
{
    $path = backup_resolve_file($relative);
    if (!unlink($path)) {
        throw new RuntimeException('Could not delete backup file.');
    }
}

function backup_delete_files(array $relativeFiles): array
{
    $deleted = 0;
    $failed = [];

    foreach ($relativeFiles as $relative) {
        $relative = (string)$relative;
        if ($relative === '') {
            continue;
        }

        try {
            backup_delete_file($relative);
            $deleted++;
        } catch (Throwable $e) {
            $failed[] = [
                'file' => $relative,
                'error' => $e->getMessage(),
            ];
        }
    }

    return [
        'deleted' => $deleted,
        'failed' => $failed,
    ];
}

function backup_cleanup_old(int $days = 30): int
{
    backup_ensure_directories();

    $cutoff = time() - ($days * 86400);
    $deleted = 0;

    foreach (backup_list_files() as $file) {
        if ($file['created'] < $cutoff) {
            backup_delete_file($file['relative']);
            $deleted++;
        }
    }

    return $deleted;
}
