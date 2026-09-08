<?php
/**
 * Airdrop Watch — configuration loader (env + chain JSON).
 *
 * Code: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Load KEY=value file into environment if not already set.
 */
function aw_load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}

function aw_env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
}

function aw_repo_root(): string
{
    // includes/ → public → app → repo root
    return dirname(__DIR__, 3);
}

/**
 * @return array<string,mixed>
 */
function aw_chain(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $candidates = [];
    $fromEnv = aw_env('AW_CHAIN_FILE');
    if ($fromEnv) {
        $candidates[] = $fromEnv;
    }
    $root = aw_repo_root();
    $candidates[] = $root . '/config/chain.json';
    $candidates[] = $root . '/config/chain.example.json';

    $path = null;
    foreach (array_unique($candidates) as $c) {
        $real = realpath($c);
        if ($real !== false && is_readable($real)) {
            $path = $real;
            break;
        }
    }

    $defaults = [
        'id' => 1,
        'name' => 'Unknown chain',
        'native_symbol' => 'ETH',
        'explorer' => '',
        'api_base' => '',
        'rpc' => '',
        'uniswap_chain_slug' => '',
        'amm_links' => [
            'uniswap' => true,
            'pons' => false,
            'pons_url' => '',
        ],
    ];

    if ($path === null) {
        $cache = $defaults;
        return $cache;
    }

    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json)) {
        $cache = $defaults;
        return $cache;
    }
    $cache = array_replace_recursive($defaults, $json);
    return $cache;
}

/**
 * @return array{
 *   address:string,
 *   password_hash:?string,
 *   seed_token:string,
 *   seed_symbol:string,
 *   seed_label:string,
 *   amm:string,
 *   proxy_url:?string,
 *   proxy_key:?string,
 *   poll_ms:int
 * }
 */
function aw_watch_config(): array
{
    $addr = strtolower((string) aw_env('AW_WATCH_ADDRESS', ''));
    $amm = strtolower((string) aw_env('AW_AMM', 'both'));
    if (!in_array($amm, ['both', 'uniswap', 'pons', 'none'], true)) {
        $amm = 'both';
    }
    $poll = (int) aw_env('AW_POLL_MS', '10000');
    if ($poll < 3000) {
        $poll = 3000;
    }

    $proxyUrl = aw_env('AW_PROXY_URL');
    $proxyKey = aw_env('AW_PROXY_KEY');

    return [
        'address' => $addr,
        'password_hash' => aw_env('AW_PASSWORD_HASH'),
        'seed_token' => strtolower((string) aw_env('AW_SEED_TOKEN', '')),
        'seed_symbol' => (string) aw_env('AW_SEED_SYMBOL', 'DEMO'),
        'seed_label' => (string) aw_env('AW_SEED_LABEL', 'Stand-in token'),
        'amm' => $amm,
        'proxy_url' => ($proxyUrl !== null && $proxyUrl !== '') ? rtrim($proxyUrl, '/') : null,
        'proxy_key' => ($proxyKey !== null && $proxyKey !== '') ? $proxyKey : null,
        'poll_ms' => $poll,
    ];
}

function aw_bootstrap(): void
{
    $p = aw_repo_root() . '/config/watch.env';
    if (is_readable($p)) {
        aw_load_env_file($p);
    }
}
