# Contributing

1. Open an issue or PR against `main`.
2. Keep secrets out of the tree (`config/watch.env`, `proxy/.env`, `*.pass`).
3. Code changes: AGPL-3.0-or-later. Doc changes: CC-BY-SA-4.0.
4. Run `php -l` on touched PHP and `python3 -m py_compile` on touched proxy Python before opening a PR.
5. Prefer small, documented chain presets under `config/` when adding explorer support.
