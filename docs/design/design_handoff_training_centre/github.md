repo: iGhoulzz/Training-center
branch: main

## Last sync
date: 2026-09-07T03:16:00Z

### Round 5
- Added create pages (Today / Proposed) for Student, Course and Batch, from the real form() schemas in StudentResource, CourseResource and BatchResource.
- "New student / New course / New batch" buttons on the list pages now open them.
- Proposed forms group fields, mark required ones, and surface the inherit-from-course rules and the no-email/no-portal-login constraint.

## Earlier sync
date: 2026-09-07T01:56:04Z

### Round 4
- Added a Finance hub page (Today / Proposed) with KPI tiles, Money in / Money out / Reports quick-nav, and a queued-export panel.
- Read the real export logic: Xlsx-only queued ExportAction + PrepareReportCsvExport, GenerateReportPdfJob, signed per-requester ReportXlsxDownload / ReportPdfDownload routes, ReportExportAuthorization re-checked at download.
- Payments rows now carry a per-receipt PDF button with ready / generating states (GenerateReceiptJob is async), plus Export Excel / Export PDF in the header.

## Earlier sync
date: 2026-09-07T01:50:25Z

### Round 3
- Added Courses and Users & roles screens (Today / Proposed), from CourseResource, UserResource, StaffProfileResource and lang/en/staff.php.
- Cross-links throughout the proposed screens: student, batch, course, bill, receipt and staff names all jump to their own page.

## Earlier sync
date: 2026-09-06T05:31:00Z

### Round 2
- Added Charges, Payments, Certificates, Reports, Payroll runs, Activity log, Student portal and a JS toolkit page, each in Today / Proposed form.
- Labels taken from lang/en/{charges,payments,reports,payroll,certificates,activity,portal}.php and the CertificateStatus enum.

## Earlier sync
date: 2026-09-06T05:19:09Z

### Updated in this project
- Recreated the admin panel chrome (sidebar, topbar, dark theme, amber primary) from the panel providers.
- Recreated today's Dashboard, Batches list + view, Students list, and Enrol & Collect wizard.
- Added a "Proposed" redesign of each of those four screens, toggleable in the topbar.
- Annotated every proposed screen with the services/actions behind it, flagging what would need new queries.

## Sync history
- 2026-09-06T05:09:14Z — initial import and recreation of the four round-one screens.

## Screen map
| Screen | Repo files |
|---|---|
| Panel chrome / theme | app/Providers/Filament/AdminPanelProvider.php, app/Providers/Filament/StudentPanelProvider.php, resources/css/app.css |
| Dashboard | app/Providers/Filament/AdminPanelProvider.php (AccountWidget, FilamentInfoWidget) |
| Batches list + view | app/Domain/Enrollment/Filament/Resources/BatchResource.php, .../BatchResource/Pages/ListBatches.php, .../Pages/ViewBatch.php, .../RelationManagers/EnrollmentsRelationManager.php, .../RelationManagers/InstructorsRelationManager.php, app/Domain/Enrollment/Models/Batch.php |
| Students list | app/Domain/Enrollment/Filament/Resources/StudentResource.php, .../StudentResource/Pages/ListStudents.php, .../Pages/ViewStudent.php |
| Enrol & Collect | app/Domain/Finance/Filament/Pages/EnrollAndCollect.php, resources/views/filament/finance/enroll-and-collect.blade.php |
| Labels / copy | lang/en/enrollment.php, lang/en/collect.php, lang/en/pricing.php, lang/en/credentials.php |
| Backend-support notes | app/Domain/Finance/Services/*, app/Domain/Finance/Reports/*, app/Domain/Enrollment/Services/EnrollmentQueryService.php, app/Domain/Enrollment/Actions/* |
| Status colours | app/Domain/Enrollment/Enums/BatchStatus.php, EnrollmentStatus.php, StudentStatus.php |
