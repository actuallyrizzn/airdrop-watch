<?php
/**
 * Airdrop Watch — snapshot fetch + formatting.
 *
 * Code: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function aw_is_authenticated(): bool
{
    return isset($_SESSION['aw_authenticated']) && $_SESSION['aw_authenticated'] === true;
}

/**
 * @param array<int,string> $headers
 * @return array{ok:bool,status:int,body:?string,json:mixed,error:?string}
 */
function aw_http_get(string $url, int $timeout = 25, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'json' => null, 'error' => 'curl_init failed'];
    }

    $httpHeaders = array_merge([
        'Accept: application/json',
        'User-Agent: airdrop-watch/0.1',
    ], $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $httpHeaders,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => null, 'json' => null, 'error' => $err ?: 'request failed'];
    }

    $json = json_decode($body, true);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'json' => $json,
        'error' => ($status >= 200 && $status < 300) ? null : ('HTTP ' . $status),
    ];
}

function aw_native_usd_fallback(): ?float
{
    $resp = aw_http_get('https://coins.llama.fi/prices/current/coingecko:ethereum', 12);
    if ($resp['ok'] && is_array($resp['json'])) {
        $price = $resp['json']['coins']['coingecko:ethereum']['price'] ?? null;
        if (is_numeric($price)) {
            return (float) $price;
        }
    }
    $resp = aw_http_get(
        'https://api.coingecko.com/api/v3/simple/price?ids=ethereum&vs_currencies=usd',
        12
    );
    if ($resp['ok'] && is_array($resp['json'])) {
        $price = $resp['json']['ethereum']['usd'] ?? null;
        if (is_numeric($price)) {
            return (float) $price;
        }
    }
    return null;
}

/**
 * @return array<string,mixed>|null
 */
function aw_rpc(string $rpcUrl, string $method, array $params = []): ?array
{
    if ($rpcUrl === '') {
        return null;
    }
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]);

    $ch = curl_init($rpcUrl);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false) {
        return null;
    }

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function aw_hex_to_dec(string $hex): string
{
    $hex = strtolower(ltrim($hex, '0x'));
    if ($hex === '' || $hex === '0') {
        return '0';
    }
    if (function_exists('gmp_init')) {
        return gmp_strval(gmp_init($hex, 16), 10);
    }
    return (string) hexdec($hex);
}

function aw_format_amount(string $raw, int $decimals, int $maxFrac = 6): string
{
    $raw = preg_replace('/\D/', '', $raw) ?: '0';
    if ($decimals <= 0) {
        return $raw;
    }
    if (strlen($raw) <= $decimals) {
        $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
    }
    $whole = substr($raw, 0, -$decimals) ?: '0';
    $frac = substr($raw, -$decimals);
    $frac = rtrim(substr($frac, 0, $maxFrac), '0');
    return $frac === '' ? $whole : ($whole . '.' . $frac);
}

function aw_amount_to_float(string $raw, int $decimals): float
{
    $formatted = aw_format_amount($raw, $decimals, min(8, max(0, $decimals)));
    return (float) $formatted;
}

/**
 * @return array<string,mixed>
 */
function aw_seed_config(): array
{
    $w = aw_watch_config();
    $chain = aw_chain();
    $token = $w['seed_token'];
    $slug = (string) ($chain['uniswap_chain_slug'] ?? '');
    $amm = $w['amm'];
    $uni = '';
    if ($token !== '' && $slug !== '') {
        $uni = 'https://app.uniswap.org/swap?chain=' . rawurlencode($slug) . '&outputCurrency=' . $token;
    }
    $ponsUrl = (string) (($chain['amm_links']['pons_url'] ?? '') ?: '');
    $explorer = rtrim((string) ($chain['explorer'] ?? ''), '/');

    return [
        'token' => $token,
        'symbol' => $w['seed_symbol'],
        'label' => $w['seed_label'],
        'amm' => $amm,
        'uniswap_url' => $uni,
        'pons_url' => $ponsUrl,
        'explorer_url' => $token !== '' && $explorer !== '' ? ($explorer . '/token/' . $token) : '',
    ];
}

