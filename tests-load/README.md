# ZULU — Load testing (k6)

These scripts use [Grafana k6](https://k6.io) to stress-test the public ZULU API. They are **not** part of the PHPUnit suite — k6 runs in its own JS runtime against a deployed environment (staging or prod).

## Why they exist

Roadmap F4 (`docs/roadmaps/zulu-roadmap-2026-05-20.md`): before any meaningful traffic spike (a press feature, a paid campaign), we should know where the bottlenecks are. PHPUnit catches correctness; k6 catches capacity.

## What's covered

| Script | What it stresses | Target endpoint |
|---|---|---|
| `discovery-search.js` | Catalog read path — the hottest endpoint in prod. PG visibility filters + Eloquent eager-loads. | `GET /api/discovery/search` |
| `hotel-detail.js` | Single-item read with location join + media URLs. | `GET /api/discovery/{id}` |
| `login.js` | Sanctum token issuance + DB write for `personal_access_tokens`. Tests the throttle rule too. | `POST /api/login` |
| `booking-create.js` | Authenticated write path. Mints a token via `login.js` flow first, then creates a cart + booking. | `POST /api/bookings` |

## Prerequisites

```bash
# Install k6 (Windows via Chocolatey, macOS via Homebrew, Linux via package manager)
choco install k6      # Windows
brew install k6       # macOS
# Linux: see https://grafana.com/docs/k6/latest/set-up/install-k6/
```

## Running a script

By default scripts target **staging** (https://staging.zulu.am). Override the host with the `BASE_URL` env var. **Never point these at prod without a maintenance window** — they generate enough load to disrupt real users.

```bash
# Smoke test (small load — safe to run anytime against staging)
BASE_URL=https://staging.zulu.am k6 run discovery-search.js

# Medium load — only run against staging or a dedicated load-test environment
SCENARIO=load k6 run discovery-search.js

# Stress test — find the breaking point
SCENARIO=stress k6 run discovery-search.js
```

Each script defines three scenarios via the `SCENARIO` env var:
- `smoke` (default): 1 VU for 30s — sanity check the endpoint is reachable
- `load`: ramps to 50 VUs over 2 minutes, holds for 3 minutes, ramps down — realistic peak
- `stress`: ramps to 200 VUs over 5 minutes — pushes for the breaking point

## Pass/fail thresholds

Every script asserts:
- `http_req_failed` rate < 1%
- `http_req_duration` p95 < 800ms (read endpoints) / < 1500ms (write endpoints)

k6 exits non-zero if any threshold breaches — useful for automated runs (e.g. `npm run test:load` from a future CI workflow).

## Capturing results

For a stored baseline:

```bash
k6 run --out json=runs/$(date +%Y%m%d-%H%M%S).json discovery-search.js
```

For sending to a Prometheus/Grafana stack later, see https://grafana.com/docs/k6/latest/results-output/.

## Caveats

- The `login.js` and `booking-create.js` scripts require a seeded test account on the target environment. Set `TEST_EMAIL` and `TEST_PASSWORD` env vars before running, otherwise they'll skip with a friendly error.
- The discovery filters use realistic but synthetic combinations — they'll hit the DB index path, not the cache hot path. Real users with frequently-repeated filter combos will see better numbers in prod.
- These scripts do not currently exercise rate-limited endpoints under their throttle (login is `throttle:login`). For throttle-aware tests, lower the VU count manually.
