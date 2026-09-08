#!/usr/bin/env python3
"""
Airdrop Watch — authenticated Blockscout snapshot proxy.

Some hosts cannot reach Cloudflare-protected Blockscout instances. Run this
service on a box that can (typically with FlareSolverr), then point the PHP
app at AW_PROXY_URL + AW_PROXY_KEY.

Environment:
  AW_PROXY_SECRET   (required) shared secret; sent as X-AW-Proxy-Key
  AW_WATCH_ADDRESS  (required) 0x wallet
  AW_API_BASE       Blockscout API v2 base (…/api/v2)
  AW_PROXY_PORT     listen port (default 8788)
  AW_CACHE_TTL      seconds (default 8)
  FLARESOLVERR_URL  default http://127.0.0.1:8191/v1

License: GNU Affero General Public License v3.0 or later (see LICENSE / licenses/).
"""
from __future__ import annotations

import json
import os
import re
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

ADDR = os.environ.get("AW_WATCH_ADDRESS", "").strip().lower()
SECRET = os.environ.get("AW_PROXY_SECRET", "").strip()
PORT = int(os.environ.get("AW_PROXY_PORT", "8788"))
FS_URL = os.environ.get("FLARESOLVERR_URL", "http://127.0.0.1:8191/v1")
API = os.environ.get(
    "AW_API_BASE", "https://robinhoodchain.blockscout.com/api/v2"
).rstrip("/")
CACHE_TTL = int(os.environ.get("AW_CACHE_TTL", "8"))

_CACHE: dict = {"at": 0.0, "data": None}


def fs_get(url: str) -> tuple[int, object]:
    body = json.dumps(
        {"cmd": "request.get", "url": url, "maxTimeout": 120000}
    ).encode()
    req = urllib.request.Request(
        FS_URL,
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=180) as resp:
        payload = json.load(resp)
    if payload.get("status") != "ok":
        raise RuntimeError(payload.get("message") or "flaresolverr failed")
    sol = payload.get("solution") or {}
    http_status = int(sol.get("status") or 0)
    html = sol.get("response") or ""
    m = re.search(r"<pre>(\{.*\}|\[.*\])</pre>", html, re.S)
    if m:
        return http_status, json.loads(m.group(1))
    stripped = html.strip()
    if stripped.startswith("{") or stripped.startswith("["):
        try:
            return http_status, json.loads(stripped)
        except Exception:
            pass
    if http_status == 200 and ("transactions" in url or "token-transfers" in url):
        return http_status, {"items": [], "next_page_params": None}
    raise RuntimeError(f"non-json flaresolverr body http={http_status}")


def snapshot() -> dict:
    now = time.time()
    if _CACHE["data"] is not None and (now - _CACHE["at"]) < CACHE_TTL:
        cached = dict(_CACHE["data"])
        cached["cache_hit"] = True
        return cached

    errors: list[str] = []
    base = f"{API}/addresses/{ADDR}"

    def j(path: str):
        url = base + path if path else base
        try:
            status, data = fs_get(url)
            if status < 200 or status >= 300:
                errors.append(f"{path or '/'}: HTTP {status}")
                return None
            return data
        except Exception as e:
            errors.append(f"{path or '/'}: {e}")
            return None

    addr = j("") or {}
    tokens = j("/token-balances") or []
    xfer = j("/token-transfers?type=ERC-20") or {}
    txs = j("/transactions") or {}
    data = {
        "ok": len(errors) == 0,
        "errors": errors,
        "address": addr,
        "token_balances": tokens if isinstance(tokens, list) else [],
        "token_transfers": xfer if isinstance(xfer, dict) else {},
        "transactions": txs if isinstance(txs, dict) else {},
        "source": "airdrop-watch-flaresolverr-proxy",
        "cache_hit": False,
        "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "watch_address": ADDR,
        "api_base": API,
    }
    _CACHE["at"] = now
    _CACHE["data"] = data
    return data


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        print("[%s] %s" % (self.log_date_time_string(), fmt % args))

    def _auth_ok(self) -> bool:
        if not SECRET:
            return False
        auth = self.headers.get("X-AW-Proxy-Key", "") or self.headers.get(
            "X-RH-Proxy-Key", ""
        )
        q = parse_qs(urlparse(self.path).query)
        key = auth or (q.get("key") or [""])[0]
        return key == SECRET

    def _json(self, code: int, obj: dict):
        raw = json.dumps(obj, separators=(",", ":")).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path in ("/health", "/"):
            self._json(200, {"status": "ok", "service": "airdrop-watch-proxy"})
            return
        if not self._auth_ok():
            self._json(401, {"error": "unauthorized"})
            return
        if parsed.path != "/snapshot":
            self._json(404, {"error": "not found"})
            return
        try:
            data = snapshot()
            code = 200 if (data.get("address") or data.get("token_balances")) else 502
            if data.get("ok"):
                code = 200
            self._json(code, data)
        except Exception as e:
            self._json(500, {"error": str(e)})


def main():
    if not SECRET:
        raise SystemExit("AW_PROXY_SECRET required")
    if not re.fullmatch(r"0x[0-9a-f]{40}", ADDR):
        raise SystemExit("bad AW_WATCH_ADDRESS")
    if not API:
        raise SystemExit("AW_API_BASE required")
    httpd = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    print(f"airdrop-watch proxy on 0.0.0.0:{PORT} for {ADDR} via {FS_URL} api={API}")
    httpd.serve_forever()


if __name__ == "__main__":
    main()
