# Views Time Cache — Feature List

## Cache plugin

- Adds a **"Time-based (presets & cron)"** cache option to every Views display
- Selectable from the standard Views Caching UI (Advanced → Caching)
- Applies a single duration to both query results and rendered output

## Preset intervals

- Six ready-to-use intervals: **1 hour**, **6 hours**, **12 hours**, **1 day**,
  **1 week**, **Forever**
- **Forever** disables time-based expiry while keeping content cache-tag
  invalidation active

## Cron expression mode

- Enter any standard **5-field cron expression** (`minute hour dom month dow`)
- Cache expires precisely at each cron boundary — not merely "N seconds after
  creation"
- Supports wildcards (`*`), ranges (`1-5`), steps (`*/15`, `0-23/2`), and
  comma-separated lists (`0,15,30,45`)
- Day-of-month and day-of-week use standard cron **OR logic** when both are
  specified
- Accepts `7` as an alias for Sunday in the day-of-week field

## Validation and error handling

- Cron expressions are validated on save; invalid expressions are rejected with
  a descriptive error message
- On runtime parse failure the plugin falls back to "never expire" (safe default)

## No external dependencies

- Self-contained cron evaluator — no extra Composer packages required
- Drop-in compatible with any Drupal 10.2+ or 11.x site
