# Test Cases

Status: Reviewed

| ID | Source | Test case | Existing evidence |
|---|---|---|---|
| TC-001 | FR-001, UC-001 | Root redirects to admin. | Existing feature test |
| TC-002 | FR-003, FR-004, UC-001 | Web ticket creation stores owner, Open status, required fields, and attachments. | Missing |
| TC-003 | FR-005, UC-002 | Ticket list supports expected actions and export. | Missing |
| TC-004 | FR-006, UC-002 | Role-scoped ticket list returns only allowed tickets for Super Admin, Admin Unit, Staff Unit, and owner. | Missing |
| TC-005 | FR-007, UC-002 | Workflow buttons transition Open and In Progress tickets correctly. | Missing |
| TC-006 | FR-008, UC-001, UC-002 | Ticket create/update creates history. | Partially existing through ITA tests |
| TC-007 | FR-009, UC-002 | Status changes set `approved_at` and `solved_at`. | Missing |
| TC-008 | FR-010, UC-001, UC-003 | Ticket and comment notifications go to intended recipients. | Missing |
| TC-009 | FR-011, UC-003 | Comment relation manager stores comment and attachment path. | Missing |
| TC-010 | FR-012, UC-004 | Master-data endpoint returns units, categories, priorities, and business entities. | Existing feature test |
| TC-011 | FR-013, UC-004 | Classification validation resolves valid values and rejects unknown or missing values. | Existing feature tests |
| TC-012 | FR-014, UC-004 | Verified ITA actor creates a ticket. | Existing feature test |
| TC-013 | FR-015, UC-004 | Phone-only ITA reporter auto-registers without email/password. | Existing feature test |
| TC-014 | FR-016, UC-004 | Unverified, unknown, or LID-only actor is rejected. | Existing feature tests |
| TC-015 | FR-017, UC-005 | ITA actor retrieves and comments on ticket. | Existing feature test |
| TC-016 | FR-018, UC-005 | Unsupported close-ticket action is rejected. | Existing feature tests |
| TC-017 | FR-019, UC-004, UC-005 | Idempotency key prevents duplicate successful action. | Existing feature test |
| TC-018 | FR-002, FR-020, UC-006 | Phone OTP login succeeds and rejects unknown or wrong code. | Existing feature tests |
| TC-019 | FR-021, UC-007 | Socialite registration disabled and existing user linking. | Existing feature tests |
| TC-020 | FR-022, UC-008 | Master data and users can be managed under policy. | Missing |
| TC-021 | NFR-001, NFR-002 | Dependency versions meet PHP, Laravel, Filament expectations. | Static evidence only |
| TC-022 | NFR-003, NFR-007 | WhatsApp gateway normalizes numbers, sends WAHA, and falls back to Fonnte. | Existing unit tests |
| TC-023 | NFR-004 | Integration method error omits debug details. | Existing feature test |
| TC-024 | NFR-006 | Ticket attachment limits reject invalid file types, count, and total size. | Missing |

