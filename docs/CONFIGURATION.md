# Configuration

## Files

| File | Required | Purpose |
|------|----------|---------|
| `config/chain.json` | Yes (or fall back to example) | Chain + explorer + RPC |
| `config/watch.env` | Yes for a real deploy | Address, auth, optional proxy/seed |

Environment variables already present in the process override values from `watch.env`.

## `chain.json` fields

| Key | Meaning |
|-----|---------|
| `id` | EVM chain id |
| `name` | Display name |
| `native_symbol` | e.g. `ETH` |
| `explorer` | Browser base URL (no trailing slash required) |
| `api_base` | Blockscout-compatible API v2 base ending in `/api/v2` |
| `rpc` | JSON-RPC HTTPS endpoint |
| `uniswap_chain_slug` | Uniswap web app `chain=` slug if applicable |
| `amm_links.uniswap` | Show Uniswap button |
| `amm_links.pons` | Show Pons button |
| `amm_links.pons_url` | Pons (or other AMM) URL |

See [CHAINS.md](CHAINS.md) for compatibility notes and the roadmap for more chains.

## `watch.env` variables

| Variable | Meaning |
|----------|---------|
| `AW_WATCH_ADDRESS` | `0x` wallet to monitor |
| `AW_PASSWORD_HASH` | bcrypt hash for the login form |
| `AW_SEED_TOKEN` | Optional contract for the initial demo/seed tab |
| `AW_SEED_SYMBOL` / `AW_SEED_LABEL` | Labels for that seed tab |
| `AW_AMM` | `both` \| `uniswap` \| `pons` \| `none` |
| `AW_PROXY_URL` | Proxy base URL (no path) |
| `AW_PROXY_KEY` | Must match proxy `AW_PROXY_SECRET` |
| `AW_POLL_MS` | Browser poll interval (min 3000) |
| `AW_CHAIN_FILE` | Optional absolute path to chain JSON |

## Proxy environment

| Variable | Meaning |
|----------|---------|
| `AW_PROXY_SECRET` | Required shared secret |
| `AW_WATCH_ADDRESS` | Same wallet the app watches |
| `AW_API_BASE` | Same as chain `api_base` |
| `AW_PROXY_PORT` | Listen port (default `8788`) |
| `AW_CACHE_TTL` | Snapshot cache seconds (default `8`) |
| `FLARESOLVERR_URL` | Default `http://127.0.0.1:8191/v1` |

## UI behavior knobs (client)

Stored in `localStorage` key `airdrop_watch_v1`:

- `tabs`, `active`, `pauseNew`, `seenTx`, `primed`

Clear site data for that origin to reset tabs.
