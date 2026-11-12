> Diekstrak dari `PRD_VMEs_Roadmap_5_Lengkap_Corporate_Sponsorship_Enterprise.docx` (diterima 2026-11-12). Gambar diagram tidak ikut; teksnya lengkap.

PRODUCT REQUIREMENTS DOCUMENT
ROADMAP 5ENTERPRISE, INTEGRATION & INTELLIGENCE
PPTI VMES Training Center | Integrated Training Management System
Blueprint khusus pengembangan Corporate Portal, Payment Gateway, WhatsApp & Email Automation, API, Advanced Analytics, dan AI terukur.

| Dokumen | Keterangan |
|---|---|
| Produk | PPTI VMES Integrated Training Management System |
| Roadmap | Phase 5, Enterprise, Integration & Intelligence |
| Tujuan | Meningkatkan kemampuan enterprise training, transaksi digital, integrasi, analytics, dan intelligence |
| Pengguna utama | Management VMES, Admin, Corporate Admin/HR/L&D, Trainer, Participant |
| Status | PRD fungsional untuk UI/UX, backend, database, integration, QA, dan deployment |

## 1. Ringkasan Eksekutif
Roadmap 5 merupakan lapisan enterprise dan intelligence di atas fondasi platform VMES. Fokusnya bukan sekadar menambah fitur, melainkan menghubungkan transaksi, organisasi, peserta, pembelajaran, komunikasi, data, integrasi eksternal, dan AI dalam satu alur yang dapat diaudit.
Enam domain utama roadmap ini adalah: (1) Corporate Portal, (2) Payment Gateway, (3) WhatsApp dan Email Automation, (4) API dan Integration Layer, (5) Advanced Analytics, dan (6) Measurable AI.
Corporate Portal menggunakan model satu identitas peserta dengan banyak enrollment. Perusahaan tidak memiliki seluruh riwayat peserta. Akses perusahaan mengikuti enrollment yang disponsori perusahaan. Model ini menangani peserta yang pindah perusahaan, resign, dan mengambil training mandiri.
## 2. Tujuan Bisnis Roadmap 5
Meningkatkan porsi corporate training dan repeat order melalui portal perusahaan.
Mengurangi pekerjaan manual pada request, participant assignment, pembayaran, notifikasi, dan reporting.
Menyediakan pengalaman pembayaran digital yang terlacak dari invoice sampai reconciliation.
Membangun komunikasi otomatis melalui WhatsApp, email, dan in-app notification.
Membuka integrasi VMES dengan HRIS, ERP, payment provider, partner, dan sistem eksternal.
Mengubah data operasional menjadi dashboard dan insight yang dapat dipakai untuk keputusan bisnis.
Menerapkan AI hanya pada use case yang memiliki data, KPI, evaluasi, dan human oversight yang jelas.
## 3. Ruang Lingkup

| Domain | In Scope | Out of Scope untuk MVP |
|---|---|---|
| Corporate Portal | Company account, request, seats, employees, enrollment, progress, competency, reports | Full HRIS replacement |
| Payment | Invoice, payment gateway, webhook, status, reconciliation | Accounting/GL penuh |
| Notification | WhatsApp, email, in-app, templates, schedule, log | Omnichannel marketing automation kompleks |
| API | REST, webhook, auth, scope, rate limit, versioning | Public developer marketplace |
| Analytics | Executive, training, LMS, corporate, funnel, cohort | Enterprise data science platform penuh |
| AI | Recommendation, early warning, feedback analysis, LMS assistant, insight | Autonomous decision making tanpa human review |

## 4. Modul A: Corporate Portal
Corporate Portal adalah workspace perusahaan untuk mengelola kebutuhan training karyawan. Portal terhubung dengan participant account, tetapi data perusahaan dibatasi oleh organization scope.
Gambar 2. Model satu member dengan beberapa enrollment dan sponsor yang berbeda.
### 4.1 Corporate Account

| Fitur | Kebutuhan |
|---|---|
| Company profile | Legal name, address, industry, contact, tax/invoice data, logo |
| Corporate users | Admin HR/L&D, supervisor, finance, viewer |
| Roles & permissions | RBAC + organization scope |
| Approval policy | Optional approval sebelum request atau payment |
| Cost center | Departemen/proyek/anggaran |
| Audit | Log perubahan user, request, participant, payment, access |

### 4.2 Training Request & Reserved Seats
Corporate admin memilih training, tipe delivery, target tanggal, lokasi, jumlah peserta/seat, dan kebutuhan.
Sistem membuat Request ID dan status request.
Sistem membuat reserved seats, misalnya 10 seats.
Reserved seat tidak membuat participant palsu.
Daftar peserta dapat diisi kemudian melalui manual entry, bulk upload, atau invitation.

