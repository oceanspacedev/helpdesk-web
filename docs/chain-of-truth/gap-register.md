# Gap Register

Status: Reviewed  
Ranking model: impact, likelihood, blast radius, and urgency based on observed implementation.

| ID | Severity | Category | Gap | Evidence | Impact | Recommended repair order |
|---|---|---|---|---|---|---|
| GAP-001 | High | Security / integration | WhatsApp integration routes have no observed route middleware authentication, relying on payload `actor.is_verified`. | `routes/api.php`, `WhatsappHelpdeskActionService.php` | API may depend entirely on upstream trust; unauthorized callers could attempt ticket actions if network-exposed. | Define API trust boundary in SRS and UCIC, then implement signature/API-key/middleware if required. |
| GAP-002 | High | Authorization / data visibility | Staff ticket query uses `whereIn('tickets.owner_id', [$user->id, 'tickets.responsible_id'])`, treating `tickets.responsible_id` as a literal value rather than column comparison. | `TicketResource.php` | Staff Unit users may not see assigned tickets in list despite policy intent. | Repair user-flow/policy contract, then fix query and add coverage. |
| GAP-003 | High | Role model conflict | Role name is seeded as `Staff Unit`, but several notification and visibility paths check `Staf Unit`. | `RoleSeeder.php`, `Ticket.php`, `Comment.php`, `CommentsRelationManager.php`, `README.md` | Notifications or authorization branches may miss intended support staff. | Choose canonical role in SRS, update policies/resources/seeders consistently, add tests. |
| GAP-004 | Medium | Authorization templates | Multiple policies still contain generated placeholder permission strings for replicate and reorder actions. | `app/Policies/*.php` | Permission checks for replicate/reorder and some role actions may never work as intended and indicate generated code leftovers. | Define permission matrix, regenerate or repair policies, test affected actions. |
| GAP-005 | Medium | Data model consistency | `users.unit_id` coexists with `user_entities` many-to-many unit relation; resources and queries use both patterns. | `User.php`, `UserResource.php`, migrations, `TicketResource.php`, `ProblemCategoryResource.php` | Unit scoping can diverge between legacy and morph relation data. | Decide authoritative user-unit model in data model, migrate queries, add regression tests. |
| GAP-006 | Medium | Relationship mismatch | `TicketHistory::ticket()` belongs to `Ticket` using `tiket_id`, while table and fillable use `ticket_id`. | `TicketHistory.php`, ticket history migration | Ticket history relationship may fail when dereferenced. | Correct data-model artifact, then implementation and tests. |
| GAP-007 | Medium | Documentation drift | README documents base helpdesk features but omits ITA/WhatsApp APIs, OTP login, nullable email reporters, business entities, and current constraints. | `README.md`, integration and auth code | Onboarding and operational handoff miss current behavior. | Replace README or link to Chain of Truth docs after stakeholder review. |
| GAP-008 | Medium | Test coverage | No observed E2E or feature coverage for Filament web ticket lifecycle, role-filtered list views, workflow buttons, comments UI, or master data resources. | `tests/Feature`, `tests/Unit` | Core web workflows can regress without automated evidence. | Add targeted feature/browser tests from user flows and UCIC. |
| GAP-009 | Medium | Business rules hardcoding | Ticket responsibility assignment contains hardcoded problem category IDs and an unreachable overlap branch for category IDs 9 and 10. | `Ticket.php` | Assignment may be stale and difficult to validate or maintain. | Document assignment rules in SRS/data model, replace with configurable mapping if approved. |
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
| UCIC | Implicit controllers/services/tests | `ucic/*` | Needs API/auth boundary decision |
| Implementation | Present | Baseline documented | Several drift risks |
| Testing | PHPUnit feature/unit tests | `test-plan.md`, `test-cases.md`, `test-execution.md` | Missing E2E and web workflow coverage |

## Recommended Artifact Repair Order

1. Validate actor/role names and API trust boundary in `srs.md`.
2. Repair data model decisions for user-unit membership, ticket history, and attachment schema.
3. Update UCIC for WhatsApp integration authentication and ticket list visibility.
4. Add tests for role-filtered tickets, Filament workflow transitions, comments, and integration authentication.
5. Only after artifact validation, implement source code fixes.
