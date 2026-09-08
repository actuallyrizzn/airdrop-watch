# Architecture

## Problem

You expect a token to land (or you are rehearsing with a stand-in). You want:

1. Continuous visibility into **one** wallet on **one** chain.
2. Immediate awareness of **new incoming ERC-20s**.
3. Balance / USD for the token under focus without babysitting an explorer UI.
4. Optional **pause** on “new airdrop discovery” without stopping live updates for the current focus.

## Components

### 1. Browser dashboard (`app/public/`)

- Password session (24h cookie params by default).
- Server-rendered first paint, then **AJAX poll** (`GET ?ajax=1`) on an interval (`AW_POLL_MS`, default 10s).
- **Airdrop watch panel**: client-side tabs in `localStorage`.
  - First successful poll **baselines** existing drop tx hashes so history does not spawn tabs.
  - Later polls: if not paused, the newest unseen incoming contract becomes the **front tab**.
  - Pause button: no new tabs; foreground stats still refresh.
- Holdings, all incoming drops, and recent native txs update in place.

### 2. PHP snapshot builder (`includes/lib.php`)

Normalizes explorer + RPC into a stable JSON shape:

- `eth` / native balance + USD (explorer rate or Llama/CoinGecko fallback for ETH-priced natives)
- `holdings[]`, `token_drops[]`, `activity[]`
- `chain` metadata from `config/chain.json`
- `airdrop_demo` seed-token status (optional stand-in while the real mint is unknown)

Prefers **proxy** when `AW_PROXY_URL` + `AW_PROXY_KEY` are set; otherwise calls Blockscout API v2 directly from the app host.

### 3. Snapshot proxy (`proxy/airdrop_watch_proxy.py`)

For hosts that cannot pass Cloudflare (or similar) on the explorer:

1. App → `GET /snapshot` with `X-AW-Proxy-Key`.
2. Proxy → FlareSolverr `request.get` for four Blockscout paths (address, token-balances, token-transfers, transactions).
3. **In-memory cache** (`AW_CACHE_TTL`, default 8s) collapses concurrent UI polls.

Bind carefully in production (firewall / reverse proxy). Example unit file: `proxy/airdrop_watch_proxy.service.example`.

### 4. Configuration

| Piece | Role |
|-------|------|
| `config/chain.json` | Chain identity, explorer, API, RPC, AMM link flags |
| `config/watch.env` | Address, password hash, seed token, proxy credentials, poll ms |

Secrets stay out of git (see `.gitignore`).

## Trust & rate limits

- One open browser tab ≈ one app poll per interval; proxy cache means upstream explorer traffic is closer to **one batch per TTL**, not per tab.
- FlareSolverr is heavy; stop the container when idle if your ops policy requires it.
- Native USD fallbacks hit public APIs sparingly (only when explorer has no rate).

## Non-goals (v0.1)

- Custodial wallets, signing, or trading execution.
- Indexer-grade historical backfill (we use the explorer’s latest page).
- Multi-chain in one process (roadmap: more chain support / presets).
