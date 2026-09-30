<?php
declare(strict_types=1);

/**
 * Server-side only. Never include this file from anything under public/js or output it.
 * Set these as environment variables (Apache SetEnv, hosting panel, or a .env loaded below).
 *
 *   DB_HOST  e.g. aws-0-eu-west-1.pooler.supabase.com   (Supabase > Connect > Session pooler)
 *   DB_PORT  5432
 *   DB_NAME  postgres
 *   DB_USER  postgres.<project-ref>
 *   DB_PASS  database password
 *   SUPABASE_URL          https://<project-ref>.supabase.co
 *   SUPABASE_SERVICE_KEY  service-role key (server only, used for Storage uploads)
 */

function env_value(string $key, ?string $default = null): ?string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = __DIR__ . '/../.env';   // keep .env outside the web root in production
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $file[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : ($file[$key] ?? $default);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        env_value('DB_HOST'), env_value('DB_PORT', '5432'), env_value('DB_NAME', 'postgres')
    );
    try {
        $pdo = new PDO($dsn, env_value('DB_USER'), env_value('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET TIME ZONE 'UTC'");
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('The service is temporarily unavailable. Please try again later.');
    }
    return $pdo;
}

/** Upload a validated image to Supabase Storage; returns the public URL. */
function storage_upload(string $tmpPath, string $objectName, string $mime): string
{
    $base   = rtrim((string) env_value('SUPABASE_URL'), '/');
    $key    = (string) env_value('SUPABASE_SERVICE_KEY');
    $bucket = env_value('SUPABASE_BUCKET', 'asset-images');

    $ch = curl_init("$base/storage/v1/object/$bucket/$objectName");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => file_get_contents($tmpPath),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $key", "apikey: $key", "Content-Type: $mime"],
    ]);
$response = curl_exec($ch);
$error = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code < 200 || $code >= 300) {
    error_log("Storage upload failed with HTTP $code");
    error_log("Storage upload error: " . ($error ?: 'none'));
    error_log("Storage upload body: " . ($response ?: 'empty'));
    throw new RuntimeException("Image upload failed. HTTP $code. " . ($error ?: 'No cURL error'));
}
    return "$base/storage/v1/object/public/$bucket/$objectName";
}
