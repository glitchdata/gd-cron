# GD Cron Manager

A lightweight WordPress admin tool to inspect, run, delete, and schedule WP-Cron events.

## Installation

1. Copy the `gd-cron` folder into your WordPress `wp-content/plugins` directory.
2. Activate **GD Cron Manager** from **Plugins** in wp-admin.

## Usage

- Go to **Tools → Cron Manager**.
- See all scheduled events with their next run time, recurrence, and arguments.
- Actions per event:
  - **Run now**: immediately triggers the hook with its stored arguments.
  - **Delete**: unschedules the specific timestamp/args pair.
  - **Edit**: opens a dedicated page to change next run time, recurrence, and arguments; the event is unscheduled and rescheduled with the new values. One-off events show as **Once**.
- Schedule new events using the form:
  - Enter a hook name (e.g. `my_custom_hook`).
  - Optionally provide JSON arguments (e.g. `["foo", 123]`).
  - Set the first run time in your site timezone (format `YYYY-MM-DD HH:MM`).
  - Choose **Once** or any registered recurrence schedule.

### Settings

- Default first-run offset (seconds) when no time is provided.
- Default recurrence for new events.
- Toggle delete confirmation prompts.

## Notes and cautions

- Running hooks manually executes the attached callbacks immediately; use with care on production.
- Deleting an event only removes the selected timestamp/args instance.
- If scheduling fails, a duplicate event with the same timestamp/arguments likely already exists.
