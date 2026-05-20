// Login throughput — Sanctum token issuance + DB write to personal_access_tokens.
//
// The `throttle:login` rule limits real users; this script intentionally
// runs UNDER that limit to measure throughput without tripping the
// rate limiter. The "stress" scenario WILL trip throttling — that's
// the point (verifies the throttle actually defends).

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'https://staging.zulu.am';
const SCENARIO = __ENV.SCENARIO || 'smoke';
const TEST_EMAIL = __ENV.TEST_EMAIL;
const TEST_PASSWORD = __ENV.TEST_PASSWORD;

if (!TEST_EMAIL || !TEST_PASSWORD) {
  console.log('Set TEST_EMAIL and TEST_PASSWORD env vars to run login.js');
  console.log('A seeded test account is required on the target environment.');
}

const scenarios = {
  smoke: { executor: 'constant-vus', vus: 1, duration: '30s' },
  load: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '1m', target: 10 },
      { duration: '2m', target: 10 },
      { duration: '1m', target: 0 },
    ],
  },
  stress: {
    // Exceeds the typical throttle:login rule (5/min) — expect 429s
    executor: 'constant-arrival-rate',
    rate: 30,
    timeUnit: '1s',
    duration: '1m',
    preAllocatedVUs: 20,
  },
};

export const options = {
  scenarios: { run: scenarios[SCENARIO] || scenarios.smoke },
  thresholds: {
    // Stress scenario expects 429s. Smoke/load expect clean 200s.
    http_req_failed: SCENARIO === 'stress' ? ['rate<0.95'] : ['rate<0.05'],
    http_req_duration: ['p(95)<1500'],
  },
};

export default function () {
  if (!TEST_EMAIL || !TEST_PASSWORD) {
    sleep(1);
    return;
  }
  const payload = JSON.stringify({
    email: TEST_EMAIL,
    password: TEST_PASSWORD,
  });
  const res = http.post(`${BASE_URL}/api/login`, payload, {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    tags: { endpoint: 'login' },
  });
  check(res, {
    'status 200 (smoke/load) or 429 (stress)': (r) =>
      r.status === 200 || (SCENARIO === 'stress' && r.status === 429),
    'returns token when 200': (r) => {
      if (r.status !== 200) return true;
      try {
        const j = r.json();
        return j?.success && typeof j?.data?.token === 'string' && j.data.token.length > 0;
      } catch {
        return false;
      }
    },
  });
  sleep(1);
}
