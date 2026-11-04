# Moderator Activity Tracker

WordPress / WooCommerce plugin that logs moderator actions and reports on order upselling.
Admin UI is Arabic (RTL).

## Features

### Activity log (سجل نشاط المشرفين)
- Logs every order status change (`من ← إلى`) and product update, per user (all roles).
- Status names are read **dynamically** from WooCommerce (custom statuses work automatically).
  Built-in statuses (pending, processing, on-hold, completed, cancelled, refunded, failed) are shown in Arabic, including old English rows.
- Filters: user (autocomplete by name / username / email), date range, order ID, **from-status** and **to-status**.
- Stats cards: total, today, this week, active moderators (7 days).
- Bulk delete, **delete all** (admins only, with confirmation), pagination that keeps filters.

### Upsell report (تقرير الـ Upsell)
Data only — **no commission calculation**; apply your own rules to the numbers.
- Tracks what each user **adds / removes / changes (qty or price)** on existing orders.
- Summary per moderator: orders, changes, total added, total removed, net.
- Details table with order link, product, quantity before → after, amount delta, current order status.
- Filters: user search (autocomplete), date range, order ID, current order status.
- CSV export (Excel-friendly Arabic).
- Not tracked:
  - orders created manually by a moderator in wp-admin (no customer original to compare);
  - changes made while the order is in an **excluded status** (default slugs `3, 10, 11, 30, 31, 32, 33`).

## Permissions
| Capability | Purpose |
|---|---|
| `view_moderator_activity` | See the activity log, upsell report, export and user suggestions. Granted to `administrator` and `shop_manager` automatically; assign to other roles in **User Role Editor**. |
| `manage_options` | Delete log entries / delete all. |

Tracking itself applies to every logged-in user working in wp-admin (any role, e.g. `reviewer`).

## Configuration
- **Excluded statuses for upsell:** edit the array in `mat_upsell_excluded_statuses()` or use the filter:
  ```php
  add_filter('mat_upsell_excluded_statuses', fn($s) => ['3', '10', '11']);
  ```
- Slugs are compared without the `wc-` prefix.

## Database
Created automatically (activation / first admin load):
- `{prefix}moderator_activity` — activity log.
- `{prefix}moderator_upsell` — upsell events.

## Installation
1. Copy `moderator-activity-tracker.php` to `wp-content/plugins/moderator-tracker/`.
2. Activate it (or deactivate → activate after updating).
3. Open **Moderator Activity** in the admin menu.

## Known notes
- Upsell history starts from when this version is installed.
- Activity-log order links use `post.php`; stores with **HPOS** need `admin.php?page=wc-orders&action=edit&id=ID` (upsell report already uses WooCommerce's own order URL).
- If a product is added twice, WooCommerce may merge it into one line and it appears as a quantity change.

## Changelog
- **1.1** — security fixes (nonces, capability checks, no `nopriv` AJAX), dynamic statuses, Arabic RTL redesign, user autocomplete, status filters, delete all, upsell report, excluded statuses, `view_moderator_activity` capability.
- **1.0** — initial version.