| Status | Makna |
|---|---|
| DRAFT | Request belum dikirim |
| SUBMITTED | Request dikirim ke VMES |
| QUOTED | Quotation dibuat |
| APPROVAL | Menunggu persetujuan |
| APPROVED | Disetujui |
| PAID | Pembayaran diterima |
| SCHEDULED | Batch dijadwalkan |
| IN_PROGRESS | Training berjalan |
| COMPLETED | Training selesai |
| CANCELLED | Request dibatalkan |

### 4.3 Participant Assignment

| Metode | Detail | Kontrol |
|---|---|---|
| Manual | Nama, email, HP, employee ID, jabatan, departemen | Duplicate check |
| Bulk Upload | Excel/CSV | Template validation + error report |
| Invitation | Karyawan menerima link | Verification + consent |
| Existing Member | Match email/phone/member ID | Tidak membuat akun kedua |

### 4.4 Model Enrollment Sponsorship
Enrollment adalah objek akses utama. Field Sponsor Organization ID wajib untuk corporate enrollment dan kosong untuk self-funded enrollment.

| Contoh | Type | Sponsor | Visibility |
|---|---|---|---|
| Budi - PLC Fundamental | CORPORATE | PT ABC | Budi + PT ABC sesuai scope |
| Budi - HMI Advanced | SELF | NULL | Budi |
| Budi - SCADA Advanced | CORPORATE | PT XYZ | Budi + PT XYZ sesuai scope |

### 4.5 Employment Lifecycle
Employment Relationship dipisahkan dari Member.
Resign mengubah employment menjadi INACTIVE, bukan menghapus member.
Certificate dan histori training peserta tetap tersedia bagi peserta.
Corporate access dapat dicabut atau dibatasi setelah employment berakhir.
Training self-funded tidak masuk ke dashboard perusahaan lama.
Jika peserta pindah perusahaan, sponsor baru hanya memperoleh enrollment yang baru disponsori.
### 4.6 Corporate Dashboard

| Dashboard | Indikator |
|---|---|
| Executive Summary | Employees, active training, completion, attendance, certificates |
| Participant Progress | Attendance, LMS completion, assessment, competency, status |
| Training Portfolio | Training aktif, selesai, upcoming, cancelled |
| Competency Matrix | Skill per employee, target, actual, gap |
| Certificate | Issued, pending, expired jika berlaku |
| Risk / Attention | Peserta dengan indikator risiko |
| Financial | Quotation, invoice, payment, outstanding |
| Reports | Export PDF/Excel/CSV sesuai permission |

## 5. Modul B: Payment Gateway
Payment Gateway menghubungkan quotation/invoice dengan pembayaran digital dan status transaksi. Corporate dan individual dapat menggunakan alur pembayaran yang berbeda sesuai policy.
Gambar 3. Hubungan event, payment, notification, recipient, dan audit.

| Komponen | Fungsi |
|---|---|
| Invoice | Nomor invoice, item, subtotal, tax, discount, total, due date |
| Payment Session | Membuat transaksi ke payment provider |
| Webhook | Menerima status dari provider |
| Payment Status | Pending, Paid, Failed, Expired, Refunded |
| Reconciliation | Mencocokkan payment dengan invoice |
| Refund | Permintaan dan status pengembalian |
| Audit | Menyimpan transaction ID, timestamp, response status |

### 5.1 Aturan Payment
Payment success tidak boleh ditentukan hanya dari browser redirect. Sistem harus memverifikasi webhook/provider status.
Invoice yang sudah paid tidak boleh dibayar ulang.
Webhook harus idempotent.
Refund harus memiliki reason, approver, timestamp, dan reference.
Corporate invoice dapat dibayar oleh finance corporate atau pihak lain sesuai policy.
Payment status memicu event notification dan mengubah status enrollment hanya setelah rule terpenuhi.
## 6. Modul C: WhatsApp + Email Automation
Semua komunikasi otomatis berasal dari Notification Engine. Business event dibuat satu kali, lalu engine menentukan recipient, channel, template, schedule, retry, dan audit.

| Event | Participant | Corporate | Trainer/Admin |
|---|---|---|---|
| Registration | WhatsApp + Email | Email jika sponsored | Dashboard |
| Payment Success | WhatsApp + Email | Email | Dashboard |
| H-7 / H-1 | WhatsApp + Email | Email | WhatsApp/Email |
| Schedule Change | WhatsApp + Email | WhatsApp + Email | WhatsApp + Email |
| Progress Risk | WhatsApp/Email | Email jika sponsored | Dashboard |
| Training Completed | WhatsApp + Email | Email | Dashboard |
| Certificate Issued | WhatsApp + Email | Email | Dashboard |

### 6.1 Notification Center

