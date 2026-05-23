import http from "k6/http";
import { check, sleep } from "k6";

/**
 * Pre-flight sanity check.
 *
 * 1 user, 30 seconds, hits the deep-health endpoint.
 * Run this FIRST before any other load script — confirms the runner,
 * URL, and target service are all wired up.
 */

const BASE_URL = __ENV.BASE_URL || "https://api.zulu.am";

export const options = {
  vus: 1,
  duration: "30s",
  thresholds: {
    http_req_duration: ["p(95)<200"],
    http_req_failed: ["rate<0.01"],
  },
};

export default function () {
  const res = http.get(`${BASE_URL}/api/health/deep`);
  check(res, {
    "status is 200": (r) => r.status === 200,
    "body has 'checks' object": (r) => {
      try {
        const body = r.json();
        return body && typeof body.checks === "object";
      } catch {
        return false;
      }
    },
  });
  sleep(1);
}
