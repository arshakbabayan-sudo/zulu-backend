// Hotel detail — second-most-trafficked read endpoint.
// Tests the GET /api/discovery/{id} path which joins location + media.

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'https://staging.zulu.am';
const SCENARIO = __ENV.SCENARIO || 'smoke';

// Hotel IDs to probe. In staging these need to be seeded; in prod a few
// real IDs would be substituted by the operator running the test.
const HOTEL_IDS_RAW = __ENV.HOTEL_IDS || '1,2,3,4,5';
const HOTEL_IDS = HOTEL_IDS_RAW.split(',').map((s) => s.trim()).filter(Boolean);

const scenarios = {
  smoke: { executor: 'constant-vus', vus: 1, duration: '30s' },
  load: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '2m', target: 50 },
      { duration: '3m', target: 50 },
      { duration: '1m', target: 0 },
    ],
  },
  stress: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '2m', target: 100 },
      { duration: '3m', target: 200 },
      { duration: '2m', target: 0 },
    ],
  },
};

export const options = {
  scenarios: { run: scenarios[SCENARIO] || scenarios.smoke },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<800'],
  },
};

export default function () {
  const id = HOTEL_IDS[Math.floor(Math.random() * HOTEL_IDS.length)];
  const res = http.get(`${BASE_URL}/api/discovery/${id}`, {
    headers: { Accept: 'application/json' },
    tags: { endpoint: 'hotel-detail' },
  });
  check(res, {
    'status 200 or 404 (deleted id ok)': (r) => r.status === 200 || r.status === 404,
    'has data when 200': (r) => {
      if (r.status !== 200) return true;
      try {
        const j = r.json();
        return j && j.success && j.data;
      } catch {
        return false;
      }
    },
  });
  sleep(Math.random() * 2);
}
