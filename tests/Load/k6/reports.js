import { page, recordFlow, selectedSession, update } from './student-search.js';

export const options = { vus: 20, duration: '5m' };

const reports = [
  { path: '/admin/reports/revenue', component: 'RevenueReportPage', filters: { 'filters.from': '2026-01-01', 'filters.to': '2026-12-31' } },
  { path: '/admin/reports/payment-methods', component: 'PaymentMethodReportPage', filters: { 'filters.from': '2026-01-01', 'filters.to': '2026-12-31' } },
  { path: '/admin/reports/outstanding-aged', component: 'OutstandingAgedReportPage', filters: { 'filters.date': '2026-06-30' } },
  { path: '/admin/reports/student-payment-history', component: 'StudentPaymentHistoryPage', filters: { 'filters.student_id': 1 } },
];

export default function () {
  const start = Date.now();
  let succeeded = false;

  try {
    const session = selectedSession();
    const report = reports[(__VU + __ITER - 1) % reports.length];
    const context = page(report.path, `App\\Domain\\Finance\\Filament\\Pages\\Reports\\${report.component}`, session);
    update(context, session, report.filters, [{ method: 'applyFilters', params: [] }]);
    succeeded = true;
  } finally {
    recordFlow(start, succeeded);
  }
}
