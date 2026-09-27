import http from 'k6/http';
import { headers, page, recordFlow, selectedSession, update } from './student-search.js';

export const options = { vus: 20, duration: '5m' };

export default function () {
  const start = Date.now();
  let succeeded = false;

  try {
    const session = selectedSession();
    const context = page('/admin/enroll-and-collect', 'App\\Domain\\Finance\\Filament\\Pages\\EnrollAndCollect', session);

    // Filament's Select createOption action is the real quick-create boundary.
    update(context, session, {}, [{ method: 'mountAction', params: ['createOption', {}, { schemaComponent: 'data.student_id' }] }]);
    const code = `LOAD-${__VU}-${__ITER}-${Date.now()}`;
    const created = update(context, session, {
      'mountedActions.0.data.student_code': code,
      'mountedActions.0.data.first_name': 'Load',
      'mountedActions.0.data.last_name': 'Student',
    }, [{ method: 'callMountedAction', params: [] }]);
    const formData = JSON.parse(created.snapshot).data.data;
    const student = Array.isArray(formData) ? formData[0] : formData;
    if (!student || !student.student_id) throw new Error('Quick-create did not select a student');

    const confirmed = update(context, session, { 'data.batch_id': Number(__ENV.K6_BATCH_ID || '1') }, [
      { method: 'confirm', params: [] },
    ]);
    const chargeId = JSON.parse(confirmed.snapshot).data.collectionChargeId;
    if (!chargeId) throw new Error('Enrollment did not open a collection charge');

    update(context, session, {
      'collect.amount': '1000.000',
      'collect.tenders': [{ method: 'cash', amount: '1000.000', external_reference: null }],
    }, [{ method: 'finalize', params: [] }]);

    // A successful Livewire response can still carry a validation error. Read
    // the new bill's derived allocation to verify the payment actually wrote.
    const bill = http.get(`${(__ENV.K6_BASE_URL || '').replace(/\/$/, '')}/admin/charges/${chargeId}`, {
      headers: headers(session), redirects: 0,
    });
    if (bill.status !== 200 || !/Paid so far[\s\S]{0,1000}1000\.000 LYD/.test(bill.body)) {
      throw new Error('The new bill does not show the full payment allocation');
    }
    succeeded = true;
  } finally {
    recordFlow(start, succeeded);
  }
}
