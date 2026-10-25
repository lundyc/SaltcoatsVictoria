# WordPress-Style User Access Simplification Plan

## Objective

Replace the overlapping account-role and access-template experience with one simple administrator workflow:

> Add a person's email → they can log in as a Member → optionally choose one administrative role.

Authentication (password or Google) and authorisation (what the person may do) remain separate internally, but the administrator should not need to manage a separate account record.

## Agreed behaviour

- A valid, unique email saved through **People & Users** automatically creates and activates that person's login account.
- A new account receives the default **Member** role and members-area access only.
- Password and Google are alternative login methods for the same account.
- One primary access role is presented in the UI, like WordPress.
- Roles grant capabilities; protected pages continue to check capabilities.
- **Administrator** grants complete Hub access.
- Club positions describe responsibilities and do not grant access.
- Individual permission additions/removals remain available under an advanced section.
- Removing an email disables login without deleting the person, tickets, history or audit records.
- Archiving a person disables login.
- Dependant children may remain without an email/account and use their manager's account.
- Login email addresses remain unique.

## Delivery principles

- Preserve all existing working logins during migration.
- Migrate data before removing compatibility behaviour.
- Make identity changes transactional and auditable.
- Never silently broaden a person's administrative access.
- Keep a rollback path until the migrated access results have been verified.

## Phase 1 — Audit and compatibility map

- [x] Trace people, accounts, account roles, access templates, capabilities, overrides and club positions.
- [x] Confirm that member login and Hub login currently use different eligibility rules.
- [x] Audit aggregate production data without exposing personal details.
- [x] Build a deterministic mapping from every current user to a single primary access role.
- [x] Identify and report ambiguous users before migration.
- [x] Add automated checks describing permitted Hub admission for Member, capability roles and Administrator.

## Phase 2 — Establish one authoritative role model

- [x] Add/confirm a system **Member** access role with no Hub capabilities.
- [x] Store exactly one primary access role for each account/person.
- [x] Map existing Public accounts to Member.
- [x] Map existing Administrator accounts to Administrator.
- [x] Preserve existing functional roles such as Treasurer, Chairman and Social Media Admin.
- [x] Stop using the legacy account role as an independent permission source for Hub admission.
- [x] Make Hub eligibility derive from the selected access role's effective capabilities.
- [x] Retain a temporary compatibility write for legacy consumers.

## Phase 3 — Automatic account lifecycle

- [x] Create a Member account automatically when a valid unique email is added.
- [x] Keep person and account email addresses synchronised in one transaction.
- [x] Make matching verified Google login work immediately.
- [x] Let passwordless users establish a password through the existing reset/welcome flow.
- [x] When an email is removed, disable the account and preserve its history.
- [x] When an email is restored or replaced, safely reactivate/update the existing account.
- [x] Keep archived/inactive people unable to log in and revoke their existing sessions.
- [x] Return clear validation for duplicate/shared login emails.
- [x] Preserve managed-dependant behaviour for children without their own email.

## Phase 4 — Simplified person interface

- [x] Remove the separate **Account** tab.
- [x] Add a compact **Login** panel to Details showing email, status, verification, Google link and last login.
- [x] Keep only useful actions: send a sign-in link, create a Google link and set a temporary password under Advanced.
- [x] Replace multiple role checkboxes with one WordPress-style **Role** dropdown.
- [x] Show a plain-English summary of the selected role's access.
- [x] Put individual capability exceptions in a collapsed **Advanced permissions** section.
- [x] Clearly state that Club positions do not grant system access.
- [x] Add responsive layout, keyboard-operable controls, labelled tabs, live role descriptions and focused validation feedback.
- [ ] Complete a hands-on screen-reader pass in a browser.

## Phase 5 — Migration and cutover

- [x] Create an idempotent migration/backfill script.
- [x] Produce aggregate before/after access checks for affected accounts.
- [x] Abort migration rather than guessing when non-Administrator multi-role assignments are ambiguous.
- [x] Apply the migration and record it in schema migrations.
- [x] Keep legacy role data synchronised during the observation period.
- [x] Log account creation, email changes, role changes, activation and deactivation.

## Phase 6 — Verification

- [x] Test Member account lifecycle and Hub denial.
- [ ] Test ordinary Member password login end-to-end.
- [ ] Test ordinary Member Google login end-to-end.
- [ ] Test password reset and welcome links.
- [x] Test configured capability-bearing roles for Hub admission.
- [x] Test Administrator full access admission.
- [x] Test that Member cannot enter the admin Hub.
- [x] Test manual login disable, email-removal disable, session revocation and final-Administrator protection.
- [x] Test transactional email addition/account creation and email removal/login disable.
- [ ] Test dependants and manager visibility.
- [ ] Test season tickets, event tickets and scanning identity links.
- [x] Run PHP and JavaScript syntax checks and targeted database-backed lifecycle/authorisation checks.
- [x] Complete architecture, security and UI/UX reviews and address the blocking findings.

## Phase 7 — Retire redundant internals

- [ ] Remove the legacy base-role decision path after the observation period.
- [ ] Remove obsolete Account-tab code and unused styles.
- [ ] Remove obsolete dual-write paths only after all legacy consumers are migrated.
- [ ] Update help content and administrator guidance.

## Rollback strategy

- Take a database backup before the data migration.
- Keep the original account-role rows during the compatibility period.
- Make migrations idempotent and record their completion.
- Retain a comparison report so previous access can be restored deterministically.
- Roll back code independently of migrated data while compatibility writes remain enabled.

## Review responsibilities

- **Implementation reviewer:** architecture, correctness, maintainability and migration improvements.
- **Security reviewer:** authentication, authorisation, privilege escalation, session handling, CSRF, account enumeration and token safety.
- **UI/UX reviewer:** administrator workflow, wording, accessibility, responsive behaviour and error recovery.

## Progress log

- 2026-10-20: Existing system audited. Nineteen active people have email addresses; six active login accounts exist; thirteen emailed people have no account. Access currently spans account roles and person access templates.
- 2026-10-20: Plan created and implementation started.
- 2026-10-20: Primary-role migration applied. Six existing accounts retained access, each now has exactly one role, and no compatibility mismatches remain.
- 2026-10-20: Email-driven Member login lifecycle, capability-based Hub admission, session revocation and simplified person UI implemented.
- 2026-10-20: Independent implementation, security and UI/UX reviews completed. Member capabilities are now immutable, all role writes enforce one primary role, account reconciliation owns its transaction, token consumption is atomic, Google ID tokens are verified with Google, and the final active Administrator is protected.
- 2026-10-20: Live invariant checks passed: Member has zero Hub capabilities, no person has multiple roles, and no ambiguous non-Administrator assignment remains. PHP/JavaScript syntax and rolled-back service guards passed.
