<?php
/**
 * Airdrop Watch — password-gated live wallet / airdrop monitor.
 *
 * Code: AGPL-3.0-or-later · Docs & non-code: CC-BY-SA-4.0 (see LICENSING.md)
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('session.gc_maxlifetime', '86400');
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/lib.php';

aw_bootstrap();
$watch = aw_watch_config();
$chain = aw_chain();
$error = null;

if (isset($_GET['logout'])) {
    unset($_SESSION['aw_authenticated']);
    header('Location: ./');
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['password'])
    && !isset($_POST['refresh'])
) {
    $stored = $watch['password_hash'];
    if ($stored === null || $stored === '') {
        $error = 'Monitor login is not configured (set AW_PASSWORD_HASH).';
    } elseif (password_verify((string) $_POST['password'], $stored)) {
        $_SESSION['aw_authenticated'] = true;
        header('Location: ./');
        exit;
    } else {
        $error = 'Invalid password.';
    }
}

$authenticated = aw_is_authenticated();

if ($authenticated && isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(aw_fetch_snapshot(), JSON_UNESCAPED_SLASHES);
    exit;
}

$snapshot = null;
if ($authenticated) {
    if ($watch['address'] === '' || !preg_match('/^0x[a-f0-9]{40}$/', $watch['address'])) {
        $error = 'Set AW_WATCH_ADDRESS to a valid 0x wallet.';
    } else {
        $snapshot = aw_fetch_snapshot();
    }
}

function aw_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function aw_usd(?float $n): string
{
    if ($n === null) {
        return '—';
    }
    return '$' . number_format($n, 2);
}

function aw_short_addr(string $addr): string
{
    if (strlen($addr) < 12) {
        return $addr;
    }
    return substr($addr, 0, 6) . '…' . substr($addr, -4);
}

function aw_explorer_tx(string $hash): string
{
    $ex = rtrim((string) (aw_chain()['explorer'] ?? ''), '/');
    return $ex . '/tx/' . $hash;
}

function aw_explorer_addr(string $addr): string
{
    $ex = rtrim((string) (aw_chain()['explorer'] ?? ''), '/');
    return $ex . '/address/' . $addr;
}

function aw_explorer_token(string $addr): string
{
    $ex = rtrim((string) (aw_chain()['explorer'] ?? ''), '/');
    return $ex . '/token/' . $addr;
}

function aw_time(?string $iso): string
{
    if (!$iso) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
    } catch (Exception $e) {
        return $iso;
    }
}

$adCfg = [];
$amm = $watch['amm'];
if (is_array($snapshot)) {
    $airdrop = $snapshot['airdrop_demo'] ?? null;
    $adCfg = is_array($airdrop) ? ($airdrop['config'] ?? []) : [];
    $amm = (string) ($adCfg['amm'] ?? $amm);
}
$showPons = !empty($chain['amm_links']['pons']) && in_array($amm, ['both', 'pons'], true);
$showUni = !empty($chain['amm_links']['uniswap']) && in_array($amm, ['both', 'uniswap'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Airdrop Watch</title>
    <link rel="stylesheet" href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700&f[]=technor@700&display=swap">
    <link rel="stylesheet" href="css/airdrop-watch.css">
</head>
<body class="rw-body">
<?php if (!$authenticated): ?>
    <div class="rw-shell">
        <div class="rw-login">
            <h1>Airdrop Watch</h1>
            <p>Password required. Live wallet monitor for expected token drops.</p>
            <?php if ($error): ?>
                <div class="rw-error"><?= aw_h($error) ?></div>
            <?php endif; ?>
            <form method="POST" action="./" autocomplete="current-password">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autofocus>
                <button class="rw-btn rw-btn-primary" type="submit">Enter</button>
            </form>
            <p class="rw-sub" style="margin-top:1rem">AGPL-3.0 code · CC-BY-SA-4.0 docs</p>
        </div>
    </div>
<?php elseif ($snapshot === null): ?>
    <div class="rw-shell">
        <div class="rw-login">
            <h1>Airdrop Watch</h1>
            <div class="rw-error"><?= aw_h($error ?: 'Not configured.') ?></div>
            <a class="rw-btn" href="?logout=1">Log out</a>
        </div>
    </div>
<?php else: ?>
    <?php
        $eth = $snapshot['eth'] ?? [];
        $totals = $snapshot['totals'] ?? [];
        $holdings = $snapshot['holdings'] ?? [];
        $drops = $snapshot['token_drops'] ?? [];
        $activity = $snapshot['activity'] ?? [];
        $errors = $snapshot['errors'] ?? [];
        $addr = $snapshot['address'] ?? $watch['address'];
        $native = (string) ($chain['native_symbol'] ?? 'ETH');
    ?>
    <div class="rw-shell">
        <div class="rw-top">
            <div>
                <h1 class="rw-brand">Airdrop Watch</h1>
                <p class="rw-sub"><?= aw_h((string) ($chain['name'] ?? '')) ?> · chain id <?= (int) ($chain['id'] ?? 0) ?></p>
            </div>
            <div class="rw-actions">
                <button class="rw-btn" type="button" id="rw-pause-new" aria-pressed="false">Pause new airdrop monitoring</button>
                <button class="rw-btn" type="button" id="rw-refresh">Refresh</button>
                <a class="rw-btn" href="<?= aw_h(aw_explorer_addr($addr)) ?>" target="_blank" rel="noopener">Explorer</a>
                <a class="rw-btn" href="?logout=1">Log out</a>
            </div>
        </div>

        <div class="rw-meta">
            <span>Address: <span class="rw-addr"><?= aw_h($addr) ?></span></span>
            <span>Fetched: <span id="rw-fetched"><?= aw_h($snapshot['fetched_at'] ?? '') ?></span></span>
            <span class="rw-live" id="rw-live"><span class="rw-live-dot" aria-hidden="true"></span> live · <span id="rw-age">just now</span></span>
            <span class="rw-chip" id="rw-watch-mode">watching for new drops</span>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="rw-warn">
                Some explorer calls failed: <?= aw_h(implode('; ', $errors)) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($totals['usd_partial'])): ?>
            <div class="rw-warn">
                Total USD includes native coin plus priced tokens only — some holdings have no exchange rate yet.
            </div>
        <?php endif; ?>

        <section class="rw-panel rw-airdrop" id="rw-airdrop"
            data-seed-token="<?= aw_h((string) ($adCfg['token'] ?? $watch['seed_token'])) ?>"
            data-seed-symbol="<?= aw_h((string) ($adCfg['symbol'] ?? $watch['seed_symbol'])) ?>"
            data-seed-name="<?= aw_h((string) ($adCfg['label'] ?? $watch['seed_label'])) ?>"
            data-amm="<?= aw_h($amm) ?>">
            <div class="rw-panel-h">
                <h2>Airdrop watch</h2>
                <span class="rw-chip rw-chip-demo" id="rw-ad-mode">tabs · live</span>
            </div>
            <div class="rw-panel-b">
                <p class="rw-airdrop-note" id="rw-ad-note">
                    Leave this page open while you wait on a drop. New incoming tokens become the front tab;
                    pause new monitoring from the top bar to lock the front tab (that tab still live-updates).
                </p>
                <div class="rw-tabs" id="rw-ad-tabs" role="tablist" aria-label="Airdrop tabs"></div>
                <div id="rw-ad-empty" class="rw-empty" hidden>No airdrop tabs yet — waiting for the first incoming token drop.</div>
                <div id="rw-ad-body">
                    <div class="rw-airdrop-grid">
                        <div class="rw-stat">
                            <div class="label">Foreground token</div>
                            <div class="value" id="rw-ad-symbol">—</div>
                            <div class="hint rw-mono" id="rw-ad-contract"></div>
                        </div>
                        <div class="rw-stat">
                            <div class="label">Status</div>
                            <div class="value">
                                <span class="rw-chip rw-chip-wait" id="rw-ad-status-chip">waiting</span>
                            </div>
                            <div class="hint" id="rw-ad-status-label">Watching</div>
                        </div>
                        <div class="rw-stat">
                            <div class="label">Balance</div>
                            <div class="value" id="rw-ad-balance">0</div>
                            <div class="hint" id="rw-ad-usd">—</div>
                        </div>
                    </div>
                    <div class="rw-airdrop-links" id="rw-ad-links">
                        <a class="rw-btn" id="rw-ad-explorer" href="#" target="_blank" rel="noopener">Token on explorer</a>
                        <a class="rw-btn rw-btn-primary" id="rw-ad-uni" href="#" target="_blank" rel="noopener"<?= $showUni ? '' : ' hidden' ?>>Trade on Uniswap</a>
                        <a class="rw-btn" id="rw-ad-pons" href="<?= aw_h((string) (($chain['amm_links']['pons_url'] ?? '') ?: '#')) ?>" target="_blank" rel="noopener"<?= $showPons ? '' : ' hidden' ?>>Open Pons</a>
                    </div>
                    <h3 class="rw-ad-h">Drops of this token</h3>
                    <div id="rw-ad-drops"><p class="rw-empty">No drops yet for this tab.</p></div>
                </div>
            </div>
        </section>

        <div class="rw-grid" id="rw-stats">
            <div class="rw-stat">
                <div class="label">Total value</div>
                <div class="value" id="rw-total-usd"><?= aw_h(aw_usd($totals['usd'] ?? null)) ?></div>
                <div class="hint"><?= aw_h($native) ?> + priced tokens</div>
            </div>
            <div class="rw-stat">
                <div class="label"><?= aw_h($native) ?></div>
                <div class="value" id="rw-eth"><?= aw_h($eth['amount_display'] ?? '0') ?></div>
                <div class="hint" id="rw-eth-usd"><?= aw_h(aw_usd($eth['usd'] ?? null)) ?>
                    <?php if (isset($eth['exchange_rate'])): ?>
                        · $<?= aw_h(number_format((float) $eth['exchange_rate'], 2)) ?>/<?= aw_h($native) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rw-stat">
                <div class="label">Token holdings</div>
                <div class="value" id="rw-token-count"><?= (int) ($totals['token_count'] ?? 0) ?></div>
                <div class="hint" id="rw-tokens-usd">priced: <?= aw_h(aw_usd($totals['tokens_usd'] ?? null)) ?></div>
            </div>
            <div class="rw-stat">
                <div class="label">Token drops (in)</div>
                <div class="value" id="rw-drop-count"><?= count($drops) ?></div>
                <div class="hint">incoming ERC-20 transfers</div>
            </div>
        </div>

        <section class="rw-panel">
            <div class="rw-panel-h"><h2>Holdings</h2></div>
            <div class="rw-panel-b" id="rw-holdings">
                <?php if (!$holdings): ?>
                    <p class="rw-empty">No token balances found.</p>
                <?php else: ?>
                    <table class="rw-table">
                        <thead><tr><th>Token</th><th>Balance</th><th>USD</th><th>Contract</th></tr></thead>
                        <tbody>
                        <?php foreach ($holdings as $h): ?>
                            <tr>
                                <td><strong><?= aw_h($h['symbol']) ?></strong><div class="rw-sub"><?= aw_h($h['name']) ?></div></td>
                                <td class="rw-mono"><?= aw_h($h['amount_display']) ?></td>
                                <td><?= aw_h(aw_usd($h['usd'] ?? null)) ?></td>
                                <td><?php if ($h['contract']): ?><a href="<?= aw_h(aw_explorer_token($h['contract'])) ?>" target="_blank" rel="noopener"><?= aw_h(aw_short_addr($h['contract'])) ?></a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

        <section class="rw-panel">
            <div class="rw-panel-h"><h2>Token drops</h2></div>
            <div class="rw-panel-b" id="rw-drops">
                <?php if (!$drops): ?>
                    <p class="rw-empty">No incoming token transfers in the latest page.</p>
                <?php else: ?>
                    <table class="rw-table">
                        <thead><tr><th>When</th><th>Token</th><th>Amount</th><th>From</th><th>Tx</th></tr></thead>
                        <tbody>
                        <?php foreach ($drops as $d): ?>
                            <tr>
                                <td><?= aw_h(aw_time($d['timestamp'] ?? null)) ?></td>
                                <td><span class="rw-chip rw-chip-in">in</span> <strong><?= aw_h($d['symbol']) ?></strong><div class="rw-sub"><?= aw_h($d['name']) ?></div></td>
                                <td class="rw-mono"><?= aw_h($d['amount_display']) ?></td>
                                <td><a href="<?= aw_h(aw_explorer_addr($d['from'])) ?>" target="_blank" rel="noopener"><?= aw_h(aw_short_addr($d['from'])) ?></a></td>
                                <td><a href="<?= aw_h(aw_explorer_tx($d['tx_hash'])) ?>" target="_blank" rel="noopener"><?= aw_h(aw_short_addr($d['tx_hash'])) ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

        <section class="rw-panel">
            <div class="rw-panel-h"><h2>Recent activity</h2></div>
            <div class="rw-panel-b" id="rw-activity">
                <?php if (!$activity): ?>
                    <p class="rw-empty">No recent transactions.</p>
                <?php else: ?>
                    <table class="rw-table">
                        <thead><tr><th>When</th><th>Dir</th><th>Value (<?= aw_h($native) ?>)</th><th>Counterparty</th><th>Tx</th></tr></thead>
                        <tbody>
                        <?php foreach ($activity as $a): ?>
                            <?php
                                $counter = ($a['direction'] ?? '') === 'out' ? ($a['to'] ?? '') : ($a['from'] ?? '');
                                $chip = ($a['direction'] ?? '') === 'in' ? 'rw-chip-in' : 'rw-chip-out';
                            ?>
                            <tr>
                                <td><?= aw_h(aw_time($a['timestamp'] ?? null)) ?></td>
                                <td><span class="rw-chip <?= aw_h($chip) ?>"><?= aw_h($a['direction'] ?? '') ?></span></td>
                                <td class="rw-mono"><?= aw_h($a['value_eth'] ?? '0') ?></td>
                                <td><a href="<?= aw_h(aw_explorer_addr($counter)) ?>" target="_blank" rel="noopener"><?= aw_h(aw_short_addr($counter)) ?></a></td>
                                <td><a href="<?= aw_h(aw_explorer_tx($a['hash'])) ?>" target="_blank" rel="noopener"><?= aw_h(aw_short_addr($a['hash'])) ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <script>
    (function () {
      function usd(n) {
        if (n === null || n === undefined) return '—';
        return '$' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
      function shortAddr(a) {
        if (!a || a.length < 12) return a || '';
        return a.slice(0, 6) + '…' + a.slice(-4);
      }
      function esc(s) {
        return String(s == null ? '' : s)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }
      function time(iso) {
        if (!iso) return '—';
        try {
          const d = new Date(iso);
          return d.toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
        } catch (e) { return iso; }
      }
      function norm(a) { return String(a || '').toLowerCase(); }

      const EX = <?= json_encode($chain['explorer'], JSON_UNESCAPED_SLASHES) ?>;
      const UNI_SLUG = <?= json_encode((string) ($chain['uniswap_chain_slug'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
      const POLL_MS = <?= (int) $watch['poll_ms'] ?>;
      const STORE_KEY = 'airdrop_watch_v1';
      let lastOkAt = Date.now();
      let inFlight = false;
      let lastSnapshot = null;

      const panel = document.getElementById('rw-airdrop');
      const seedToken = norm(panel && panel.dataset.seedToken);
      const seedSymbol = (panel && panel.dataset.seedSymbol) || 'DEMO';
      const seedName = (panel && panel.dataset.seedName) || 'Stand-in demo';
      const ammMode = (panel && panel.dataset.amm) || 'both';

      function loadState() {
        try {
          const raw = localStorage.getItem(STORE_KEY);
          if (raw) {
            const s = JSON.parse(raw);
            if (s && Array.isArray(s.tabs)) return s;
          }
        } catch (e) {}
        return { tabs: [], active: null, pauseNew: false, seenTx: {}, primed: false };
      }

      function saveState() {
        try {
          localStorage.setItem(STORE_KEY, JSON.stringify({
            tabs: state.tabs,
            active: state.active,
            pauseNew: state.pauseNew,
            seenTx: state.seenTx,
            primed: !!state.primed
          }));
        } catch (e) {}
      }

      const state = loadState();
      if (!state.seenTx || typeof state.seenTx !== 'object') state.seenTx = {};
      if (!Array.isArray(state.tabs)) state.tabs = [];
      if (typeof state.primed !== 'boolean') state.primed = false;

      // First visit: seed stand-in demo tab so the UI is not empty.
      if (!state.tabs.length && seedToken) {
        state.tabs.push({
          contract: seedToken,
          symbol: seedSymbol,
          name: seedName,
          firstSeen: new Date().toISOString(),
          seeded: true
        });
        state.active = seedToken;
        saveState();
      }
      if (state.active && !state.tabs.some(t => t.contract === state.active)) {
        state.active = state.tabs.length ? state.tabs[0].contract : null;
      }

      function ageLabel(ms) {
        const s = Math.max(0, Math.floor(ms / 1000));
        if (s < 5) return 'just now';
        if (s < 60) return s + 's ago';
        const m = Math.floor(s / 60);
        return m + 'm ' + (s % 60) + 's ago';
      }

      function tickAge() {
        const live = document.getElementById('rw-live');
        const age = document.getElementById('rw-age');
        if (!live || !age) return;
        const delta = Date.now() - lastOkAt;
        age.textContent = ageLabel(delta);
        live.classList.toggle('is-stale', delta > 25000);
      }

      function updatePauseUi() {
        const btn = document.getElementById('rw-pause-new');
        const mode = document.getElementById('rw-watch-mode');
        if (btn) {
          btn.textContent = state.pauseNew
            ? 'Resume new airdrop monitoring'
            : 'Pause new airdrop monitoring';
          btn.classList.toggle('is-paused', !!state.pauseNew);
          btn.setAttribute('aria-pressed', state.pauseNew ? 'true' : 'false');
        }
        if (mode) {
          mode.textContent = state.pauseNew
            ? 'new drops paused · front tab still live'
            : 'watching for new drops';
          mode.className = 'rw-chip ' + (state.pauseNew ? 'rw-chip-paused' : 'rw-chip-held');
        }
      }

      function renderTabs(flashContract) {
        const el = document.getElementById('rw-ad-tabs');
        const empty = document.getElementById('rw-ad-empty');
        const body = document.getElementById('rw-ad-body');
        if (!el) return;
        if (!state.tabs.length) {
          el.innerHTML = '';
          if (empty) empty.hidden = false;
          if (body) body.hidden = true;
          return;
        }
        if (empty) empty.hidden = true;
        if (body) body.hidden = false;
        el.innerHTML = state.tabs.map(t => {
          const active = t.contract === state.active;
          const neu = flashContract && t.contract === flashContract;
          return '<button type="button" class="rw-tab' + (active ? ' is-active' : '') +
            (neu ? ' is-new' : '') + '" role="tab" aria-selected="' + (active ? 'true' : 'false') +
            '" data-contract="' + esc(t.contract) + '">' + esc(t.symbol || shortAddr(t.contract)) +
            (t.seeded ? ' · demo' : '') + '</button>';
        }).join('');
        el.querySelectorAll('.rw-tab').forEach(btn => {
          btn.addEventListener('click', function () {
            state.active = norm(btn.getAttribute('data-contract'));
            saveState();
            renderTabs();
            renderActiveTab(lastSnapshot);
          });
        });
      }

      function tabStats(data, contract) {
        const c = norm(contract);
        let holding = null;
        (data.holdings || []).forEach(h => {
          if (norm(h.contract) === c) holding = h;
        });
        const drops = (data.token_drops || []).filter(d => norm(d.contract) === c);
        let status = 'waiting';
        let statusLabel = 'No balance yet for this tab';
        if (holding && Number(holding.amount) > 0) {
          status = 'held';
          statusLabel = 'Token is in the wallet';
        } else if (drops.length) {
          status = 'seen_drop';
          statusLabel = 'Drop seen (balance may already be spent)';
        }
        return { holding, drops, status, statusLabel };
      }

      function renderActiveTab(data) {
        if (!data) return;
        const tab = state.tabs.find(t => t.contract === state.active);
        if (!tab) return;
        const stats = tabStats(data, tab.contract);
        const hold = stats.holding;
        if (hold) {
          if (hold.symbol) tab.symbol = hold.symbol;
          if (hold.name) tab.name = hold.name;
        }
        document.getElementById('rw-ad-symbol').textContent = tab.symbol || '???';
        document.getElementById('rw-ad-contract').textContent = shortAddr(tab.contract);
        document.getElementById('rw-ad-status-label').textContent = stats.statusLabel;
        document.getElementById('rw-ad-balance').textContent = (hold && hold.amount_display) || '0';
        document.getElementById('rw-ad-usd').textContent = usd(hold && hold.usd);
        const chip = document.getElementById('rw-ad-status-chip');
        if (chip) {
          chip.textContent = stats.status;
          chip.className = 'rw-chip ' + (stats.status === 'held' ? 'rw-chip-held' : (stats.status === 'waiting' ? 'rw-chip-wait' : 'rw-chip-demo'));
        }
        const ex = document.getElementById('rw-ad-explorer');
        if (ex) ex.href = EX + '/token/' + tab.contract;
        const uni = document.getElementById('rw-ad-uni');
        if (uni) {
          uni.href = 'https://app.uniswap.org/swap?chain=' + (UNI_SLUG || 'robinhood_chain') + '&outputCurrency=' + tab.contract;
          uni.hidden = !(ammMode === 'both' || ammMode === 'uniswap');
        }
        const pons = document.getElementById('rw-ad-pons');
        if (pons) pons.hidden = !(ammMode === 'both' || ammMode === 'pons');

        const adDropsEl = document.getElementById('rw-ad-drops');
        if (adDropsEl) {
          if (!stats.drops.length) {
            adDropsEl.innerHTML = '<p class="rw-empty">No drops yet for this tab.</p>';
          } else {
            adDropsEl.innerHTML = '<table class="rw-table"><thead><tr><th>When</th><th>Amount</th><th>From</th><th>Tx</th></tr></thead><tbody>' +
              stats.drops.map(d => '<tr><td>' + esc(time(d.timestamp)) + '</td><td class="rw-mono">' + esc(d.amount_display) +
                '</td><td><a href="' + esc(EX + '/address/' + d.from) + '" target="_blank" rel="noopener">' +
                esc(shortAddr(d.from)) + '</a></td><td><a href="' + esc(EX + '/tx/' + d.tx_hash) +
                '" target="_blank" rel="noopener">' + esc(shortAddr(d.tx_hash)) + '</a></td></tr>').join('') +
              '</tbody></table>';
          }
        }
      }

      function markDropsSeen(data) {
        (data.token_drops || []).forEach(d => {
          const tx = d.tx_hash || '';
          if (tx) state.seenTx[tx] = 1;
        });
        const keys = Object.keys(state.seenTx);
        if (keys.length > 500) {
          keys.slice(0, keys.length - 400).forEach(k => { delete state.seenTx[k]; });
        }
      }

      function promoteNewAirdrops(data) {
        // First successful poll: baseline existing drops so history does not spawn tabs.
        if (!state.primed) {
          markDropsSeen(data);
          state.primed = true;
          saveState();
          return null;
        }
        // Paused: keep front-tab stats live, do not discover / promote new contracts.
        // Do not mark unseen txs while paused so a mid-pause drop can still promote on resume.
        if (state.pauseNew) return null;

        const known = new Set(state.tabs.map(t => t.contract));
        let promoted = null;
        const ordered = data.token_drops || [];
        for (let i = 0; i < ordered.length; i++) {
          const d = ordered[i];
          const c = norm(d.contract);
          const tx = d.tx_hash || '';
          if (!c || c.length < 10) continue;
          if (tx && state.seenTx[tx]) continue;
          if (tx) state.seenTx[tx] = 1;
          if (known.has(c)) continue;
          const tab = {
            contract: c,
            symbol: d.symbol || shortAddr(c),
            name: d.name || '',
            firstSeen: d.timestamp || new Date().toISOString(),
            seeded: false
          };
          state.tabs.unshift(tab);
          state.active = c;
          known.add(c);
          promoted = c;
          break; // one new front tab per poll; next poll can catch another
        }
        markDropsSeen(data);
        saveState();
        return promoted;
      }

      function render(data) {
        lastSnapshot = data;
        document.getElementById('rw-fetched').textContent = data.fetched_at || '';
        document.getElementById('rw-total-usd').textContent = usd(data.totals && data.totals.usd);
        document.getElementById('rw-eth').textContent = (data.eth && data.eth.amount_display) || '0';
        let ethHint = usd(data.eth && data.eth.usd);
        if (data.eth && data.eth.exchange_rate != null) {
          ethHint += ' · $' + Number(data.eth.exchange_rate).toFixed(2) + '/ETH';
        }
        document.getElementById('rw-eth-usd').textContent = ethHint;
        document.getElementById('rw-token-count').textContent = (data.totals && data.totals.token_count) || 0;
        document.getElementById('rw-tokens-usd').textContent = 'priced: ' + usd(data.totals && data.totals.tokens_usd);
        document.getElementById('rw-drop-count').textContent = (data.token_drops || []).length;

        const holdings = data.holdings || [];
        const hEl = document.getElementById('rw-holdings');
        if (!holdings.length) {
          hEl.innerHTML = '<p class="rw-empty">No token balances found.</p>';
        } else {
          hEl.innerHTML = '<table class="rw-table"><thead><tr><th>Token</th><th>Balance</th><th>USD</th><th>Contract</th></tr></thead><tbody>' +
            holdings.map(h => '<tr><td><strong>' + esc(h.symbol) + '</strong><div class="rw-sub">' + esc(h.name) +
              '</div></td><td class="rw-mono">' + esc(h.amount_display) + '</td><td>' + esc(usd(h.usd)) +
              '</td><td><a href="' + esc(EX + '/token/' + h.contract) + '" target="_blank" rel="noopener">' +
              esc(shortAddr(h.contract)) + '</a></td></tr>').join('') + '</tbody></table>';
        }

        const drops = data.token_drops || [];
        const dEl = document.getElementById('rw-drops');
        if (!drops.length) {
          dEl.innerHTML = '<p class="rw-empty">No incoming token transfers in the latest page.</p>';
        } else {
          dEl.innerHTML = '<table class="rw-table"><thead><tr><th>When</th><th>Token</th><th>Amount</th><th>From</th><th>Tx</th></tr></thead><tbody>' +
            drops.map(d => '<tr><td>' + esc(time(d.timestamp)) + '</td><td><span class="rw-chip rw-chip-in">in</span> <strong>' +
              esc(d.symbol) + '</strong><div class="rw-sub">' + esc(d.name) + '</div></td><td class="rw-mono">' +
              esc(d.amount_display) + '</td><td><a href="' + esc(EX + '/address/' + d.from) +
              '" target="_blank" rel="noopener">' + esc(shortAddr(d.from)) + '</a></td><td><a href="' +
              esc(EX + '/tx/' + d.tx_hash) + '" target="_blank" rel="noopener">' + esc(shortAddr(d.tx_hash)) +
              '</a></td></tr>').join('') + '</tbody></table>';
        }

        const acts = data.activity || [];
        const aEl = document.getElementById('rw-activity');
        if (!acts.length) {
          aEl.innerHTML = '<p class="rw-empty">No recent transactions.</p>';
        } else {
          aEl.innerHTML = '<table class="rw-table"><thead><tr><th>When</th><th>Dir</th><th>Value (ETH)</th><th>Counterparty</th><th>Tx</th></tr></thead><tbody>' +
            acts.map(a => {
              const counter = a.direction === 'out' ? a.to : a.from;
              const chip = a.direction === 'in' ? 'rw-chip-in' : 'rw-chip-out';
              return '<tr><td>' + esc(time(a.timestamp)) + '</td><td><span class="rw-chip ' + chip + '">' +
                esc(a.direction) + '</span></td><td class="rw-mono">' + esc(a.value_eth) +
                '</td><td><a href="' + esc(EX + '/address/' + counter) + '" target="_blank" rel="noopener">' +
                esc(shortAddr(counter)) + '</a></td><td><a href="' + esc(EX + '/tx/' + a.hash) +
                '" target="_blank" rel="noopener">' + esc(shortAddr(a.hash)) + '</a></td></tr>';
            }).join('') + '</tbody></table>';
        }

        const promoted = promoteNewAirdrops(data);
        renderTabs(promoted);
        renderActiveTab(data);
        if (promoted && panel) {
          panel.classList.add('is-flash');
          setTimeout(() => panel.classList.remove('is-flash'), 2500);
        }
      }

      async function refresh() {
        if (inFlight) return;
        inFlight = true;
        const live = document.getElementById('rw-live');
        try {
          const res = await fetch('?ajax=1', { credentials: 'same-origin', cache: 'no-store' });
          if (res.status === 401 || res.redirected) {
            location.reload();
            return;
          }
          if (!res.ok) {
            if (live) live.classList.add('is-error');
            return;
          }
          const data = await res.json();
          render(data);
          lastOkAt = Date.now();
          if (live) live.classList.remove('is-error', 'is-stale');
          tickAge();
        } catch (e) {
          if (live) live.classList.add('is-error');
        } finally {
          inFlight = false;
        }
      }

      document.getElementById('rw-refresh').addEventListener('click', refresh);
      document.getElementById('rw-pause-new').addEventListener('click', function () {
        state.pauseNew = !state.pauseNew;
        saveState();
        updatePauseUi();
      });
      updatePauseUi();
      renderTabs();
      setInterval(refresh, POLL_MS);
      setInterval(tickAge, 1000);
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') refresh();
      });
      tickAge();
      // Initial paint from server-embedded first snapshot via immediate poll
      refresh();
    })();
    
    </script>
<?php endif; ?>
</body>
</html>
