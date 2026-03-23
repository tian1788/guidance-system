<?php
/**
 * Guidance → HR: insert into public.hr_staff_requests via Supabase PostgREST (anon key),
 * same contract as PMED createHrStaffRequest / COMLAB comlab_push_hr_staff_request.
 */

declare(strict_types=1);

const GUIDANCE_HR_STAFF_DEPT_NAME = 'Guidance';

function guidance_hr_load_dotenv(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // guidance/includes → package root (where .env usually lives), then walk up for monorepo / HR/.env
    $current = dirname(__DIR__, 2);
    for ($depth = 0; $depth < 8; $depth++) {
        $path = $current . DIRECTORY_SEPARATOR . '.env';
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim(trim($value), "\"'");
                    if ($key !== '' && !array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                        putenv("{$key}={$value}");
                    }
                }
            }
        }
        $parent = dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
    }
}

function guidance_hr_env(string $key, string $default = ''): string
{
    guidance_hr_load_dotenv();
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    $v = getenv($key);
    return ($v !== false && $v !== '') ? (string) $v : $default;
}

function guidance_resolve_supabase_rest_base_url(): string
{
    foreach (
        [
            guidance_hr_env('SUPABASE_URL', ''),
            guidance_hr_env('VITE_SUPABASE_URL', ''),
        ] as $u
    ) {
        $u = rtrim((string) $u, '/');
        if ($u !== '') {
            return $u;
        }
    }

    foreach ([guidance_hr_env('DATABASE_URL', ''), guidance_hr_env('SUPABASE_DB_URL', ''), guidance_hr_env('SUPABASE_DATABASE_URL', '')] as $dsn) {
        if ($dsn === '') {
            continue;
        }
        $parts = parse_url((string) $dsn);
        if (!is_array($parts)) {
            continue;
        }
        $user = $parts['user'] ?? '';
        if (preg_match('/^postgres\.([a-z0-9]+)$/i', (string) $user, $m) === 1) {
            return 'https://' . strtolower($m[1]) . '.supabase.co';
        }
        $host = $parts['host'] ?? '';
        if (preg_match('/^db\.([a-z0-9]+)\.supabase\.co$/i', (string) $host, $m) === 1) {
            return 'https://' . strtolower($m[1]) . '.supabase.co';
        }
        if (preg_match('/^(?:aws-\d+-[a-z0-9-]+\.)?pooler\.supabase\.com$/i', (string) $host) === 1 && preg_match('/^(?:postgres\.|postgres:)([a-z0-9]{15,25})/i', (string) $user, $m2) === 1) {
            return 'https://' . strtolower($m2[1]) . '.supabase.co';
        }
    }

    return '';
}

function guidance_supabase_rest_json(
    string $base,
    string $key,
    string $method,
    string $pathQuery,
    ?array $body,
    string $prefer
): mixed {
    $raw = guidance_supabase_rest($base, $key, $method, $pathQuery, $body, $prefer);
    if ($raw === '' || $raw === 'null') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON from Supabase: ' . json_last_error_msg());
    }
    return $decoded;
}

function guidance_supabase_rest(
    string $base,
    string $key,
    string $method,
    string $pathQuery,
    ?array $body,
    string $prefer
): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl extension is required to send HR staff requests.');
    }

    $url = $base . '/rest/v1/' . ltrim($pathQuery, '/');
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
    ];
    if ($prefer !== '') {
        $headers[] = 'Prefer: ' . $prefer;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed.');
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 30,
    ];

    if ($body !== null && in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('Supabase HTTP error: ' . $err);
    }
    if ($response === false) {
        throw new RuntimeException('Empty response from Supabase.');
    }
    if ($status >= 400) {
        throw new RuntimeException('Supabase REST ' . $status . ': ' . $response);
    }

    return (string) $response;
}

/**
 * @param array<string, mixed> $extraMetadata merged into request metadata JSON
 * @return array{request_reference: string, staff_id: int}
 */
