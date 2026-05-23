import http from "k6/http";
import { check } from "k6";

/**
 * Public hotel search load test.
 *
 * The hotels-listing endpoint is the most-hit public surface — if it
 * degrades, the whole front door feels slow. This run ramps to 50
 * concurrent users to validate the cache + DB indexes hold.
 */

const BASE_URL = __ENV.BASE_URL || "https://api.zulu.am";

export const options = {
  stages: [
    { duration: "30s", target: 50 },  // ramp to 50 users
    { duration: "90s", target: 50 },  // hold for 90s
    { duration: "30s", target: 0 },   // ramp down
  ],
  thresholds: {
    http_req_duration: ["p(95)<1000"],
    http_req_failed: ["rate<0.01"],
  },
};

const LANGS = ["hy", "en", "ru"];

export default function () {
  const lang = LANGS[Math.floor(Math.random() * LANGS.length)];
  const res = http.get(`${BASE_URL}/api/hotels?lang=${lang}&per_page=20`);
  check(res, {
    "status is 200": (r) => r.status === 200,
    "body has 'data' array": (r) => {
      try {
        const body = r.json();
        return body && Array.isArray(body.data);
      } catch {
        return false;
      }
    },
  });
}
