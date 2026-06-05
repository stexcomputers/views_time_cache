# Views Time Cache

## Introduction

Views Time Cache provides a **time-based caching plugin** for Drupal Views that
extends the built-in "Time-based" option with friendlier presets and an extra
cron expression mode:

- **Friendly preset intervals**: 1 hour, 6 hours, 12 hours, 1 day, 1 week, Forever
  — separate selections for **Query results** and **Rendered output**
- **Cron expression mode**: enter a standard 5-field cron expression
  (`0 */6 * * *`) and the cache expires exactly at each matching boundary
- **No extra dependencies**: the cron evaluator is self-contained — no additional
  Composer packages required

"Forever" disables time-based expiry while keeping Drupal's content cache-tag
invalidation active, so the view still rebuilds when its underlying content changes.

## Requirements

- Drupal 10.3 or higher, or Drupal 11.x
- Views (included in Drupal core)

## Installation

Install as you would any contributed module. With Composer:

```bash
composer require drupal/views_time_cache
drush en views_time_cache
```

Or download and place in `web/modules/contrib/views_time_cache/`, then enable via
**Admin → Extend** or with Drush.

## Configuration

1. Edit any View.
2. Under **Advanced → Caching**, click the current cache setting.
3. Select **Time-based (presets & cron)** from the plugin dropdown.
4. Choose **Preset interval** mode and pick durations for **Query results** and
   **Rendered output**, or switch to **Cron expression** mode and enter your
   expression (e.g. `0 6 * * *` to refresh daily at 6 AM).
5. Save the View.

### Cron expression reference

```
┌───────────── minute        (0–59)
│ ┌─────────── hour          (0–23)
│ │ ┌───────── day of month  (1–31)
│ │ │ ┌─────── month         (1–12)
│ │ │ │ ┌───── day of week   (0–7, 0 and 7 = Sunday)
│ │ │ │ │
* * * * *
```

| Expression      | Meaning                        |
|-----------------|--------------------------------|
| `0 * * * *`     | Every hour                     |
| `0 */6 * * *`   | Every 6 hours (0, 6, 12, 18)   |
| `0 0 * * *`     | Daily at midnight              |
| `0 0 * * 1`     | Weekly on Monday at midnight   |
| `*/15 * * * *`  | Every 15 minutes               |

When **both** day-of-month and day-of-week are specified, standard cron OR logic
applies — the cache expires if **either** condition matches.

## Block cache max-age

In addition to the Views cache plugin, this module lets administrators set the
render-cache max-age directly on **any block** placed via Block Layout.

### Permission

Grant **Administer block cache max-age** to trusted roles (e.g. Administrators).
The field is hidden from users without this permission.

### Usage

1. Go to **Structure → Block layout** and click **Configure** on any block.
2. Expand the **Cache max-age** fieldset (visible only when permission is granted).
3. Choose a mode:
   - **No override** — leave the block plugin's default max-age unchanged.
   - **Preset interval** — pick a duration (1 hour through 1 week, or *Forever*).
   - **Cron expression** — enter a 5-field expression; the block cache expires at
     each matching boundary.
4. Save the block configuration.

### Important note on caching semantics

Drupal merges cacheability metadata using the **minimum** max-age across the
entire render subtree.  This means:

- The chosen max-age **replaces** what the block plugin itself declares.
- It is still bounded below by the block's content — if the rendered content
  declares max-age 0 (e.g. a CSRF token, a one-time message), that wins and the
  block remains uncacheable.
- *Forever* stores the entry until a relevant cache-tag invalidation occurs (e.g.
  when the content the block depends on is saved).

## Maintainers

Current maintainers:
- [jbuttler](https://www.drupal.org/u/jbuttler)