function guidance_push_hr_staff_request_to_hr(
    string $roleTypeCode,
    int $quantity,
    string $roleTitle,
    string $primaryNotes,
    string $requestedByDisplay,
    array $extraMetadata = []
): array {
    guidance_hr_load_dotenv();

    $base = guidance_resolve_supabase_rest_base_url();
    $key = (string) (
        guidance_hr_env('SUPABASE_ANON_KEY', '')
        ?: guidance_hr_env('SUPABASE_PUBLISHABLE_KEY', '')
        ?: guidance_hr_env('VITE_SUPABASE_ANON_KEY', '')
        ?: guidance_hr_env('VITE_SUPABASE_PUBLISHABLE_KEY', '')
        ?: ''
    );

    if ($base === '' || $key === '') {
        throw new RuntimeException(
            'Missing SUPABASE_URL (or VITE_SUPABASE_URL) and SUPABASE_ANON_KEY (or VITE_SUPABASE_PUBLISHABLE_KEY). Copy these from the HR app .env into Guidance .env so requests reach public.hr_staff_requests.'
        );
    }

    $roleType = strtolower((string) preg_replace('/[^a-z0-9_]/', '', $roleTypeCode));
    if ($roleType === '') {
        $roleType = 'counselor';
    }

    $poolKey = 'HR-REQ-POOL-' . strtoupper($roleType);
    $poolName = ucwords(str_replace('_', ' ', $roleType));

    $directoryBody = [
        'employee_no' => $poolKey,
        'full_name' => 'Open ' . $poolName . ' Hiring Request',
        'role_type' => $roleType,
        'department_name' => GUIDANCE_HR_STAFF_DEPT_NAME,
        'employment_status' => 'inactive',
        'contact_email' => null,
        'contact_phone' => null,
        'hired_at' => null,
    ];

    $poolLookupPath = 'hr_staff_directory?employee_no=eq.' . rawurlencode($poolKey) . '&select=id&limit=1';

    $staffRows = guidance_supabase_rest_json($base, $key, 'GET', $poolLookupPath, null, '');

    if (!is_array($staffRows) || !isset($staffRows[0]['id'])) {
        try {
            guidance_supabase_rest($base, $key, 'POST', 'hr_staff_directory', $directoryBody, 'return=representation');
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, ' 409') === false && strpos($msg, '23505') === false) {
                throw $e;
            }
        }
        $staffRows = guidance_supabase_rest_json($base, $key, 'GET', $poolLookupPath, null, '');
    }

    if (!is_array($staffRows) || !isset($staffRows[0]['id'])) {
        throw new RuntimeException('Failed to resolve hr_staff_directory pool row (' . $poolKey . ').');
    }

    $staffId = (int) $staffRows[0]['id'];
    $year = (int) gmdate('Y');
    $hrRef = 'HR-REQ-' . $year . '-' . random_int(10000, 99999);

    $notesParts = [
        $primaryNotes,
        'Requested count: ' . max(1, $quantity),
        'Role: ' . ($roleTitle !== '' ? $roleTitle : $roleType),
        'Department: ' . GUIDANCE_HR_STAFF_DEPT_NAME,
    ];
    $notes = implode(' | ', array_filter($notesParts, static function ($p) {
        return $p !== '';
    }));

    $metadata = array_merge([
        'source' => 'guidance_integration_hub',
        'hr_role_type' => $roleType,
        'requested_count' => max(1, $quantity),
    ], $extraMetadata);

    $requestBody = [
        'request_reference' => $hrRef,
        'staff_id' => $staffId,
        'request_status' => 'pending',
        'request_notes' => $notes,
        'requested_by' => $requestedByDisplay !== '' ? $requestedByDisplay : 'Guidance',
        'metadata' => $metadata,
    ];

    guidance_supabase_rest($base, $key, 'POST', 'hr_staff_requests', $requestBody, 'return=representation');

    return ['request_reference' => $hrRef, 'staff_id' => $staffId];
}