| Field | Keterangan |
|---|---|
| Event | Trigger seperti enrollment.created atau certificate.issued |
| Recipient Scope | Participant, Corporate Admin, Trainer, Admin |
| Channel | WhatsApp, Email, In-app |
| Template | Template versioned |
| Schedule | Immediate atau scheduled |
| Status | Queued, Sent, Delivered, Failed, Read |
| Retry | Retry policy |
| Audit | Timestamp dan actor/system |

## 7. Modul D: API & Integration Layer
API menjadi lapisan resmi untuk integrasi dengan payment provider, WhatsApp provider, email provider, HRIS, partner, aplikasi mobile, dan sistem perusahaan.

| API Area | Contoh Endpoint | Tujuan |
|---|---|---|
| Corporate | /api/v1/corporate/requests | Request training |
| Employees | /api/v1/corporate/employees | Employee directory |
| Enrollment | /api/v1/enrollments | Create/read/update enrollment |
| Progress | /api/v1/progress | LMS progress |
| Attendance | /api/v1/attendance | Attendance |
| Assessment | /api/v1/assessments | Assessment |
| Certificate | /api/v1/certificates | Certificate verification |
| Payment | /api/v1/payments | Transaction status |
| Notification | /api/v1/notifications | Notification status |
| Analytics | /api/v1/analytics | Approved KPI access |

### 7.1 API Security
Authentication dengan token/session yang aman.
Authorization berbasis role dan organization scope.
Rate limiting.
API versioning, misalnya /api/v1.
Idempotency key untuk transaksi penting.
Webhook signature verification.
Audit log.
Data minimization dan field-level access bila diperlukan.
## 8. Modul E: Advanced Analytics
Gambar 4. Data operasional menjadi analytics, AI, action, dan measurement.

| Layer | Dashboard | KPI Utama |
|---|---|---|
| Executive | Business overview | Revenue, corporate accounts, repeat order, margin, completion |
| Corporate | Client workspace | Employees, training hours, completion, attendance, competency gap |
| Training | Course performance | Demand, enrollment, attendance, completion, assessment |
| LMS | Learning behavior | Lesson, video, quiz, assignment, dropout |
| Sales Funnel | Corporate sales | Lead, request, quotation, approval, paid, completed, repeat |
| Customer | Cohort/retention | Repeat rate, time-to-repeat, average order value |
| Trainer | Trainer performance | Sessions, attendance, completion, rating, workload |

### 8.1 KPI Definition

| KPI | Definisi Operasional |
|---|---|
| Completion Rate | Completed enrollments / enrollments eligible for completion |
| Attendance Rate | Attended learning units / scheduled learning units |
| Repeat Order Rate | Corporate accounts with repeat purchase / corporate accounts eligible |
| Average Order Value | Recognized transaction value / number of orders |
| Training Conversion | Paid enrollments / qualified opportunities |
| At-Risk Rate | Active participants flagged by defined risk rules / active participants |

### 8.2 Corporate Analytics
Company training portfolio.
Participant progress.
Training hours per department.
Competency coverage.
Skill gap.
Certificate status.
Training cost by department/cost center.
Repeat order and program utilization.
## 9. Modul F: Measurable AI
AI pada roadmap ini tidak diposisikan sebagai fitur generatif semata. Setiap use case harus memiliki tujuan, data input, output, baseline, evaluation metric, human review, dan monitoring.

| Use Case | Input | Output | KPI |
|---|---|---|---|
| Training Recommendation | Training history, competency, prerequisite, corporate needs | Recommended courses | CTR, conversion, repeat enrollment |
| Early Warning | Attendance, LMS progress, assessment | Risk score + reason | Precision, recall, completion improvement |
| Feedback Analysis | Survey/comment | Theme/sentiment/category | F1/accuracy + actionability |
| LMS Assistant | Approved training content | Answer + source | Answer quality, citation coverage, unresolved rate |
| Corporate Insight | Competency + training + outcomes | Gap summary + action | Acceptance rate, training conversion |
| Demand Forecast | Historical enrollment, seasonality, segment | Forecast | MAE/MAPE atau metric yang sesuai |

