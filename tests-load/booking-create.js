// Authenticated write path — cart + booking creation.
//
// Logs in once per VU at setup, then creates carts + bookings against
// a known offer/hotel ID. Tests the slowest hot path: validation +
// availability check + commission split + payment intent stub +
// audit log write.

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'https://staging.zulu.am';
const SCENARIO = __ENV.SCENARIO || 'smoke';
const TEST_EMAIL = __ENV.TEST_EMAIL;
const TEST_PASSWORD = __ENV.TEST_PASSWORD;
const OFFER_ID = __ENV.OFFER_ID || '1';

const scenarios = {
  smoke: { executor: 'constant-vus', vus: 1, duration: '30s' },
  load: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '1m', target: 5 },
      { duration: '2m', target: 5 },
      { duration: '1m', target: 0 },
    ],
  },
  stress: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '2m', target: 20 },
      { duration: '3m', target: 20 },
      { duration: '1m', target: 0 },
    ],
  },
};

export const options = {
  scenarios: { run: scenarios[SCENARIO] || scenarios.smoke },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<1500'],
  },
};

// One login per VU at setup; the token is cached in default-export scope.
export function setup() {
  if (!TEST_EMAIL || !TEST_PASSWORD) {
    console.log('Set TEST_EMAIL + TEST_PASSWORD env vars to run booking-create.js');
    return { token: null };
  }
  const res = http.post(
    `${BASE_URL}/api/login`,
    JSON.stringify({ email: TEST_EMAIL, password: TEST_PASSWORD }),
    { headers: { 'Content-Type': 'application/json' } }
  );
  if (res.status !== 200) {
    console.error(`Setup login failed: HTTP ${res.status} — ${res.body.slice(0, 200)}`);
    return { token: null };
  }
  return { token: res.json().data.token };
}

export default function (data) {
  if (!data?.token) {
    sleep(1);
    return;
  }
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${data.token}`,
  };

  // Light booking creation — exact payload depends on offer type. This is
  // illustrative; tweak `payload` for the offer type seeded in staging.
  const payload = JSON.stringify({
    offer_id: Number(OFFER_ID),
    start_date: new Date(Date.now() + 7 * 86400_000).toISOString().slice(0, 10),
    end_date: new Date(Date.now() + 10 * 86400_000).toISOString().slice(0, 10),
    adults: 2,
    children: 0,
  });

  const res = http.post(`${BASE_URL}/api/bookings`, payload, {
    headers,
    tags: { endpoint: 'booking-create' },
  });
  check(res, {
    'status 201 or 422 (validation, seed dependent)': (r) => r.status === 201 || r.status === 422,
  });
  sleep(Math.random() * 2);
}
