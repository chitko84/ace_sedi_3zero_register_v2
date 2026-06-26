<?php

function public_normalize_upload_path(?string $path, string $folder = ''): ?string
{
    $path = trim((string)$path);
    if ($path === '') {
        return null;
    }

    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('~^(\.\./)+~', '', $path);
    $path = preg_replace('~^(\./)+~', '', $path);
    $path = ltrim($path, '/');

    if (strpos($path, 'uploads/') === 0) {
        return $path;
    }

    if (strpos($path, '/') !== false) {
        return 'uploads/' . $path;
    }

    if ($folder !== '') {
        $folder = trim($folder, '/');
        return 'uploads/' . $folder . '/' . basename($path);
    }

    return 'uploads/' . $path;
}

function public_upload_physical_path(?string $webPath): ?string
{
    $webPath = trim((string)$webPath);
    if ($webPath === '') {
        return null;
    }

    if (preg_match('~^https?://~i', $webPath)) {
        return null;
    }

    $path = parse_url($webPath, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    $uploadsPos = strpos($path, 'uploads/');
    if ($uploadsPos === false) {
        return null;
    }

    $relativePath = substr($path, $uploadsPos);

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function public_upload_file_exists(?string $webPath): bool
{
    if (preg_match('~^https?://~i', trim((string)$webPath))) {
        return true;
    }

    $absolutePath = public_upload_physical_path($webPath);

    return $absolutePath !== null && is_file($absolutePath);
}

function public_debug_image_path(?string $rawPath, ?string $normalizedPath): void
{
    $physicalPath = public_upload_physical_path($normalizedPath);
    $exists = $physicalPath !== null && is_file($physicalPath);

    error_log(sprintf(
        '[public image debug] raw="%s" normalized="%s" physical="%s" exists=%s',
        (string)$rawPath,
        (string)$normalizedPath,
        (string)($physicalPath ?? ''),
        $exists ? 'true' : 'false'
    ));
}

function public_placeholder_image(string $label = 'Image unavailable'): string
{
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 450">'
        . '<rect width="800" height="450" fill="#eef1f4"/>'
        . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle"'
        . ' font-family="Arial" font-size="22" fill="#9aa6b2">' . $safeLabel . '</text>'
        . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