### 9.1 AI Governance
AI tidak boleh mengubah status kelulusan atau sertifikasi secara otomatis.
Rekomendasi harus dapat ditelusuri ke data dan rule/model yang digunakan.
Model dan prompt/version harus dicatat untuk use case yang relevan.
Human approval digunakan untuk keputusan berisiko tinggi.
Data pribadi diproses sesuai kebutuhan dan permission.
AI output harus memiliki monitoring kualitas dan fallback ke proses manual.
## 10. End-to-End Corporate Scenario
PT ABC membuat Corporate Request untuk PLC Fundamental, 10 seats.
VMES membuat quotation dan invoice.
PT ABC membayar melalui payment gateway.
Payment webhook tervalidasi dan invoice berubah menjadi PAID.
Corporate admin mengisi 10 peserta melalui Excel.
Sistem melakukan duplicate/member matching.
Peserta existing tidak dibuatkan akun baru.
Enrollment dibuat dengan Enrollment Type CORPORATE dan Sponsor PT ABC.
Peserta menerima WhatsApp + email konfirmasi.
Corporate admin melihat peserta pada dashboard.
Trainer mengisi attendance, logbook progress, assessment, dan competency.
Corporate dashboard memperbarui progress peserta.
Jika risk rule terpenuhi, notification engine mengirim alert sesuai scope.
Training selesai, certificate diterbitkan.
Peserta dan corporate menerima notifikasi sertifikat.
Analytics memperbarui completion, corporate performance, dan repeat-order indicators.
## 11. Skenario Khusus yang Wajib Didukung

| Kasus | Expected Behavior |
|---|---|
| Peserta sudah punya akun | Reuse Member ID; create enrollment |
| Peserta self-funded | Sponsor NULL; private from corporate |
| Karyawan resign | Employment INACTIVE; member/history retained |
| Pindah perusahaan | New sponsor relationship/enrollment; old scope retained |
| Satu peserta ikut dua corporate training | Separate enrollments; each sponsor scoped |
| Corporate membatalkan satu peserta | Only affected enrollment changes |
| Payment gagal | Invoice/payment pending or failed; notification sent |
| Webhook dikirim dua kali | Idempotent; no duplicate payment/enrollment |
| Corporate admin berganti | Role/access changes without changing participant data |
| Certificate terbit | Participant owns certificate; corporate sees only sponsored record |

## 12. Role-Based Access Control

| Capability | Super Admin | Training Manager | Trainer | Corporate Admin | Participant |
|---|---|---|---|---|---|
| Manage corporate account | ✓ | ✓ | - | Own company | - |
| Create request | ✓ | ✓ | - | ✓ | - |
| Assign employees | ✓ | ✓ | - | ✓ | - |
| View sponsored progress | ✓ | ✓ | Scoped | ✓ | Own |
| View self-funded enrollment | ✓ | ✓ | Assigned only | - | Own |
| Manage payment | ✓ | ✓ | - | Own invoices | Own |
| Issue certificate | ✓ | ✓ | - | - | - |
| View analytics | ✓ | ✓ | Limited | Corporate scope | Own |
| Configure notification | ✓ | ✓ | - | Limited | Preferences |

## 13. Data Model Minimum

| Entity | Key Relationship |
|---|---|
| User | Authentication identity |
| Member | One participant identity |
| Organization | Corporate entity |
| EmploymentRelationship | Member ↔ Organization lifecycle |
| CorporateUser | User ↔ Organization |
| Training | Course/program master |
| Batch | Training delivery instance |
| TrainingRequest | Organization → VMES request |
| Seat | Reserved position within request |
| Enrollment | Member ↔ Training/Batch + Sponsor |
| Attendance | Enrollment learning attendance |
| LearningProgress | Enrollment LMS progress |
| Assessment | Enrollment assessment |
| Competency | Skill/level evidence |
| Certificate | Enrollment completion credential |
| Invoice | Commercial billing |
| PaymentTransaction | Payment provider transaction |
| Notification | Communication event/log |
| AuditLog | Immutable activity trail |
| AIInsight | Model-generated insight with version/reference |

## 14. Non-Functional Requirements

| Area | Requirement |
|---|---|
| Availability | Target 99.5%+ for production core services, excluding planned maintenance |
| Performance | Common dashboard/API reads target p95 ≤ 2 seconds under agreed load |
| Security | RBAC, scoped authorization, secure secrets, encryption in transit, audit |
| Scalability | Corporate users, enrollments, LMS events, and notifications can scale independently |
| Reliability | Payment webhook and notification processing are idempotent |
| Observability | Application logs, metrics, error tracking, integration status |
| Backup | Regular backup with tested restore procedure |
| Privacy | Minimum necessary data exposure by role and organization |
| Accessibility | Responsive web and basic accessibility compliance |
| Auditability | Business-critical changes traceable to actor/time/action |

## 15. Acceptance Criteria Roadmap 5
Corporate company account can be created and assigned corporate roles.
Corporate admin can request training without knowing participant names at request time.
System creates reserved seats without creating fake members.
Participants can be assigned manually, by bulk upload, or invitation.
Existing member identity is reused after successful matching.
Corporate dashboard only displays enrollment within company sponsor scope.
Self-funded training is excluded from company dashboard.
Resignation does not delete member, certificate, or training history.
Payment gateway updates transaction status through verified provider webhook.
Duplicate webhook cannot create duplicate payment or enrollment.
WhatsApp and email can be triggered by the same business event.
Notification delivery status and retry are logged.
API endpoints enforce authentication, authorization, rate limit, and versioning.
Analytics differentiates corporate vs self-funded enrollment.
Competency gap can be calculated from defined evidence.
AI recommendation and risk models have measurable evaluation metrics.
AI cannot autonomously issue certificates or change official assessment results.
All critical access, payment, enrollment, and certificate changes are audited.
## 16. Implementation Roadmap within Roadmap 5

