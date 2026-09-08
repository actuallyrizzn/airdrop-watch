# Chains

## Current support (v0.1)

Airdrop Watch speaks **Blockscout API v2**-shaped explorers plus a standard **EVM JSON-RPC** `eth_getBalance`.

The bundled example targets **Robinhood Chain** (chain id **4663**):

- Explorer: `https://robinhoodchain.blockscout.com`
- API: `…/api/v2`
- RPC: `https://rpc.mainnet.chain.robinhood.com`

That explorer is often **Cloudflare-protected**; many cloud app hosts need the **FlareSolverr proxy**. Residential or allowlisted IPs may call Blockscout directly.

## Adding another chain (manual)

1. Confirm the explorer exposes Blockscout-compatible:
   - `GET /api/v2/addresses/{addr}`
   - `GET /api/v2/addresses/{addr}/token-balances`
   - `GET /api/v2/addresses/{addr}/token-transfers?type=ERC-20`
   - `GET /api/v2/addresses/{addr}/transactions`
2. Copy `config/chain.example.json` → `config/chain.json` and fill `id`, `name`, `explorer`, `api_base`, `rpc`.
3. Set AMM link flags / Uniswap slug if you want trade buttons.
4. Point `AW_API_BASE` on the proxy at the same `api_base` if you use the proxy.
5. Restart proxy (if any) and reload the app.

Token list field names (`token.address_hash`, transfer `total.value`, etc.) follow Blockscout’s common JSON. Explorers that diverge will need an adapter (roadmap).

## Roadmap — more chain support

Planned work (see also README):

- Preset pack for additional L2s / Blockscout instances.
- Explicit adapter interface when an explorer is “almost Blockscout” but not quite.
- Per-chain native USD pricing beyond the ETH Llama/CoinGecko fallback.
- Optional multi-chain UI (one install, multiple `chain.json` profiles).

Contributions that add a tested `config/chains/<name>.json` plus a short note in this file are welcome under the project licenses.
