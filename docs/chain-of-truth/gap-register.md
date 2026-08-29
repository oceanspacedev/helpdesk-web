# Gap Register

Status: Reviewed  
Ranking model: impact, likelihood, blast radius, and urgency based on observed implementation.

| ID | Severity | Category | Gap | Evidence | Impact | Recommended repair order |
|---|---|---|---|---|---|---|
| GAP-002 | Resolved | Authorization / data visibility | Ticket visibility now uses explicit sender, destination-unit inbox, and global scopes instead of the invalid owner/responsible comparison. | `Ticket.php`, `TicketResource.php`, `TicketPolicy.php`, mailbox tests | Resolved. | Covered by mailbox scope and Livewire tab tests. |
| GAP-003 | Resolved | Role model conflict | `Staff Unit` is canonical across permissions, visibility, workflow, comments, SLA recipients, and notifications; legacy `Staf Unit` memberships and permissions are merged by migration. | `RoleSeeder.php`, role normalization migration, notification queries, feature tests | Resolved. | Covered by role migration and mailbox tests. |
| GAP-004 | Medium | Authorization templates | Multiple policies still contain generated placeholder permission strings for replicate and reorder actions. | `app/Policies/*.php` | Permission checks for replicate/reorder and some role actions may never work as intended and indicate generated code leftovers. | Define permission matrix, regenerate or repair policies, test affected actions. |
| GAP-005 | Resolved | Data model consistency | `user_entities` is now authoritative for unit access; legacy `users.unit_id` is backfilled only when no pivot exists and is synchronized by the user form for compatibility. | `User.php`, `UserResource.php`, membership migration, ticket/category resources | Resolved for ticket and category authorization paths. | Covered by stale-legacy and multi-unit feature tests. |
| GAP-006 | Medium | Relationship mismatch | `TicketHistory::ticket()` belongs to `Ticket` using `tiket_id`, while table and fillable use `ticket_id`. | `TicketHistory.php`, ticket history migration | Ticket history relationship may fail when dereferenced. | Correct data-model artifact, then implementation and tests. |
| GAP-008 | Partially resolved | Test coverage | Livewire feature coverage now exercises role-filtered mailbox tabs, claim/takeover actions, policy boundaries, comments, and category-unit scoping; browser E2E remains absent. | `TicketMailboxAccessTest.php`, `CommentShieldPolicyTest.php` | Browser-only rendering or JavaScript regressions can still escape component tests. | Add Playwright/browser smoke coverage when an E2E environment is available. |
| GAP-009 | Medium | Business rules hardcoding | Ticket responsibility assignment still contains hardcoded problem-category and user IDs; the previously unreachable category branch is fixed and invalid candidates now fall back to the destination inbox. | `Ticket.php` | Assignment rules remain difficult to configure and validate operationally. | Replace with a small database-backed mapping if stakeholders want UI management. |
| GAP-010 | Low | IA consistency | Problem Category and Ticket Status resources are not in `Master Data` navigation group while related resources are. | Resource classes | Navigation may feel inconsistent for administrators. | Normalize IA after stakeholder approval. |
| GAP-011 | Low | Test placeholders | Default example tests remain in the suite. | `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | Low signal in test suite; can obscure coverage quality. | Replace with meaningful smoke checks or remove after coverage plan. |
| GAP-012 | Low | Attachment model clarity | Comment fillable includes `attachments`; base comment migration shown creates no attachment column, though a later migration may add one. | `Comment.php`, `CommentsRelationManager.php`, migrations | Comment attachment persistence should be confirmed end-to-end. | Confirm schema and add a comment attachment test. |

## Artifact Coverage By Chain Of Truth Phase

| Phase | Existing before this work | Reconstructed now | Gap |
|---|---|---|---|
| SRS | README only, high-level | `srs.md` | Needs stakeholder validation |
| IA | Implicit in Filament resources | `information-architecture.md` | Needs route verification and stakeholder review |
| Design System | Implicit Filament config | `design-system.md` | No approved design system |
| User Flows | Implicit code/tests | `user-flows/*` | Needs validation |
| HiFi Prototype | Screenshots referenced in README | `prototype-validation.md` | No navigable prototype evidence |
| Data Model | Migrations/models/README image | `data-model.md` | Needs schema audit and ERD update |
| UCIC | Implicit services/tests | `ucic/*` | Needs MCP transport and identity-contract validation |
| Implementation | Present | Baseline documented | Several drift risks |
| Testing | PHPUnit feature/unit tests | `test-plan.md`, `test-cases.md`, `test-execution.md` | Missing E2E and web workflow coverage |

## Recommended Artifact Repair Order

1. Validate actor and role names plus MCP transport and reporter-identity boundaries in `srs.md`.
2. Repair data model decisions for user-unit membership, ticket history, and attachment schema.
3. Validate UCIC replay, reporter binding, and ticket-creation contracts against deployment topology.
4. Add tests for role-filtered tickets, Filament workflow transitions, comments, and production-facing MCP authentication behavior.
5. Only after artifact validation, implement source code fixes.
