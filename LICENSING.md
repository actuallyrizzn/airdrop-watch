# Dual licensing — Airdrop Watch

This project uses **two licenses** depending on the material:

| Material | License | Files (typical) |
|----------|---------|-----------------|
| **Software / source code** | **GNU Affero General Public License v3.0 or later** (AGPL-3.0-or-later) | `app/**/*.php`, `app/**/*.js` (if any), `proxy/**/*.py`, CSS that is part of the running app (`app/public/css/`), shell/systemd examples that implement the service |
| **Documentation, examples, design notes, and other non-code** | **Creative Commons Attribution-ShareAlike 4.0 International** (CC-BY-SA-4.0) | `README.md`, `docs/**`, `CHANGELOG.md`, `config/*.example.*`, narrative content |

Full legal texts:

- [`licenses/AGPL-3.0.txt`](licenses/AGPL-3.0.txt)
- [`licenses/CC-BY-SA-4.0.txt`](licenses/CC-BY-SA-4.0.txt)

The root [`LICENSE`](LICENSE) file is a short pointer. SPDX in source headers may say `AGPL-3.0-or-later` for code files.

## Why AGPL for the code

Airdrop Watch is meant to stay free even when deployed as a network service (a password page you leave open). AGPL requires that people who run a modified version as a service offer corresponding source to users of that service.

## Why CC-BY-SA for docs

Docs and architecture write-ups should be easy to remix with attribution and share-alike, without forcing documentation derivatives under AGPL.

## Combined works

If you distribute a package that mixes code and docs, apply each license to its portion. When in doubt, treat executable/config-driving source as AGPL and prose as CC-BY-SA.

## Copyright

Copyright (c) 2026 Mark Hopkins / Decision Science Corp contributors, unless a file says otherwise.
