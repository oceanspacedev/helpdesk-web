# Traceability Matrix

Status: Reviewed

| Requirement | Use case | Data | Integration | Tests |
|---|---|---|---|---|
| FR-001 | UC-001 | none | PAGE-001 | TC-001 |
| FR-002 | UC-001, UC-008 | ENT-001 | PAGE-002 | TC-018 |
| FR-003 | UC-001 | ENT-002, ENT-003, ENT-004, ENT-005, ENT-007 | PAGE-004 | TC-002 |
| FR-004 | UC-001 | ENT-002, ENT-006 | PAGE-004 | TC-002 |
| FR-005 | UC-002 | ENT-002 | PAGE-003, PAGE-005, PAGE-006 | TC-003 |
| FR-006 | UC-002 | ENT-001, ENT-002 | PAGE-003, policy | TC-004 |
| FR-007 | UC-002 | ENT-002, ENT-006 | PAGE-005 | TC-005 |
| FR-008 | UC-001, UC-002 | ENT-009 | model events | TC-006 |
| FR-009 | UC-002 | ENT-002 | model events | TC-007 |
| FR-010 | UC-001, UC-003 | ENT-001, ENT-002, ENT-008 | notifications | TC-008 |
| FR-011 | UC-003 | ENT-008 | relation manager | TC-009 |
| FR-012 | UC-004 | ENT-003, ENT-004, ENT-005, ENT-007 | API-001 | TC-010 |
| FR-013 | UC-004 | ENT-003, ENT-004, ENT-005, ENT-007 | API-002 | TC-011 |
| FR-014 | UC-004 | ENT-001, ENT-002 | API-003 | TC-012 |
| FR-015 | UC-004 | ENT-001 | API-003 | TC-013 |
| FR-016 | UC-004 | ENT-001 | API-003 | TC-014 |
| FR-017 | UC-005 | ENT-002, ENT-008 | API-003 | TC-015 |
| FR-018 | UC-005 | ENT-002 | API-003 | TC-016 |
| FR-019 | UC-004, UC-005 | cache | API-003 | TC-017 |
| FR-020 | UC-006 | ENT-001, cache | PAGE-012, PAGE-013, WhatsApp gateway | TC-018 |
| FR-021 | UC-007 | ENT-001, ENT-010 | API-004 | TC-019 |
| FR-022 | UC-008 | ENT-001, ENT-003, ENT-004, ENT-006, ENT-007 | Filament resources | TC-020 |

## NFR Trace

| NFR | Evidence | Tests |
|---|---|---|
| NFR-001 | `composer.json`, README | TC-021 |
| NFR-002 | `composer.json`, README | TC-021 |
| NFR-003 | phone traits and gateway | TC-022 |
| NFR-004 | exception response test | TC-023 |
| NFR-005 | OTP code and cache implementation | TC-018 |
| NFR-006 | ticket upload form rules | TC-024 |
| NFR-007 | gateway fallback implementation | TC-022 |

