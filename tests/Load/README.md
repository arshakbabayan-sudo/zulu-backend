# ZULU — Load Tests (k6)

**Purpose.** Catch performance regressions before they hit production
users. Run before any release that changes the hot path (search, login,
booking-create) and on a quarterly cadence regardless.

**Tool.** [k6](https://k6.io/) — pure-JS test scripts that spin up
synthetic load against the live API.

**Install.**
```bash
# Mac
brew install k6

# Windows (winget)
winget install k6 --source winget

# Docker (no install)
docker run --rm -i grafana/k6 run - < script.js
```

---

## Layout

```
tests/Load/
  smoke.js              ← 1 user, 30 seconds. Pre-flight sanity check.
  api-search.js         ← 50 users, 2 min. Public hotel search endpoint.
  api-login.js          ← 20 users, 1 min. Auth burst.
  README.md             ← you are here
```

---

## Running

### Smoke (always run first)
```bash
k6 run tests/Load/smoke.js
```
1 user, 30 seconds, hits `/api/health/deep`. Confirms the target URL
is reachable + the test runner is wired up before you spend 10
minutes on a real load run.

### Search load
```bash
BASE_URL=https://api.zulu.am k6 run tests/Load/api-search.js
```
50 virtual users ramp up over 30s, hold for 90s, ramp down 30s. The
search endpoint is the most-hit public endpoint; this catches DB
index regressions and N+1 query regressions immediately.

### Auth load
```bash
BASE_URL=https://api.zulu.am \
  E2E_TEST_USER=loadtest@zulu.am \
  E2E_TEST_PASS='set-in-env' \
  k6 run tests/Load/api-login.js
```
20 users login concurrently for 60 seconds. Catches Sanctum token
issuance latency + DB write contention on `personal_access_tokens`.

---

## Thresholds

Each script enforces SLOs via k6's `thresholds`:

| Endpoint | p95 latency | Error rate | Notes |
|---|---|---|---|
| `/api/health/deep` | < 200ms | < 1% | Smoke run |
| `/api/hotels?lang=hy` | < 1000ms | < 1% | Public, no auth |
| `/api/auth/login` | < 500ms | < 2% | Token issuance |

If any threshold fails the script exits non-zero — wire into CI as
needed.

---

## NOT run automatically

These load tests are NOT in the GitHub Actions matrix because:

1. They generate real traffic against production (or staging). Running
   them on every push would saturate the box.
2. Auth + DB writes leave artifacts (token rows, login audit logs).
3. The right cadence is "before a release that touches the hot path"
   + quarterly — not "per commit".

When the staging environment exists, move these to a nightly run
against staging.

---

## Adding a new endpoint

1. Copy `api-search.js` to `api-<name>.js`.
2. Change `URL`, `THRESHOLDS`, and the `default function` body.
3. Document the SLO in this README's threshold table.
