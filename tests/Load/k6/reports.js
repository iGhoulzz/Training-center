import { page, recordFlow, selectedSession, update } from './student-search.js';

export const options = { vus: 20, duration: '5m' };

const reports = [
  { path: '/admin/reports/revenue', component: 'RevenueReportPage', filters: { 'filters.from': '2026-01-01', 'filters.to': '2026-12-31' }, markers: ['PERF-COURSE', 'PERF-BATCH', '1600000.000'] },
  { path: '/admin/reports/payment-methods', component: 'PaymentMethodReportPage', filters: { 'filters.from': '2026-01-01', 'filters.to': '2026-12-31' }, markers: ['Cash', '1600000.000'] },
  { path: '/admin/reports/outstanding-aged', component: 'OutstandingAgedReportPage', filters: { 'filters.date': '2026-06-30' }, markers: ['PERF-000001', '600.000'] },
  { path: '/admin/reports/student-payment-history', component: 'StudentPaymentHistoryPage', filters: { 'filters.student_id': 1 }, markers: ['PERF-000001', '400.000'] },
];

export default function () {
  const start = Date.now();
  let succeeded = false;

  try {
    const session = selectedSession();
    const report = reports[(__VU + __ITER - 1) % reports.length];
    const context = page(report.path, `App\\Domain\\Finance\\Filament\\Pages\\Reports\\${report.component}`, session);
    const result = update(context, session, report.filters, [{ method: 'applyFilters', params: [] }]);
    const rawFilters = JSON.parse(result.snapshot).data.appliedFilters;
    const applied = Array.isArray(rawFilters) ? rawFilters[0] : rawFilters;
    const filtersMatch = Object.entries(report.filters).every(([field, value]) =>
      String(applied[field.replace('filters.', '')]) === String(value));
    const rowsRendered = report.markers.every((marker) => result.effects.html.includes(marker));
    if (!filtersMatch || !rowsRendered) {
      throw new Error(`${report.component} did not render the applied fixture-backed report`);
    }
    succeeded = true;
  } finally {
    recordFlow(start, succeeded);
  }
}