| Sprint/Stage | Focus | Output |
|---|---|---|
| 5A Foundation | Organization, employment, enrollment sponsorship, access scope | Enterprise data model |
| 5B Corporate MVP | Request, seats, assignment, dashboard | Corporate Portal MVP |
| 5C Transaction | Quotation, invoice, payment gateway, reconciliation | Digital payment flow |
| 5D Automation | Notification engine, WhatsApp, email, scheduler | Automated communication |
| 5E Integration | REST API, webhook, partner/HRIS integration | Integration layer |
| 5F Analytics | BI, KPI, corporate analytics, funnel, cohort | Advanced dashboards |
| 5G AI | Recommendation, early warning, feedback, assistant | Measured AI services |
| 5H Hardening | Security, performance, audit, DR, UAT | Production readiness |

## 17. Prioritas MVP vs Advanced

| Priority | Features |
|---|---|
| P0 - Mandatory | Corporate account, request, seats, participant assignment, enrollment sponsorship, access scope, payment status, notification, audit |
| P1 - High | Corporate dashboard, progress, certificate, WhatsApp/email, reports, API core |
| P2 - Strategic | Competency matrix, TNA, advanced analytics, corporate funnel, HRIS integration |
| P3 - Intelligence | Recommendation, early warning, feedback NLP, LMS assistant, forecasting |

## 18. Keputusan Arsitektur yang Tidak Boleh Berubah
Corporate ownership tidak ditempelkan langsung pada Member sebagai satu-satunya sumber akses.
Enrollment menjadi unit utama sponsorship dan corporate visibility.
Member identity tetap satu sepanjang siklus hidup peserta.
Employment relationship adalah data terpisah dari Member.
Self-funded enrollment tidak memiliki corporate sponsor.
Payment status berasal dari sumber transaksi yang dapat diverifikasi.
WhatsApp dan email menggunakan satu notification/event engine.
API menggunakan versioning dan authorization scope.
Analytics menggunakan definisi KPI yang terdokumentasi.
AI harus measurable, auditable, dan tidak menggantikan keputusan resmi yang membutuhkan validasi manusia.
## 19. Output Akhir Roadmap 5
Pada akhir Roadmap 5, VMES diharapkan memiliki platform enterprise yang mampu mengelola hubungan perusahaan dan peserta secara aman, transaksi digital yang dapat direkonsiliasi, komunikasi otomatis, integrasi melalui API, analytics lintas proses, serta AI yang menghasilkan rekomendasi dan peringatan berbasis data.

| Capability | Outcome |
|---|---|
| Corporate Portal | Perusahaan dapat request, mendaftarkan, memonitor, dan mengevaluasi training |
| Payment Gateway | Transaksi online terlacak dari invoice sampai reconciliation |
| WhatsApp + Email | Informasi penting dikirim otomatis sesuai event dan scope |
| API | VMES dapat terhubung dengan sistem eksternal |
| Analytics | Management dan corporate memperoleh insight yang terukur |
| AI | Sistem membantu recommendation, risk detection, dan knowledge access dengan KPI |

PPTI VMES Training Center | Roadmap 5 PRD
## 20. Corporate Portal & Enrollment Sponsorship: Spesifikasi Lengkap
Bagian ini mengintegrasikan seluruh rancangan Corporate Portal dan Enrollment Sponsorship ke dalam Roadmap 5. Corporate Portal diperlakukan sebagai workspace perusahaan, sedangkan peserta tetap memiliki identitas VMES individual. Hubungan perusahaan dengan peserta dibentuk melalui Employment Relationship dan Enrollment Sponsorship.
### 20.1 Prinsip Arsitektur Identitas

| Objek | Makna | Aturan |
|---|---|---|
| Member | Identitas individu peserta VMES | Satu orang hanya satu Member ID |
| Organization | Perusahaan/instansi corporate | Memiliki corporate account |
| Employment Relationship | Hubungan kerja peserta dengan perusahaan | Dapat ACTIVE atau INACTIVE |
| Enrollment | Satu keikutsertaan pada satu training/batch | Menjadi unit utama sponsorship |
| Sponsor | Pihak yang membiayai/menugaskan training | Disimpan pada enrollment |
| Access Scope | Batas data yang dapat dilihat user | Mengikuti sponsor dan role |
| Certificate | Kredensial hasil training | Melekat pada enrollment, dapat diakses peserta |