/**
 * @param array<string,mixed> $snapshot
 * @param array<string,mixed> $cfg
 * @return array<string,mixed>
 */
function aw_airdrop_status(array $snapshot, array $cfg): array
{
    $want = strtolower((string) ($cfg['token'] ?? ''));
    $holding = null;
    foreach ($snapshot['holdings'] ?? [] as $h) {
        if (strtolower((string) ($h['contract'] ?? '')) === $want) {
            $holding = $h;
            break;
        }
    }

    $matchedDrops = [];
    $otherNewDrops = [];
    foreach ($snapshot['token_drops'] ?? [] as $d) {
        $c = strtolower((string) ($d['contract'] ?? ''));
        if ($want !== '' && $c === $want) {
            $matchedDrops[] = $d;
        } else {
            $otherNewDrops[] = $d;
        }
    }

    if ($holding !== null && (float) ($holding['amount'] ?? 0) > 0) {
        $status = 'held';
        $status_label = 'Seed / watched token is in the wallet';
    } elseif ($matchedDrops !== []) {
        $status = 'seen_drop';
        $status_label = 'Drop seen (balance may already be spent)';
    } else {
        $status = 'waiting';
        $status_label = 'Waiting for watched token drop';
    }

    return [
        'config' => $cfg,
        'status' => $status,
        'status_label' => $status_label,
        'holding' => $holding,
        'drops' => $matchedDrops,
        'other_incoming' => $otherNewDrops,
    ];
}

/**
 * @return array<string,mixed>
 */
