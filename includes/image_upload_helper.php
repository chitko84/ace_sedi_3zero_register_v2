<?php
if (!defined('IMAGE_UPLOAD_MAX_BYTES')) {
    define('IMAGE_UPLOAD_MAX_BYTES', 1024 * 1024);
}

if (!defined('IMAGE_UPLOAD_SIZE_ERROR')) {
    define('IMAGE_UPLOAD_SIZE_ERROR', 'Image size must be less than or equal to 1MB. Please compress the image and upload again.');
}

if (!defined('IMAGE_UPLOAD_DISCLAIMER')) {
    define('IMAGE_UPLOAD_DISCLAIMER', 'Please upload an image less than or equal to 1MB. If your image is larger, please compress it before uploading.');
}

function image_upload_allowed_mimes(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
}

function image_upload_allowed_extensions(): array {
    return ['jpg', 'jpeg', 'png', 'webp'];
}

function image_upload_files_from_field(array $field): array {
    if (!isset($field['name'])) {
        return [];
    }

    if (!is_array($field['name'])) {
        return [$field];
    }

    $files = [];
    $count = count($field['name']);
    for ($i = 0; $i < $count; $i++) {
        $files[] = [
            'name'     => $field['name'][$i] ?? '',
            'type'     => $field['type'][$i] ?? '',
            'tmp_name' => $field['tmp_name'][$i] ?? '',
            'error'    => $field['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $field['size'][$i] ?? 0,
        ];
    }

    return $files;
}

function image_upload_validate_file(array $file): array {
    $name = (string)($file['name'] ?? '');
    $tmp = (string)($file['tmp_name'] ?? '');
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $size = (int)($file['size'] ?? 0);

    if ($error === UPLOAD_ERR_NO_FILE || $name === '') {
        return ['ok' => false, 'skip' => true, 'error' => ''];
    }

    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'skip' => false, 'error' => 'Image upload failed. Please try again.'];
    }

    if ($size > IMAGE_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'skip' => false, 'error' => IMAGE_UPLOAD_SIZE_ERROR];
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, image_upload_allowed_extensions(), true)) {
        return ['ok' => false, 'skip' => false, 'error' => 'Only JPG, JPEG, PNG, and WEBP images are allowed.'];
    }

    $allowedMimes = image_upload_allowed_mimes();
    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)@$finfo->file($tmp);
    }

    if ($mime === '' || !isset($allowedMimes[$mime])) {
        $imageInfo = @getimagesize($tmp);
        $mime = (string)($imageInfo['mime'] ?? '');
    }

    if (!isset($allowedMimes[$mime]) || @getimagesize($tmp) === false) {
        return ['ok' => false, 'skip' => false, 'error' => 'Only JPG, JPEG, PNG, and WEBP images are allowed.'];
    }

    return [
        'ok' => true,
        'skip' => false,
        'error' => '',
        'ext' => $allowedMimes[$mime],
        'original_name' => $name,
        'tmp_name' => $tmp,
        'size' => $size,
    ];
}

function image_upload_validate_many(array $field, int $min = 1, int $max = 3): array {
    $valid = [];
    foreach (image_upload_files_from_field($field) as $file) {
        $result = image_upload_validate_file($file);
        if (!empty($result['skip'])) {
            continue;
        }
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'files' => []];
        }
        $valid[] = $result;
    }

    $count = count($valid);
    if ($count < $min || $count > $max) {
        return ['ok' => false, 'error' => "Please upload between {$min} and {$max} images.", 'files' => []];
    }

    return ['ok' => true, 'error' => '', 'files' => $valid];
}

function image_upload_safe_filename(string $prefix, string $originalName, string $ext): string {
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9_-]/', '_', $base);
    $base = trim((string)$base, '_');
    if ($base === '') {
        $base = 'image';
    }

    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $base . '.' . $ext;
}

function image_upload_move_validated(array $validatedFile, string $uploadDirAbs, string $dbPrefix, string $prefix): array {
    if (!is_dir($uploadDirAbs)) {
        @mkdir($uploadDirAbs, 0755, true);
    }

    if (!is_dir($uploadDirAbs)) {
        return ['ok' => false, 'error' => 'Upload directory is not available.'];
    }

    $newName = image_upload_safe_filename($prefix, $validatedFile['original_name'], $validatedFile['ext']);
    $destAbs = rtrim($uploadDirAbs, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $newName;

    if (!move_uploaded_file($validatedFile['tmp_name'], $destAbs)) {
        return ['ok' => false, 'error' => 'Failed to store uploaded image.'];
    }

    return [
        'ok' => true,
        'error' => '',
        'absolute_path' => $destAbs,
        'db_path' => rtrim($dbPrefix, '/') . '/' . $newName,
        'original_name' => $validatedFile['original_name'],
    ];
}

function image_upload_delete_db_path(string $dbPath, string $projectRoot): void {
    $path = str_replace('\\', '/', trim($dbPath));
    $path = preg_replace('~^(\.\./)+~', '', $path);
    $path = ltrim($path, '/');

    if (strpos($path, 'uploads/') !== 0) {
        return;
    }

    $root = realpath($projectRoot);
    if ($root === false) {
        return;
    }

    $absolute = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    if ($absolute === false || strpos($absolute, $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }

    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function image_upload_base64_to_file(string $base64Image, string $uploadDirAbs, string $dbPrefix, string $prefix): ?string {
    if (!preg_match('/^data:(image\/(?:jpeg|jpg|png|webp));base64,/', $base64Image, $type)) {
        return null;
    }

    $mime = strtolower($type[1]);
    if ($mime === 'image/jpg') {
        $mime = 'image/jpeg';
    }

    $allowed = image_upload_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return null;
    }

    $data = base64_decode(substr($base64Image, strpos($base64Image, ',') + 1), true);
    if ($data === false || strlen($data) > IMAGE_UPLOAD_MAX_BYTES) {
        return null;
    }

    if (!is_dir($uploadDirAbs)) {
        @mkdir($uploadDirAbs, 0755, true);
    }
    if (!is_dir($uploadDirAbs)) {
        return null;
    }

    $newName = image_upload_safe_filename($prefix, 'profile.' . $allowed[$mime], $allowed[$mime]);
    $destAbs = rtrim($uploadDirAbs, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $newName;

    if (file_put_contents($destAbs, $data) === false) {
        return null;
    }

    if (@getimagesize($destAbs) === false) {
        @unlink($destAbs);
        return null;
    }

    return rtrim($dbPrefix, '/') . '/' . $newName;
}
