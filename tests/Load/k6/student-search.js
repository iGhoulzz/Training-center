import http from 'k6/http';
import { Counter, Rate, Trend } from 'k6/metrics';

export const options = { vus: 20, duration: '5m' };

const manifestPath = __ENV.K6_SESSION_FILE;
if (!manifestPath) throw new Error('K6_SESSION_FILE is required');
const manifest = JSON.parse(open(manifestPath));
if (!Array.isArray(manifest.sessions) || manifest.sessions.length < 20) {
  throw new Error('The manifest must contain at least 20 sessions');
}

export const flowDuration = new Trend('flow_duration', true);
export const flowErrors = new Rate('flow_errors');
export const flowCompleted = new Counter('flow_completed');

const base = (__ENV.K6_BASE_URL || '').replace(/\/$/, '');
if (!base) throw new Error('K6_BASE_URL is required');

function decodeAttribute(raw) {
  return raw.replace(/&quot;/g, '"').replace(/&#039;|&#39;/g, "'")
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

export function selectedSession() {
  return manifest.sessions[(__VU - 1) % manifest.sessions.length];
}

export function headers(session) {
  return {
    Cookie: `${manifest.cookie_name}=${session.cookie}`,
    'X-CSRF-TOKEN': session.csrf,
    'X-Livewire': 'true',
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
}

export function page(path, componentName, session) {
  const response = http.get(`${base}${path}`, { headers: headers(session), redirects: 0 });
  if (response.status !== 200) throw new Error(`Page ${path} returned ${response.status}`);

  const uri = response.body.match(/data-update-uri="([^"]+)"/);
  if (!uri) throw new Error(`No Livewire update URI on ${path}`);
  const snapshots = [...response.body.matchAll(/wire:snapshot="([^"]+)"/g)];
  const match = snapshots.map((item) => decodeAttribute(item[1]))
    .find((snapshot) => JSON.parse(snapshot).memo.name === componentName);
  if (!match) throw new Error(`No ${componentName} snapshot on ${path}`);

  return { uri: decodeAttribute(uri[1]), snapshot: match };
}

export function update(context, session, updates, calls) {
  const response = http.post(`${base}${context.uri}`, JSON.stringify({
    components: [{ snapshot: context.snapshot, updates, calls: calls.map((call) => ({ path: '', ...call })) }],
  }), { headers: headers(session), redirects: 0 });

  if (response.status !== 200) throw new Error(`Livewire update returned ${response.status}`);
  const payload = response.json();
  if (!payload.components || !payload.components[0] || !payload.components[0].snapshot) {
    throw new Error('Livewire update returned no component snapshot');
  }
  context.snapshot = payload.components[0].snapshot;
  return payload.components[0];
}

export function recordFlow(start, succeeded) {
  flowDuration.add(Date.now() - start);
  flowErrors.add(!succeeded);
  if (succeeded) flowCompleted.add(1);
}

export default function () {
  const start = Date.now();
  let succeeded = false;
  try {
    const session = selectedSession();
    const context = page('/admin/students', 'App\\Domain\\Enrollment\\Filament\\Resources\\StudentResource\\Pages\\ListStudents', session);
    const search = __ENV.K6_STUDENT_SEARCH || 'PERF-000100';
    const expectedHit = __ENV.K6_STUDENT_HIT || search;
    const result = update(context, session, { tableSearch: search }, []);
    if (JSON.parse(result.snapshot).data.tableSearch !== search ||
        !result.effects.html.includes(expectedHit)) {
      throw new Error('Student search did not render the expected seeded student');
    }
    succeeded = true;
  } finally {
    recordFlow(start, succeeded);
  }
}