function aw_fetch_snapshot(?string $address = null): array
{
    $watch = aw_watch_config();
    $chain = aw_chain();
    $address = strtolower($address ?: $watch['address']);
    $errors = [];
    $addr = [];
    $tokenRows = [];
    $transfers = [];
    $txs = [];
    $source = 'direct';

    $apiBase = rtrim((string) ($chain['api_base'] ?? ''), '/');
    $explorer = rtrim((string) ($chain['explorer'] ?? ''), '/');
    $rpcUrl = (string) ($chain['rpc'] ?? '');

    if ($watch['proxy_url'] !== null && $watch['proxy_key'] !== null) {
        $proxyResp = aw_http_get(
            $watch['proxy_url'] . '/snapshot',
            120,
            ['X-AW-Proxy-Key: ' . $watch['proxy_key']]
        );
        $pj = is_array($proxyResp['json']) ? $proxyResp['json'] : null;
        $proxyUsable = is_array($pj) && (
            (isset($pj['address']) && is_array($pj['address']) && $pj['address'] !== [])
            || (isset($pj['token_balances']) && is_array($pj['token_balances']) && $pj['token_balances'] !== [])
            || (isset($pj['token_transfers']['items']) && is_array($pj['token_transfers']['items']))
            || (isset($pj['transactions']['items']) && is_array($pj['transactions']['items']))
        );
        if ($proxyUsable) {
            $source = (string) ($pj['source'] ?? 'proxy');
            $addr = is_array($pj['address'] ?? null) ? $pj['address'] : [];
            $tokenRows = is_array($pj['token_balances'] ?? null) ? $pj['token_balances'] : [];
            $xfer = is_array($pj['token_transfers'] ?? null) ? $pj['token_transfers'] : [];
            $txWrap = is_array($pj['transactions'] ?? null) ? $pj['transactions'] : [];
            $transfers = is_array($xfer['items'] ?? null) ? $xfer['items'] : [];
            $txs = is_array($txWrap['items'] ?? null) ? $txWrap['items'] : [];
            if (!empty($pj['errors']) && is_array($pj['errors'])) {
                foreach ($pj['errors'] as $e) {
                    $errors[] = 'proxy: ' . (string) $e;
                }
            }
        } else {
            $errors[] = 'proxy: ' . ($proxyResp['error'] ?? 'failed');
        }
    }

    if ($tokenRows === [] && $addr === [] && $transfers === [] && $txs === [] && $apiBase !== '') {
        $source = 'direct';
        $base = $apiBase . '/addresses/' . $address;
        $addrResp = aw_http_get($base);
        $tokensResp = aw_http_get($base . '/token-balances');
        $xferResp = aw_http_get($base . '/token-transfers?type=ERC-20');
        $txResp = aw_http_get($base . '/transactions');

        foreach ([
            'address' => $addrResp,
            'tokens' => $tokensResp,
            'transfers' => $xferResp,
            'transactions' => $txResp,
        ] as $label => $resp) {
            if (!$resp['ok']) {
                $errors[] = $label . ': ' . ($resp['error'] ?? 'failed');
            }
        }

        $addr = is_array($addrResp['json']) ? $addrResp['json'] : [];
        $tokenRows = is_array($tokensResp['json']) ? $tokensResp['json'] : [];
        if (is_array($xferResp['json']) && isset($xferResp['json']['items']) && is_array($xferResp['json']['items'])) {
            $transfers = $xferResp['json']['items'];
        }
        if (is_array($txResp['json']) && isset($txResp['json']['items']) && is_array($txResp['json']['items'])) {
            $txs = $txResp['json']['items'];
        }
    }

    $ethWei = isset($addr['coin_balance']) ? (string) $addr['coin_balance'] : '0';
    $rpc = aw_rpc($rpcUrl, 'eth_getBalance', [$address, 'latest']);
    if (is_array($rpc) && isset($rpc['result']) && is_string($rpc['result'])) {
        $ethWei = aw_hex_to_dec($rpc['result']);
    }

    $ethRate = isset($addr['exchange_rate']) && is_numeric($addr['exchange_rate'])
        ? (float) $addr['exchange_rate']
        : null;
    if ($ethRate === null) {
        $ethRate = aw_native_usd_fallback();
    }
    $ethAmount = aw_amount_to_float($ethWei, 18);
    $ethUsd = $ethRate !== null ? $ethAmount * $ethRate : null;

    $holdings = [];
    $tokensUsd = 0.0;
    $tokensUsdKnown = false;

    foreach ($tokenRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $token = isset($row['token']) && is_array($row['token']) ? $row['token'] : [];
        $decimals = isset($token['decimals']) && is_numeric($token['decimals'])
            ? (int) $token['decimals']
            : 18;
        $raw = isset($row['value']) ? (string) $row['value'] : '0';
        $amount = aw_amount_to_float($raw, $decimals);
        $rate = isset($token['exchange_rate']) && is_numeric($token['exchange_rate'])
            ? (float) $token['exchange_rate']
            : null;
        $usd = $rate !== null ? $amount * $rate : null;
        if ($usd !== null) {
            $tokensUsd += $usd;
            $tokensUsdKnown = true;
        }

        $holdings[] = [
            'symbol' => (string) ($token['symbol'] ?? '???'),
            'name' => (string) ($token['name'] ?? ''),
            'contract' => (string) ($token['address_hash'] ?? ''),
            'decimals' => $decimals,
            'raw' => $raw,
            'amount' => $amount,
            'amount_display' => aw_format_amount($raw, $decimals),
            'exchange_rate' => $rate,
            'usd' => $usd,
            'icon_url' => $token['icon_url'] ?? null,
            'type' => (string) ($token['type'] ?? 'ERC-20'),
        ];
    }

    usort($holdings, static function ($a, $b) {
        $au = $a['usd'] ?? -1;
        $bu = $b['usd'] ?? -1;
        if ($au == $bu) {
            return strcmp($a['symbol'], $b['symbol']);
        }
        return $bu <=> $au;
    });

    $drops = [];
    foreach ($transfers as $t) {
        if (!is_array($t)) {
            continue;
        }
        $toHash = strtolower((string) ($t['to']['hash'] ?? ''));
        $fromHash = strtolower((string) ($t['from']['hash'] ?? ''));
        $direction = $toHash === $address ? 'in' : ($fromHash === $address ? 'out' : 'other');
        $token = isset($t['token']) && is_array($t['token']) ? $t['token'] : [];
        $decimals = isset($t['total']['decimals']) && is_numeric($t['total']['decimals'])
            ? (int) $t['total']['decimals']
            : (isset($token['decimals']) && is_numeric($token['decimals']) ? (int) $token['decimals'] : 18);
        $raw = isset($t['total']['value']) ? (string) $t['total']['value'] : '0';

        $drops[] = [
            'direction' => $direction,
            'timestamp' => (string) ($t['timestamp'] ?? ''),
            'tx_hash' => (string) ($t['transaction_hash'] ?? ''),
            'from' => (string) ($t['from']['hash'] ?? ''),
            'to' => (string) ($t['to']['hash'] ?? ''),
            'symbol' => (string) ($token['symbol'] ?? '???'),
            'name' => (string) ($token['name'] ?? ''),
            'contract' => (string) ($token['address_hash'] ?? ''),
            'amount_display' => aw_format_amount($raw, $decimals),
            'is_drop' => $direction === 'in',
        ];
    }

    $activity = [];
    foreach ($txs as $tx) {
        if (!is_array($tx)) {
            continue;
        }
        $from = strtolower((string) ($tx['from']['hash'] ?? ''));
        $to = strtolower((string) ($tx['to']['hash'] ?? ''));
        $direction = $from === $address ? 'out' : ($to === $address ? 'in' : 'other');
        $valueWei = isset($tx['value']) ? (string) $tx['value'] : '0';
        $activity[] = [
            'hash' => (string) ($tx['hash'] ?? ''),
            'timestamp' => (string) ($tx['timestamp'] ?? ''),
            'status' => (string) ($tx['status'] ?? ''),
            'method' => $tx['method'] ?? null,
            'from' => (string) ($tx['from']['hash'] ?? ''),
            'to' => (string) ($tx['to']['hash'] ?? ''),
            'direction' => $direction,
            'value_eth' => aw_format_amount($valueWei, 18),
            'types' => $tx['transaction_types'] ?? [],
            'fee_eth' => isset($tx['fee']['value'])
                ? aw_format_amount((string) $tx['fee']['value'], 18, 8)
                : null,
        ];
    }

    $totalUsd = null;
    if ($ethUsd !== null) {
        $totalUsd = $ethUsd + ($tokensUsdKnown ? $tokensUsd : 0.0);
    }

    $incomingDrops = array_values(array_filter($drops, static fn ($d) => !empty($d['is_drop'])));

    $out = [
        'fetched_at' => gmdate('c'),
        'address' => $address,
        'chain' => [
            'id' => (int) ($chain['id'] ?? 0),
            'name' => (string) ($chain['name'] ?? ''),
            'explorer' => $explorer,
            'native_symbol' => (string) ($chain['native_symbol'] ?? 'ETH'),
        ],
        'eth' => [
            'wei' => $ethWei,
            'amount' => $ethAmount,
            'amount_display' => aw_format_amount($ethWei, 18),
            'exchange_rate' => $ethRate,
            'usd' => $ethUsd,
        ],
        'totals' => [
            'usd' => $totalUsd,
            'tokens_usd' => $tokensUsdKnown ? $tokensUsd : null,
            'token_count' => count($holdings),
            'usd_partial' => $ethUsd !== null && !$tokensUsdKnown && count($holdings) > 0,
        ],
        'holdings' => $holdings,
        'token_drops' => $incomingDrops,
        'token_transfers' => $drops,
        'activity' => $activity,
        'errors' => $errors,
        'source' => $source,
        'airdrop_demo' => null,
    ];

    $seed = aw_seed_config();
    $out['airdrop_demo'] = aw_airdrop_status($out, $seed);

    return $out;
}
