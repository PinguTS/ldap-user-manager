# Frontend Upgrade: Bootstrap 3 → 5

This document records the Bootstrap 5 frontend upgrade (Priority 3 of the project improvement plan).

## Summary

- **Bootstrap:** Replaced `www/bootstrap/` with Bootstrap 5.3 (CSS: `bootstrap.min.css`, JS: `bootstrap.bundle.min.js`). Bootstrap 5 does not require jQuery for its components.
- **Icons:** Glyphicons (removed in BS5) replaced with [Bootstrap Icons](https://icons.getbootstrap.com/) loaded from CDN.
- **Shared layout:** CSS and JS are loaded once in `www/includes/web_functions.inc.php` via `render_header()`. All pages that call `render_header()` use the same Bootstrap 5 assets.

## Files Changed

### Global (all pages)

| File | Changes |
|------|---------|
| `www/includes/web_functions.inc.php` | Bootstrap 5 CSS/JS and Icons links; navbar (BS5 structure, `nav-link`, `navbar-nav`); alert close (`btn-close`, `data-bs-dismiss`); `has-error` → `is-invalid`; `btn-default` → `btn-secondary`; `form-group` compat style; `control-label` → `form-label` in attribute fields; `input-group-btn` removed |
| `www/includes/module_functions.inc.php` | Submenu navbar updated to BS5 (`navbar-nav`, `nav-link`, `active` on link) |
| `www/bootstrap/` | Replaced with Bootstrap 5.3 dist (css + js). Glyphicons and theme CSS removed. |

### Page-specific

| File | Changes |
|------|---------|
| `www/change_password/index.php` | Panel → card; `col-sm-offset-3` → `offset-sm-3`; `control-label` → `form-label`; `btn-default` → `btn-secondary`; `has-error` → `is-invalid` |
| `www/log_in/index.php` | Panel → card; offset; `control-label` → `form-label`; `btn-default` → `btn-secondary` |
| `www/request_account/index.php` | Panel → card; glyphicon → bi icon; `btn-default` → `btn-secondary`; offset; `control-label` → `form-label` |
| `www/setup/index.php` | Panel → card; `btn-default` → `btn-secondary` |
| `www/setup/ldap.php` | Panel → card |
| `www/setup/run_checks.php` | Panel → card; `data-toggle` → `data-bs-toggle`, `data-content` → `data-bs-content`, `data-bs-title`; `pull-right` → `float-end`; popover init switched to vanilla `bootstrap.Popover` |
| `www/setup/verify.php` | Panel → card (default, warning, success); `btn-default center-block` → `btn-secondary d-block mx-auto` |
| `www/manage/index.php` | Panel → card (primary, success, info, warning); card headers with bg colors; `panel-title` → `card-title` |
| `www/manage/users/index.php` | Modals: `close` → `btn-close`, `data-dismiss` → `data-bs-dismiss`, `h4.modal-title` → `h5`; `btn-default` → `btn-secondary`; glyphicon → bi; alert dismissible; modal JS → `bootstrap.Modal.getOrCreateInstance()`; `badge-info` → `bg-info`; `text-right` → `text-end` |
| `www/manage/users/new.php` | Panel → card; glyphicon → bi; `has-error` → `is-invalid`; `input-group-btn` removed (button direct in input-group); `btn-default` → `btn-secondary` |
| `www/manage/users/show.php` | Panel → card; `has-error` → `is-invalid`; `btn-default` → `btn-secondary`; card headers with bg |
| `www/manage/roles/index.php` | Panel → card; `label label-*` → `badge bg-*`; `pull-right` → `float-end` |
| `www/manage/organizations/index.php` | Panel → card; glyphicon → bi; modal close/title/BS5; modal JS → `bootstrap.Modal.getOrCreateInstance()`; `btn-default` → `btn-secondary`; alert close `btn-close`; badge classes |
| `www/manage/organizations/add.php` | Alert close → `btn-close`; panel → card; `btn-default` → `btn-secondary` |
| `www/manage/organizations/show/index.php` | Panel → card; `btn-default pull-right` → `btn-secondary float-end`; `btn-default` → `btn-secondary` |
| `www/manage/organizations/users/index.php` | Modal close → `btn-close`, `data-bs-dismiss`; `btn-default` → `btn-secondary`; modal JS → Bootstrap 5 API; duplicate bootstrap script removed |
| `www/manage/organizations/users/add.php` | Panel → card; `col-sm-offset-3` → `offset-sm-3`; `btn-default` → `btn-secondary`; duplicate bootstrap script removed |
| `www/js/zxcvbn-bootstrap-strength-meter.js` | Progress bar classes updated for BS5 (`progress-bar-*` → `bg-*`, `active` → `progress-bar-animated`) |

## Class / attribute mapping (reference)

| Bootstrap 3 | Bootstrap 5 |
|-------------|-------------|
| `data-dismiss` | `data-bs-dismiss` |
| `data-toggle` | `data-bs-toggle` |
| `data-target` | `data-bs-target` |
| `data-content` / `title` (popover) | `data-bs-content` / `data-bs-title` |
| `.close` | `.btn-close` |
| `.btn-default` | `.btn-secondary` |
| `.panel`, `.panel-default` | `.card` |
| `.panel-primary` (etc.) | `.card.border-primary` + `.card-header.bg-primary.text-white` |
| `.panel-heading` | `.card-header` |
| `.panel-body` | `.card-body` |
| `.panel-title` | `.card-title` |
| `.glyphicon.glyphicon-*` | `<i class="bi bi-*">` (Bootstrap Icons) |
| `.pull-left` / `.pull-right` | `.float-start` / `.float-end` |
| `.col-*-offset-*` | `.offset-*-*` |
| `.has-error` | `.is-invalid` |
| `.control-label` | `.form-label` |
| `.label.label-*` | `.badge.bg-*` |
| `.badge-*` | `.bg-*` (badges) |
| `.navbar-default` | `.navbar-light.bg-light` (or similar) |
| `.nav.navbar-nav` | `.navbar-nav`; `.nav-item` + `.nav-link` |
| `.navbar-right` | `.ms-auto` on nav or separate `ul.navbar-nav` |
| `.input-group-btn` | Button as direct child of `.input-group` |
| `.center-block` | `.d-block.mx-auto` |
| `.text-right` | `.text-end` |

## Verification

- Click through: login, user list, org list, org users, modals (delete/lock/unlock), forms (new user, change password, request account).
- Confirm layout and interactions work and there are no console errors.
- Optional: run a quick search for `glyphicon`, `btn-default`, `data-dismiss`, `panel panel-` to ensure no remaining BS3-only usage in `www/`.

## Optional: JS build (Task 3.5)

If a frontend build step (e.g. npm) is introduced later, document it (e.g. npm scripts) and keep minified assets in version control or build in CI so Docker does not require Node.
