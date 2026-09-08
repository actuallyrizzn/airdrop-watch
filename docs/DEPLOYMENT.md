# Deployment

## Requirements

- **PHP 8.1+** with `curl`; `gmp` recommended for large balances.
- A web server pointing the vhost document root at **`app/public/`**.
- Writable? Not required for default setup (no SQLite).
- Optional: **Python 3.10+**, **Docker** (FlareSolverr), a second host that can reach Cloudflare-gated Blockscout.

## App only (direct explorer)

1. Clone the repo.
2. `cp config/chain.example.json config/chain.json` and edit.
3. `cp config/watch.example.env config/watch.env` and set address + password hash.
4. Serve `app/public/`.
5. Confirm `GET /` shows the login page; after login, holdings populate.

If explorer calls fail with HTTP 403/challenge pages, use the proxy path below.

## App + FlareSolverr proxy

### FlareSolverr

On the proxy host (loopback only):

```bash
docker run -d --name flaresolverr --shm-size=2g \
  -p 127.0.0.1:8191:8191 \
  -e LOG_LEVEL=info \
  ghcr.io/flaresolverr/flaresolverr:latest
```

Do not publish `8191` on `0.0.0.0`. Prefer stopping the container when idle.

### Proxy service

```bash
cp proxy/.env.example proxy/.env   # set AW_PROXY_SECRET, AW_WATCH_ADDRESS, AW_API_BASE
python3 proxy/airdrop_watch_proxy.py
# or install proxy/airdrop_watch_proxy.service.example as a systemd unit
```

Health: `curl -sS http://127.0.0.1:8788/health`

Snapshot (auth):

```bash
curl -sS -H "X-AW-Proxy-Key: $AW_PROXY_SECRET" http://127.0.0.1:8788/snapshot | head
```

Firewall the proxy port to the app host (or terminate TLS on a reverse proxy with mutual auth).

### Wire the app

In `config/watch.env`:

```
AW_PROXY_URL=http://PROXY_HOST:8788
AW_PROXY_KEY=same-as-AW_PROXY_SECRET
```

## Password hash

```bash
php -r "echo password_hash('your-passphrase', PASSWORD_DEFAULT), PHP_EOL;"
```

Put the hash in `AW_PASSWORD_HASH`. Never commit `watch.env`.

## Security checklist

- [ ] HTTPS on the app vhost
- [ ] Strong page password; rotate if shared
- [ ] Proxy secret ≠ page password; proxy not world-open
- [ ] FlareSolverr bound to localhost
- [ ] `robots` noindex is set in the HTML; still do not rely on obscurity alone
- [ ] AGPL compliance if you run a modified network service for others (offer corresponding source)

## Local smoke without nginx

From `app/public` (ephemeral only):

```bash
php -S 127.0.0.1:8765
```

Stop the server when finished; do not leave it running unattended in shared environments.