### 20.2 Mengapa Sponsorship Harus Berada di Enrollment
Corporate tidak boleh menjadi atribut permanen pada Member karena seseorang dapat bekerja di beberapa perusahaan sepanjang siklus hidupnya, mengikuti training yang dibiayai perusahaan, dan pada waktu lain mengikuti training mandiri. Jika perusahaan ditempel langsung pada Member, sistem berisiko membuka histori yang seharusnya private.

| Contoh Riwayat | Enrollment Type | Sponsor | Dashboard Perusahaan |
|---|---|---|---|
| PLC Fundamental | CORPORATE | PT ABC | Terlihat PT ABC |
| HMI Basic | SELF | NULL | Tidak terlihat PT ABC |
| SCADA Advanced | CORPORATE | PT XYZ | Terlihat PT XYZ |
| Leadership Training | SELF | NULL | Tidak terlihat PT ABC maupun PT XYZ |

### 20.3 Corporate Training Request
Corporate Admin membuat request berdasarkan kebutuhan training, bukan berdasarkan daftar peserta terlebih dahulu.
Request berisi training/package, target tanggal, metode delivery, lokasi, jumlah seat, kebutuhan trainer, dan catatan.
Sistem membuat Request ID.
Sistem dapat membuat Reserved Seat 01 sampai N.
Reserved Seat tidak menghasilkan Member ID.
Peserta baru ditetapkan setelah perusahaan siap memberikan daftar peserta.

| Request Data | Contoh |
|---|---|
| Company | PT ABC |
| Training | PLC Fundamental |
| Delivery | On-site |
| Target Date | 2027-01-15 |
| Seat | 10 |
| Department | Engineering |
| Cost Center | ENG-01 |
| Participant List | Pending |
| Status | QUOTED |

### 20.4 Cara Memasukkan Nama Peserta

| Metode | Proses | Kelebihan | Kontrol |
|---|---|---|---|
| Manual | Corporate Admin mengisi satu per satu | Sederhana untuk sedikit peserta | Duplicate check |
| Bulk Excel/CSV | Download template → upload → validation | Cepat untuk puluhan/ratusan peserta | Row-level validation |
| Invitation Link | Perusahaan mengirim link ke karyawan | Karyawan melengkapi data sendiri | Email/phone verification |
| Existing Member | Cari Member ID/email terverifikasi | Tidak membuat akun ganda | Identity matching |

Sistem tidak boleh otomatis membuat nama peserta hanya karena perusahaan memesan 10 seat. Yang dibuat otomatis adalah reserved seat. Member dan enrollment baru dibuat setelah identity assignment terjadi.
### 20.5 Identity Matching & Duplicate Prevention

| Kondisi | Sistem |
|---|---|
| Email verified cocok | Gunakan Member ID existing |
| Member ID cocok | Gunakan Member existing |
| Phone verified cocok | Tampilkan candidate match untuk konfirmasi |
| Employee ID cocok dalam organization | Tawarkan existing employment record |
| Tidak ditemukan | Buat invitation/member baru sesuai policy |
| Sudah enrolled pada batch yang sama | Tolak duplicate enrollment |
| Data meragukan | Masuk review queue, jangan otomatis membuat akun |

### 20.6 Employment Relationship
Employment Relationship menjadi penghubung antara Member dan Organization. Ini penting untuk menangani resign dan perpindahan perusahaan tanpa menghapus identitas atau histori peserta.

| Field | Contoh |
|---|---|
| Employment ID | EMP-000982 |
| Member ID | VMES-000182 |
| Organization ID | ORG-ABC |
| Employee ID | ABC-001 |
| Department | Engineering |
| Position | Maintenance Technician |
| Start Date | 2026-01-10 |
| End Date | 2027-06-30 |
| Status | INACTIVE |
| Source | Corporate Portal |

### 20.7 Skenario Karyawan Resign
PT ABC sebelumnya mendaftarkan Budi untuk PLC Fundamental.
Budi memiliki Member ID VMES-000182.
Enrollment PLC tetap tersimpan sebagai historical corporate enrollment dengan Sponsor PT ABC.
Ketika Budi resign, Employment Relationship PT ABC berubah menjadi INACTIVE.
Corporate user PT ABC kehilangan akses aktif sesuai policy.
Budi tetap dapat login sebagai Member VMES.
Sertifikat PLC tetap dapat dilihat dan diverifikasi oleh Budi.
Training baru yang Budi beli sendiri memiliki Sponsor NULL dan tidak muncul pada dashboard PT ABC.
### 20.8 Skenario Pindah Perusahaan

