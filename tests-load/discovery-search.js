// Discovery search — the hottest endpoint in prod.
//
// Hits GET /api/discovery/search with realistic filter combinations.
// PG visibility controls + eager-loaded relations make this a good
// proxy for the worst-case read path under concurrency.
//
// See README.md for how to run.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'https://staging.zulu.am';
const SCENARIO = __ENV.SCENARIO || 'smoke';

const searchLatency = new Trend('search_latency', true);

const scenarios = {
  smoke: {
    executor: 'constant-vus',
    vus: 1,
    duration: '30s',
  },
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
      { duration: '2m', target: 50 },
      { duration: '3m', target: 100 },
      { duration: '5m', target: 200 },
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

// Realistic-ish filter combos. Each VU picks one at random per iteration
// so the cache cannot serve every request from one hot row.
const FILTERS = [
  '?type=hotel&adults=2',
  '?type=hotel&adults=2&children=1&stars=4',
  '?type=flight&adults=1',
  '?type=transfer&adults=2',
  '?type=excursion&adults=2',
  '?type=hotel&adults=2&min_rating=4&free_cancellation=1',
  '?type=hotel&adults=1&meal_type=breakfast&stars=3',
  '?type=excursion&adults=4&children=2',
  '?type=transfer&adults=1&private_only=1',
  '?type=hotel&adults=2&price_min=20&price_max=200&currency=USD',
];

export default function () {
  const qs = FILTERS[Math.floor(Math.random() * FILTERS.length)];
  const res = http.get(`${BASE_URL}/api/discovery/search${qs}`, {
    headers: { Accept: 'application/json' },
    tags: { endpoint: 'discovery-search' },
  });
  searchLatency.add(res.timings.duration);
  check(res, {
    'status 200': (r) => r.status === 200,
    'has data array': (r) => {
      try {
        const j = r.json();
        return j && j.success && Array.isArray(j.data?.items ?? j.data);
      } catch {
        return false;
      }
    },
  });
  sleep(Math.random() * 2);
}
