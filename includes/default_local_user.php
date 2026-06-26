<?php

function default_local_user_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();

    return $stmt->get_result()->num_rows > 0;
}

function default_local_user_columns(mysqli $conn, string $table): array
{
    $stmt = $conn->prepare("
        SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, EXTRA
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();

    $columns = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = $row;
    }

    return $columns;
}

function default_local_user_pick_enum_value(string $columnType, array $preferred, string $fallback): string
{
    if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $columnType, $matches)) {
        return $fallback;
    }

    $values = array_map('stripslashes', $matches[1]);
    foreach ($preferred as $candidate) {
        foreach ($values as $value) {
            if (strcasecmp($value, $candidate) === 0) {
                return $value;
            }
        }
    }

    return $values[0] ?? $fallback;
}

function default_local_user_value_for_column(string $column, array $meta, string $email, string $passwordHash)
{
    $dataType = strtolower($meta['DATA_TYPE'] ?? '');
    $columnType = (string)($meta['COLUMN_TYPE'] ?? '');

    if ($column === 'name' || $column === 'full_name' || $column === 'username') {
        return '3Zero Local User';
    }

    if ($column === 'email') {
        return $email;
    }

    if ($column === 'password' || $column === 'password_hash') {
        return $passwordHash;
    }

    if ($column === 'role' || $column === 'user_type' || $column === 'type') {
        return $dataType === 'enum'
            ? default_local_user_pick_enum_value($columnType, ['user', 'member'], 'user')
            : 'user';
    }

    if ($column === 'status') {
        return $dataType === 'enum'
            ? default_local_user_pick_enum_value($columnType, ['active', 'approved', 'enabled', '1'], 'active')
            : 'active';
    }

    if ($column === 'created_at' || $column === 'updated_at') {
        return date('Y-m-d H:i:s');
    }

    if ($column === 'date_of_birth') {
        return '2000-01-01';
    }

    if ($column === 'phone_number' || $column === 'phone') {
        return '0000000001';
    }

    if ($column === 'department') {
        return 'General';
    }

    if ($column === 'program_of_study') {
        return 'General';
    }

    if ($column === 'intake') {
        return 'Local';
    }

    if ($column === 'country') {
        return 'Malaysia';
    }

    if ($column === 'gender') {
        return $dataType === 'enum'
            ? default_local_user_pick_enum_value($columnType, ['Other', 'Male', 'Female'], 'Other')
            : 'Other';
    }

    if ($column === 'area_of_interest') {
        return '3Zero Club';
    }

    if ($column === 'expected_graduation_year') {
        return '2029';
    }

    if ($column === 'profile_pic') {
        return '';
    }

    if ($dataType === 'enum') {
        return default_local_user_pick_enum_value($columnType, [], '');
    }

    if (in_array($dataType, ['int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'float', 'double'], true)) {
        return 0;
    }

    if ($dataType === 'date') {
        return date('Y-m-d');
    }

    if (in_array($dataType, ['datetime', 'timestamp'], true)) {
        return date('Y-m-d H:i:s');
    }

    return '';
}

function ensure_default_local_user(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $table = 'users';
    $email = 'user@3zero.local';
    $plainPassword = 'User@12345';

    if (!default_local_user_table_exists($conn, $table)) {
        return;
    }

    $columns = default_local_user_columns($conn, $table);
    if (!isset($columns['email']) || (!isset($columns['password']) && !isset($columns['password_hash']))) {
        return;
    }

    $stmt = $conn->prepare("SELECT 1 FROM `{$table}` WHERE `email` = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return;
    }

    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $insertColumns = [];
    $values = [];
    $types = '';

    foreach ($columns as $column => $meta) {
        $extra = strtolower((string)($meta['EXTRA'] ?? ''));
        $hasDefault = array_key_exists('COLUMN_DEFAULT', $meta) && $meta['COLUMN_DEFAULT'] !== null;
        $isNullable = strcasecmp((string)($meta['IS_NULLABLE'] ?? ''), 'YES') === 0;

        if (strpos($extra, 'auto_increment') !== false) {
            continue;
        }

        $knownLocalUserField = in_array($column, [
            'name',
            'full_name',
            'username',
            'email',
            'password',
            'password_hash',
            'role',
            'user_type',
            'type',
            'status',
            'created_at',
            'updated_at',
            'date_of_birth',
            'phone_number',
            'phone',
            'department',
            'program_of_study',
            'intake',
            'country',
            'gender',
            'area_of_interest',
            'expected_graduation_year',
            'profile_pic',
        ], true);

        if (!$knownLocalUserField && ($isNullable || $hasDefault)) {
            continue;
        }

        $insertColumns[] = "`{$column}`";
        $values[] = default_local_user_value_for_column($column, $meta, $email, $passwordHash);
        $types .= 's';
    }

    if (!in_array('`email`', $insertColumns, true)) {
        $insertColumns[] = '`email`';
        $values[] = $email;
        $types .= 's';
    }

    $passwordColumn = isset($columns['password']) ? '`password`' : '`password_hash`';
    if (!in_array($passwordColumn, $insertColumns, true)) {
        $insertColumns[] = $passwordColumn;
        $values[] = $passwordHash;
        $types .= 's';
    }

    $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
    $sql = "INSERT INTO `{$table}` (" . implode(', ', $insertColumns) . ") VALUES ({$placeholders})";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
}