| Training | Sponsor | Access Budi | Access PT ABC | Access PT XYZ |
|---|---|---|---|---|
| PLC Fundamental | PT ABC | ✓ | ✓ | - |
| HMI Advanced | NULL / SELF | ✓ | - | - |
| SCADA Advanced | PT XYZ | ✓ | - | ✓ |

PT XYZ tidak memperoleh histori training PT ABC. PT ABC juga tidak memperoleh training PT XYZ. Budi tetap memiliki satu profil dan seluruh histori miliknya.
### 20.9 Corporate Dashboard

| Dashboard | Isi |
|---|---|
| Overview | Total sponsored participants, active training, completion, attendance |
| Participant | Nama, employee ID, training, batch, status, progress |
| Progress | LMS, attendance, assessment, logbook progress |
| Competency | Skill, target level, actual level, gap |
| Certificate | Certificate status dari sponsored enrollment |
| Training | Upcoming, active, completed |
| Financial | Quotation, invoice, payment, outstanding |
| Risk | Peserta yang perlu perhatian |
| Report | Export berdasarkan organization scope |

### 20.10 Progress yang Dilihat Perusahaan
Progress corporate harus bersumber dari aktivitas training yang memang disponsori perusahaan. Trainer menginput logbook progress, attendance, assessment, dan milestone. Corporate dashboard membaca data tersebut dalam bentuk ringkasan.

| Progress Element | Contoh |
|---|---|
| Attendance | 4/4 days |
| LMS | 82% |
| Module | 8/10 completed |
| Assessment | 85 |
| Assignment | 3/4 submitted |
| Trainer Logbook | Day 3: HMI communication completed |
| Competency | PLC Basic: Achieved |
| Overall Status | On Track |

### 20.11 Privacy Boundary

| Data | Corporate | Participant |
|---|---|---|
| Corporate sponsored progress | ✓ | ✓ |
| Self-funded training | - | ✓ |
| Other company training | - | ✓ |
| Certificate owned by participant | Limited status/sponsored context | ✓ |
| Private trainer notes | - | - / trainer scope |
| Internal VMES notes | - | - |
| Corporate invoice | ✓ own organization | If relevant to transaction |

### 20.12 Corporate User Roles

| Role | Hak Akses |
|---|---|
| Corporate Owner | Full company workspace |
| HR/L&D Admin | Request, employee assignment, progress, report |
| Supervisor | View assigned department/team |
| Finance | Quotation, invoice, payment |
| Viewer | Read-only corporate dashboard |
| Participant | Own member and own enrollment |

### 20.13 Corporate Approval Workflow

| Stage | Actor | Output |
|---|---|---|
| Request | Corporate HR/L&D | Training Request |
| Review | VMES Sales/Training Manager | Scope & quotation |
| Approval | Corporate authorized user | Approved request |
| Payment | Corporate Finance | Paid/confirmed |
| Assignment | Corporate HR/L&D | Participant list |
| Delivery | Trainer | Attendance + logbook |
| Completion | VMES/Trainer | Completion decision |
| Certificate | VMES authorized role | Certificate issued |
| Review | Corporate | Performance report |

### 20.14 Corporate Contract & Account Relationship
Corporate account dapat memiliki beberapa request dan beberapa batch.
Satu corporate account dapat memiliki banyak corporate users.
Corporate user dapat dibatasi ke department, cost center, project, atau seluruh organization.
Kontrak/MoU dapat disimpan sebagai reference document jika dibutuhkan.
Harga corporate, discount, tax, payment term, dan quotation dapat mengikuti agreement perusahaan.
### 20.15 Corporate Analytics

| Analitik | Pertanyaan yang Dijawab |
|---|---|
| Training Utilization | Berapa seat dibeli dan berapa yang benar-benar digunakan? |
| Completion | Berapa peserta menyelesaikan training? |
| Attendance | Bagaimana kehadiran per batch/departemen? |
| Competency Gap | Skill apa yang masih kurang? |
| Cost per Employee | Berapa investasi training per employee? |
| Training Hours | Berapa jam training per employee/departemen? |
| Repeat Training | Apakah perusahaan kembali membeli training? |
| Program Effectiveness | Program mana yang menghasilkan outcome terbaik? |
| Risk | Peserta mana yang berisiko tidak menyelesaikan program? |

### 20.16 Corporate TNA & Recommendation
Pada tahap lanjut, perusahaan dapat menetapkan target kompetensi per posisi. Sistem membandingkan required competency dengan evidence dari training, assessment, dan competency record. Gap tersebut dapat menghasilkan rekomendasi program.

| Employee | Required | Actual | Gap | Recommendation |
|---|---|---|---|---|
| Budi | PLC 90 | PLC 72 | 18 | PLC Advanced |
| Andi | HMI 85 | HMI 88 | -3 | Maintenance / SCADA |
| Siti | SCADA 80 | SCADA 62 | 18 | SCADA Fundamental |

