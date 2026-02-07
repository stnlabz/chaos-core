# ChAoS CMS Core

## Version
v2.0.9

## Status
**Stable**
Project is stable.

## Main is Development
 - Do not consider anything here on this page as **Latest**, 2.0.9 is in the **Releases**

   **Things to note**
   - The **ChAoS CMS** does not rely on **Composer**
---

## Summary
This release finalizes the Chaos CMS core, installer, updater, and admin tooling.
Architecture, update flow, and runtime behavior are now documented, versioned, and aligned.

This marks the first **monitized** core of ChAoS CMS Core.
This also marks the very first `sql` migration of the ChAoS CMS Core

---

## Highlights

### Core
- Core runtime stabilized under `/app`
- Bootstrap hardened with filesystem-first theme fallback
- Default theme always loads if DB configuration is missing or invalid
- Core behavior fully documented
- Core modules updated

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
- `SQL` Migration and Update

### Admin
- Dashboard stabilized and corrected
- Topics management tool added
- Roles management tool added
  - Admin role (id 4) protected from deletion
  - Roles in use cannot be deleted
- CSRF conflicts resolved via view-scoped tokens for admin tools
- Admin CSS stabilized and centralized

### Content
- Posts and Media are now Monetized
- Topics implemented as global taxonomy
- Posts reference topics via `topic_id`
- Topic selection already present in post editor
- `public/plugins/filter` provides default content filtering
 

## Documentation
- Core architecture documented
- Bootstrap and theme resolution documented
- Router behavior documented
- Admin system and tooling documented
- Updater process documented
- Database contract documented

---

## Breaking Changes
 - Core theme with user theme override
 - Video upload and playback
 - enhanced role awareness
 - built in Search Engine Optimization (SEO) xml generation.
 - Stripe webhook to manage Media and Post Premium and Pro content
 - Automated `SQL` Updating
 - Social aspects of `posts` and `media`
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
This release is a continuance of the the core foundation.  
Future versions will build **on top of this**, not rewrite it.
