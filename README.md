# Chaos CMS Core

## Version
v2.0.7

## Status
**Stable**  
Project is frozen pending bug discovery and fixes only.

---

## Summary
This release finalizes the Chaos CMS core, installer, updater, and admin tooling.  
Architecture, update flow, and runtime behavior are now documented, versioned, and aligned.

This marks the first **release-grade** core of Chaos CMS.

---

## Highlights

### Core
- Core runtime stabilized under `/app`
- Bootstrap hardened with filesystem-first theme fallback
- Default theme always loads if DB configuration is missing or invalid
- Core behavior fully documented

### Installer
- Schema-only database creation (no silent seeding)
- First user created explicitly as **Admin (role id 4)**
- Installer writes config and exits cleanly
- No magic defaults beyond explicit installer actions

### Updater
- Working end-to-end update pipeline
- Remote version manifest via version domain
- Package download with SHA256 verification
- Lock + maintenance enforcement during updates
- Safe failure recovery (maintenance + lock released on error)

### Admin
- Dashboard stabilized and corrected
- Topics management tool added
- Roles management tool added
  - Admin role (id 4) protected from deletion
  - Roles in use cannot be deleted
- CSRF conflicts resolved via view-scoped tokens for admin tools
- Admin CSS stabilized and centralized

### Content
- Topics implemented as global taxonomy
- Posts reference topics via `topic_id`
- Topic selection already present in post editor

### Documentation
- Core architecture documented
- Bootstrap and theme resolution documented
- Router behavior documented
- Admin system and tooling documented
- Updater process documented
- Database contract documented

---

## Breaking Changes
None.

---

## Known Constraints
- Core is frozen; only bug fixes will be accepted.
- No automatic role or theme seeding.
- Permissions beyond roles are not implemented in this release.

---

## Upgrade Notes
- Updates must be performed via the built-in updater.
- Manual overwrites are not recommended.
- Ensure filesystem permissions allow:
  - `/app/update/*`
  - `/app/data/*`

---

## Philosophy
Chaos CMS favors:
- Explicit behavior over automation
- Filesystem truth over database assumptions
- Predictable failure over silent mutation
- Simplicity over abstraction

---

## Final Note
This release establishes the foundation.  
Future versions will build **on top of this**, not rewrite it.
