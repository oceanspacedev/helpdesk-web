# Test Cases

Status: Reviewed

| ID | Source | Test case | Existing evidence |
|---|---|---|---|
| TC-001 | FR-001, UC-001 | Root redirects to admin. | Existing feature test |
| TC-002 | FR-003, FR-004, UC-001 | Web ticket creation stores owner, Open status, required fields, and attachments. | Missing |
| TC-003 | FR-005, UC-002 | Ticket list supports expected actions and export. | Missing |
| TC-004 | FR-006, UC-002 | Mailbox scopes return outgoing tickets to the sender, incoming tickets to Admin/Staff of the destination unit, all tickets to global administrators, and no tickets to unrelated units. | Existing feature tests |
| TC-005 | FR-007, UC-002 | Only destination processors receive workflow permission for Open tickets; report-content Edit remains unavailable to recipients, once In Progress only the eligible responsible agent can transition, an ineligible responsible can be replaced through Ambil Alih, and Cancel/Closed/soft-deleted tickets are workflow-terminal. | Existing policy and Livewire feature tests |
| TC-006 | FR-008, UC-001, UC-002 | Ticket create/update creates history. | Partially existing through MCP ticket-creation tests |
| TC-007 | FR-009, UC-002 | Status changes set `approved_at` and `solved_at`. | Covered for closed workflow; other date branches remain partial |
| TC-008 | FR-010, UC-001, UC-003 | Ticket and comment notifications go to intended active recipients and fall back from an ineligible responsible user. | Covered for comment fallback and closed-ticket notification; external delivery remains untested |
| TC-009 | FR-011, UC-003 | Comment relation manager stores comment and attachment path. | Missing |
| TC-010 | FR-012, UC-004 | MCP intake returns relevant units, categories, priorities, and business entities when requesting or correcting classification. | Existing feature tests |
| TC-011 | FR-013, UC-004 | MCP classification resolves valid values and rejects unknown, ambiguous, or missing values without advancing incorrectly. | Existing feature tests |
| TC-012 | FR-014, UC-004 | A verified MCP reporter with one canonical resolved identity creates a ticket; if the resolved owner changes before commit, creation is rejected and phone verification restarts. | Existing feature tests |
| TC-013 | FR-015, UC-004 | When no eligible Helpdesk account exists, exactly one Talenta phone match may supply the prerequisite account data server-side; multiple matches create neither an account nor a ticket, while a zero match enters consent-gated inline account creation before the same intake can create a ticket. | Existing feature tests |
| TC-014 | FR-016, UC-004 | Unverified, conflicting-phone, ambiguous-phone, and LID-only identities are rejected; a verified phone unknown to both directories enters consent-gated inline registration. | Existing feature tests |
| TC-017 | FR-019, UC-004 | Exact message and creation retries replay safely, while reused identifiers with changed content conflict and no duplicate ticket is created. | Existing feature tests |
| TC-018 | FR-002, FR-020, UC-006 | Phone login sends OTP before any Helpdesk/Talenta lookup and gives known/unknown numbers the same pre-verification state. Existing login succeeds after proof; manual and Talenta registration remain pending until valid OTP. Wrong OTP, missing server-side proof, delivery failure, cache loss, phone tampering, inactive/deleted accounts, and canonical duplicates create no account. | Existing feature tests |
| TC-019 | FR-021, UC-007 | Socialite never auto-registers an unknown identity; new and existing links accept trusted active users and reject unverified, legacy-review, inactive, or soft-deleted users. | Existing feature tests |
| TC-020 | FR-022, UC-008 | Master data and users can be managed under policy. | Missing |
| TC-021 | NFR-001, NFR-002 | Dependency versions meet PHP, Laravel, Filament expectations. | Static evidence only |
| TC-022 | NFR-003, NFR-007 | WhatsApp gateway normalizes numbers, sends WAHA, and falls back to Fonnte. | Existing unit tests |
| TC-023 | NFR-004 | MCP method and tool errors omit framework debug details. | Existing feature test |
| TC-024 | NFR-006 | Ticket attachment limits reject invalid file types, count, and total size. | Missing |
| TC-025 | FR-016, FR-020, UC-004 | An MCP reporter unknown to Helpdesk/Talenta reaches `registration_consent` only after OTP or a trusted assertion. Name input before explicit yes creates nothing; after yes, only a valid full name typed at `registration_name` may atomically create one phone-only account and continue the same intake with its original issue. Declining returns terminal `cancelled` with no account, ticket, web URL, or redirect. | Existing feature tests |
| TC-026 | FR-002, NFR-008, BR-010 | Canonical phone duplicates block the unique-index migration; legacy email timestamps require review, all old remember tokens are revoked, and unverified email cannot authenticate, receive mail, mutate identity through self-service profile, reset passwords, link Socialite, or access the panel without a matching phone-OTP session. | Existing feature tests |
| TC-027 | NFR-009, UC-004 | Stable distinct external users A/B/C/D retain isolated reporter bindings; local MCP calls without `external_user_id` never inherit another reporter and must verify by OTP on each new intake. | Existing feature tests |
