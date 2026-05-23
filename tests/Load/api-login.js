import http from "k6/http";
import { check } from "k6";

/**
 * Auth burst load test.
 *
 * 20 concurrent logins for 1 minute. Stresses Sanctum token issuance
 * (DB INSERT into personal_access_tokens) + bcrypt password verify
 * (intentionally CPU-bound) + the audit log write.
 *
 * Requires E2E_TEST_USER + E2E_TEST_PASS env vars. Without them, the
 * script exits early with a clear message.
 */

const BASE_URL = __ENV.BASE_URL || "https://api.zulu.am";
const EMAIL = __ENV.E2E_TEST_USER;
const PASSWORD = __ENV.E2E_TEST_PASS;

export const options = {
  vus: 20,
  duration: "60s",
  thresholds: {
    http_req_duration: ["p(95)<500"],
    http_req_failed: ["rate<0.02"],
  },
};

export function setup() {
  if (!EMAIL || !PASSWORD) {
    throw new Error(
      "E2E_TEST_USER and E2E_TEST_PASS env vars are required to run the auth-load test.",
    );
  }
}

export default function () {
  const res = http.post(
    `${BASE_URL}/api/auth/login`,
    JSON.stringify({ email: EMAIL, password: PASSWORD }),
    { headers: { "Content-Type": "application/json", Accept: "application/json" } },
  );
  check(res, {
    "status is 200 or 422": (r) => r.status === 200 || r.status === 422,
    "no 5xx": (r) => r.status < 500,
  });
}