### 20.17 Corporate Portal dan Participant Portal
Corporate Portal dan Participant Portal saling terhubung, tetapi bukan satu dashboard yang sama. Corporate melihat data yang disponsori organisasi. Participant melihat seluruh data miliknya yang diizinkan oleh policy VMES.

| Aktivitas | Corporate | Participant |
|---|---|---|
| Request training | ✓ | - / self registration |
| Assign employee | ✓ | - |
| See sponsored progress | ✓ | ✓ |
| See self-funded progress | - | ✓ |
| See other company training | - | ✓ |
| Download own certificate | - / sponsored status | ✓ |
| Buy new training | - / company request | ✓ |

### 20.18 Corporate Exit & Data Retention Policy
Organization yang tidak lagi aktif tidak menghapus enrollment historical.
Corporate access user dapat disabled.
Historical enrollment tetap dapat dipakai untuk audit internal VMES.
Peserta tetap memiliki akses ke credential yang menjadi haknya.
Data yang memiliki kewajiban retensi mengikuti kebijakan legal dan operasional VMES.
Deletion/anonymization harus dipisahkan dari access revocation.
### 20.19 Corporate Sponsorship Rules

| Rule | Ketentuan |
|---|---|
| R1 | CORPORATE enrollment wajib memiliki Sponsor Organization |
| R2 | SELF enrollment tidak memiliki Sponsor Organization |
| R3 | Corporate dashboard hanya query enrollment dengan sponsor organization yang sama |
| R4 | Employment INACTIVE tidak menghapus historical enrollment |
| R5 | New employer tidak memperoleh historical sponsor data |
| R6 | Participant tetap dapat melihat own history sesuai policy |
| R7 | Corporate admin tidak boleh mengubah enrollment sponsor lama tanpa audit/authorization |
| R8 | Transfer sponsorship harus menjadi workflow resmi dan tercatat |
| R9 | Certificate tidak boleh dihapus hanya karena employment berakhir |
| R10 | Setiap perubahan access scope wajib diaudit |

### 20.20 Skenario Transfer Sponsorship
Jika terjadi kesalahan administrasi, misalnya training awalnya dibiayai PT ABC lalu biaya secara resmi dialihkan ke PT XYZ, sistem tidak boleh mengubah histori secara diam-diam.
Create sponsorship transfer request.
Validate authorization dari pihak yang berwenang.
Record old sponsor and new sponsor.
Record effective date and reason.
Update access scope sesuai effective date.
Write immutable audit log.
Recalculate corporate analytics sesuai policy.
### 20.21 Corporate Dashboard Security
Semua query corporate harus memiliki organization scope di server-side.
Frontend filtering tidak boleh menjadi satu-satunya pengaman.
User tidak dapat mengganti organization_id melalui URL/API untuk melihat organisasi lain.
Export report harus mengikuti permission yang sama dengan dashboard.
Audit log mencatat login, export, participant access, perubahan assignment, dan perubahan role.
Session dan token corporate harus dapat dicabut.
### 20.22 Acceptance Criteria Tambahan Corporate & Sponsorship
Corporate dapat membuat request dengan jumlah seat tanpa daftar nama peserta.
Reserved seat tidak membuat Member ID.
Corporate dapat mengisi peserta secara manual, bulk, atau invitation.
Existing member tidak dibuatkan duplicate account.
Setiap corporate enrollment memiliki sponsor organization.
Self-funded enrollment tidak memiliki sponsor.
Corporate dashboard hanya menampilkan sponsored enrollment.
Peserta resign tetap memiliki Member ID dan histori.
Corporate access dapat dicabut setelah employment berakhir.
Peserta yang pindah perusahaan tidak membawa histori sponsor lama ke dashboard perusahaan baru.
Peserta dapat mengikuti training mandiri tanpa muncul di dashboard perusahaan lama.
Trainer dapat mengisi progress dan corporate dapat melihat ringkasan yang diizinkan.
Certificate tetap tersedia bagi peserta setelah resign.
Perubahan sponsor memerlukan workflow dan audit.
Corporate export tidak boleh melampaui organization scope.
### 20.23 Rekomendasi Final Model VMES
ONE MEMBER → MANY EMPLOYMENT RELATIONSHIPS → MANY ENROLLMENTS → EACH ENROLLMENT HAS ITS OWN SPONSOR & ACCESS SCOPE
Model ini menjadi fondasi untuk Corporate Portal, LMS, certificate, payment, analytics, API, dan AI. Dengan model tersebut, sistem tidak perlu membuat akun baru setiap kali peserta berganti perusahaan atau mengambil training mandiri.
END OF PRD | PPTI VMES TRAINING CENTER
