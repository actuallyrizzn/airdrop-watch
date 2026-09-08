# Airdrop Watch

Password-gated **live wallet monitor** for the stretch of time when you are **expecting an airdrop** (or testing with a stand-in token): leave a tab open, watch native + ERC-20 balances and USD when priced, see incoming drops the moment they hit the explorer, and keep a **tabbed “airdrop watch” panel** focused on the token you care about.

When a **new** incoming token appears, it becomes the **foreground tab** and older tabs slide over. **Pause new airdrop monitoring** stops discovering new tabs but **keeps polling** the open tab’s balance and drop list.

## Why this exists

Explorers and dashboards are fine for browsing. They are awkward when you want a **second-screen vigil**: one address, one chain, continuous refresh, flash on novelty, trade links ready. That is the use case.

## Architecture (short)

```
┌─────────────┐   poll ~10s    ┌──────────────────┐
│  Browser UI │ ─────────────► │  PHP app (auth)  │
│  tabs+live  │ ◄───────────── │  aw_fetch_…      │
└─────────────┘   JSON snapshot└────────┬─────────┘
                                        │
                    optional CF bypass  │  or direct
                                        ▼
                              ┌───────────────────┐
                              │ Snapshot proxy    │
                              │ + FlareSolverr    │
                              └─────────┬─────────┘
                                        ▼
                              Blockscout API v2 + chain RPC
```

- **App** (`app/public/`): session password, HTML dashboard, `?ajax=1` JSON for live updates.
- **Chain config** (`config/chain.json`): explorer, API base, RPC, AMM link preferences — swap the file for another Blockscout-shaped chain (more chains on the roadmap).
- **Proxy** (`proxy/`): optional authenticated service for Cloudflare-blocked explorers; short TTL cache so one open page does not hammer upstream.

Details: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) · deploy: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) · config: [docs/CONFIGURATION.md](docs/CONFIGURATION.md) · chains: [docs/CHAINS.md](docs/CHAINS.md).

## Quick start

1. Copy `config/chain.example.json` → `config/chain.json` (edit for your chain).
2. Copy `config/watch.example.env` → `config/watch.env` and set:
   - `AW_WATCH_ADDRESS`
   - `AW_PASSWORD_HASH` (`php -r "echo password_hash('…', PASSWORD_DEFAULT), PHP_EOL;"`)
   - optional seed token + proxy URL/key
3. Point a PHP 8.1+ docroot at `app/public/` (with `curl` and preferably `gmp`).
4. If Blockscout is CF-blocked from that host, run `proxy/airdrop_watch_proxy.py` behind FlareSolverr (see Deployment).
5. Open the site, log in, leave it open.

## Roadmap

- **More chain support** — first-class presets and adapters beyond the current Blockscout v2 + EVM RPC shape (additional L2s / explorers, clearer per-chain AMM link maps).
- Multi-address / multi-watch profiles from one install.
- Optional notifications (webhook / Telegram) when a new tab is promoted.
- Hardening: bind proxy to loopback + reverse proxy only; rate-limit auth.
- Dex / indexer price fallbacks when explorers omit `exchange_rate` (DexScreener already wired in v0.1.1).

## License

**Dual license** — see [LICENSING.md](LICENSING.md):

- **Code:** [AGPL-3.0-or-later](licenses/AGPL-3.0.txt)
- **Docs and other non-code:** [CC-BY-SA-4.0](licenses/CC-BY-SA-4.0.txt)

## Status

v0.1.0 — extracted and generalized from a production Robinhood Chain watch page. Useful today; chain pack is intentionally small until the roadmap item above lands.
