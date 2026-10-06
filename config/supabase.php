<?php
declare(strict_types=1);

function supabase_url(): string
{
    return $_ENV['SUPABASE_URL'] ?? getenv('SUPABASE_URL') ?: 'https://ghrexntoqduadflakijc.supabase.co';
}

function supabase_anon_key(): string
{
    return $_ENV['SUPABASE_ANON_KEY'] ?? getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdocmV4bnRvcWR1YWRmbGFraWpjIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODg4NjMyNjgsImV4cCI6MjEwNDQzOTI2OH0.CxORa-VSGILFmJF3T4BS_ZHSElVQ6FdStXv51XPBFeg';
}

function supabase_service_role_key(): string
{
    return $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? getenv('SUPABASE_SERVICE_ROLE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdocmV4bnRvcWR1YWRmbGFraWpjIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4ODg2MzI2OCwiZXhwIjoyMTA0NDM5MjY4fQ.j7S3xSN4GH4C4pi8Kc47FgI9rQryJv1x7iX7tnXf08w';
}

/**
 * Executes a Supabase PL/pgSQL function (RPC) via PDO.
 *
 * @param PDO $conn
 * @param string $functionName
 * @param array $params Key-value pairs for parameters or indexed values.
 * @return mixed Result of the RPC call.
 */
function supabase_call_rpc(PDO $conn, string $functionName, array $params = []): mixed
{
    $placeholders = [];
    $boundValues = [];

    foreach ($params as $key => $val) {
        if (is_string($key)) {
            $dbParam = str_starts_with($key, 'p_') ? $key : 'p_' . $key;
            $bindKey = ':' . ltrim($key, ':');
            $placeholders[] = "{$dbParam} => {$bindKey}";
            $boundValues[$bindKey] = is_array($val) ? json_encode($val) : $val;
        } else {
            $bindKey = ':p' . $key;
            $placeholders[] = $bindKey;
            $boundValues[$bindKey] = is_array($val) ? json_encode($val) : $val;
        }
    }

    $sql = "SELECT public.{$functionName}(" . implode(', ', $placeholders) . ") AS result";
    $stmt = $conn->prepare($sql);
    $stmt->execute($boundValues);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !array_key_exists('result', $row)) {
        return null;
    }

    $raw = $row['result'];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_numeric($decoded) || is_bool($decoded))) {
            return $decoded;
        }
    }

    return $raw;
}

/**
 * Executes a Supabase PL/pgSQL function (RPC) that returns a TABLE or set of rows via PDO.
 *
 * @param PDO $conn
 * @param string $functionName
 * @param array $params Key-value pairs for parameters.
 * @return array Array of associative rows.
 */
function supabase_call_rpc_table(PDO $conn, string $functionName, array $params = []): array
{
    $placeholders = [];
    $boundValues = [];

    foreach ($params as $key => $val) {
        if (is_string($key)) {
            $dbParam = str_starts_with($key, 'p_') ? $key : 'p_' . $key;
            $bindKey = ':' . ltrim($key, ':');
            $placeholders[] = "{$dbParam} => {$bindKey}";
            $boundValues[$bindKey] = is_array($val) ? json_encode($val) : $val;
        } else {
            $bindKey = ':p' . $key;
            $placeholders[] = $bindKey;
            $boundValues[$bindKey] = is_array($val) ? json_encode($val) : $val;
        }
    }

    $paramSql = !empty($placeholders) ? '(' . implode(', ', $placeholders) . ')' : '()';
    $sql = "SELECT * FROM public.{$functionName}{$paramSql}";
    $stmt = $conn->prepare($sql);
    $stmt->execute($boundValues);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Calls a Supabase RPC via the Supabase REST API (PostgREST).
 *
 * @param string $functionName
 * @param array $payload
 * @param bool $useServiceRole
 * @return array
 */
function supabase_rest_rpc(string $functionName, array $payload = [], bool $useServiceRole = true): array
{
    $url = rtrim(supabase_url(), '/') . '/rest/v1/rpc/' . urlencode($functionName);
    $key = $useServiceRole ? supabase_service_role_key() : supabase_anon_key();

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        throw new RuntimeException("Supabase RPC network error: {$error}");
    }

    $data = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($data) && isset($data['message']) ? $data['message'] : "Supabase RPC failed (HTTP {$status})";
        throw new RuntimeException($msg);
    }

    return is_array($data) ? $data : ['data' => $data];
}

/**
 * Returns a HTML script tag defining window.SUPABASE_CONFIG for frontend scripts.
 */
function supabase_frontend_config_tag(): string
{
    $config = [
        'url'     => supabase_url(),
        'anonKey' => supabase_anon_key(),
    ];
    $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    return "<script>window.SUPABASE_CONFIG = {$json};</script>";
}
