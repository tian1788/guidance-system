<?php

/**
 * Supabase (PostgreSQL) connection adapter.
 * Keeps the old mysqli-like API used in this project: $conn->query(), ->num_rows, ->fetch_assoc().
 */
class SupabaseDbResult {
    public int $num_rows = 0;
    private array $rows = [];
    private int $pointer = 0;

    public function __construct(array $rows) {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc() {
        if ($this->pointer >= $this->num_rows) {
            return null;
        }

        $row = $this->rows[$this->pointer];
        $this->pointer++;
        return $row;
    }
}

class SupabaseDbConnection {
    public string $connect_error = '';
    private ?PDO $pdo = null;

    public function __construct() {
        $config = $this->loadConfig();

        if (!$config['host'] || !$config['user'] || !$config['password']) {
            $this->connect_error = 'Missing Supabase DB credentials. Fill .env (SUPABASE_DB_*).';
            return;
        }

        $schema = $config['schema'] ?: 'guidance';
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};sslmode=require;options=--search_path={$schema},public";

        try {
            $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            $this->connect_error = $e->getMessage();
        }
    }

    public function query(string $sql) {
        if ($this->pdo === null) {
            return false;
        }

        $normalizedSql = $this->normalizeSql($sql);

        try {
            $stmt = $this->pdo->query($normalizedSql);

            if ($stmt === false) {
                return false;
            }

            if ($this->isSelectQuery($normalizedSql)) {
                return new SupabaseDbResult($stmt->fetchAll());
            }

            return true;
        } catch (Throwable $e) {
            error_log('DB query failed: ' . $e->getMessage() . ' | SQL: ' . $normalizedSql);
            return false;
        }
    }

    private function isSelectQuery(string $sql): bool {
        return (bool) preg_match('/^\s*(SELECT|WITH)\b/i', $sql);
    }

    private function normalizeSql(string $sql): string {
        $sql = str_replace('`', '"', $sql);
        $sql = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $sql);
        $sql = preg_replace('/\bINTERVAL\s+(\d+)\s+DAY\b/i', "INTERVAL '$1 day'", $sql);
        return $sql;
    }

    private function loadConfig(): array {
        $this->loadCandidateEnvFiles();

        $databaseUrl = $this->env('SUPABASE_DATABASE_URL', '');
        if ($databaseUrl === '') {
            $databaseUrl = $this->env('SUPABASE_DB_URL', '');
        }
        if ($databaseUrl === '') {
            $databaseUrl = $this->env('DATABASE_URL', '');
        }
        if ($databaseUrl !== '') {
            $parts = parse_url($databaseUrl);
            if ($parts !== false) {
                return [
                    'host' => $parts['host'] ?? '',
                    'port' => $parts['port'] ?? 5432,
                    'dbname' => isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres',
                    'user' => $parts['user'] ?? '',
                    'password' => $parts['pass'] ?? '',
                    'schema' => $this->env('SUPABASE_DB_SCHEMA', 'guidance'),
                ];
            }
        }

        return [
            'host' => $this->env('SUPABASE_DB_HOST', ''),
            'port' => (int) $this->env('SUPABASE_DB_PORT', '5432'),
            'dbname' => $this->env('SUPABASE_DB_NAME', 'postgres'),
            'user' => $this->env('SUPABASE_DB_USER', ''),
            'password' => $this->env('SUPABASE_DB_PASSWORD', ''),
            'schema' => $this->env('SUPABASE_DB_SCHEMA', 'guidance'),
        ];
    }

    private function loadCandidateEnvFiles(): void {
        $paths = [];
        $current = dirname(__DIR__);

        for ($depth = 0; $depth < 5; $depth++) {
            $paths[] = $current . DIRECTORY_SEPARATOR . '.env';
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        foreach (array_unique($paths) as $path) {
            $this->loadDotEnv($path);
        }
    }

    private function env(string $key, string $default = ''): string {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        return $default;
    }

    private function loadDotEnv(string $path): void {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");

            if ($key !== '' && !array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

$conn = new SupabaseDbConnection();

if ($conn->connect_error !== '') {
    die('Connection Failed: ' . $conn->connect_error);
}

?>
