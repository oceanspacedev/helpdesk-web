# Test Plan As-Is

Status: Reviewed

## Strategy

Tests are derived from reconstructed User Flows and UCIC. Existing tests provide useful coverage for integration and auth paths, while web admin workflows need additional coverage.

## Test Types

- Unit tests: phone normalization, gateway provider behavior, pure classification resolution where practical.
- Feature/MCP tests: discovery of only `helpdesk_intake`, the existing-account-to-ticket path, the prerequisite-account-to-ticket path, cancellation without writes when account creation is declined, classification validation, idempotency/replay, Socialite linking, OTP verification, and stable versus omitted external-user binding behavior.
- Filament feature or browser tests: ticket creation, workflow actions, comments, master data, role-scoped visibility.
- Security tests: MCP transport authentication, cross-user intake isolation, trusted-assertion replay, canonical-phone collisions, and refusal to trust `reporter_name` as inline registration data.

## Environments

- Observed test environment uses PHPUnit and Laravel test helpers.
- Existing tests often build minimal schemas directly rather than running all migrations.
- No browser test runner configuration was observed.

## Residual Risk

Web UI behavior is mostly untested by automated E2E or browser tests. Production MCP gateway, cache, reverse-proxy, and credential-rotation behavior remains unverified outside the isolated test environment.
