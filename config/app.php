<?php

// Returns the application base URL, including the project folder.
function getAppBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (preg_match('#^(.*?)/backend/#', $script, $matches)) {
        return $scheme . '://' . $host . $matches[1];
    }

    $fallback = rtrim(dirname(dirname(dirname($script))), '/');
    if ($fallback === '' || $fallback === '.') {
        $fallback = '';
    }

    return $scheme . '://' . $host . $fallback;
}

// Builds an absolute URL for a project-relative path.
function buildAppUrl($path) {
    return rtrim(getAppBaseUrl(), '/') . '/' . ltrim($path, '/');
}

?>