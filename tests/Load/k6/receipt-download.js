import http from 'k6/http';
import { sleep } from 'k6';
import { headers, recordFlow, selectedSession } from './student-search.js';

export const options = { vus: 20, duration: '5m' };

const base = (__ENV.K6_BASE_URL || '').replace(/\/$/, '');
const ids = (__ENV.K6_RECEIPT_IDS || '').split(',').map((value) => value.trim()).filter(Boolean);
if (!ids.length || ids.some((id) => !/^\d+$/.test(id))) {
  throw new Error('K6_RECEIPT_IDS must be a comma-separated list of real PDF-backed payment IDs');
}

export default function () {
  const start = Date.now();
  let succeeded = false;

  try {
    const session = selectedSession();
    const id = ids[(__VU + __ITER - 1) % ids.length];
    const response = http.get(`${base}/receipts/${id}/download`, {
      headers: headers(session),
      redirects: 0,
      responseType: 'none',
    });
    succeeded = response.status === 200 && (response.headers['Content-Type'] || '').includes('application/pdf');
    if (!succeeded) throw new Error(`Receipt download returned ${response.status}`);
  } finally {
    recordFlow(start, succeeded);
    sleep(25);
  }
}
