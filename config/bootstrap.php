<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'RakanWiman\\';
        if (str_starts_with($class, $prefix)) {
            $file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    });
}

require_once $root . '/app/Core/Helpers.php';

$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) $value = $decoded;
            else $value = substr($value, 1, -1);
        } elseif (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) putenv($key . '=' . $value);
    }
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(getenv('SESSION_NAME') ?: 'rakan_wiman_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => filter_var(getenv('SESSION_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
