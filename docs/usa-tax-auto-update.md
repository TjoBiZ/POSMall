# POSMall USA Tax Auto-Update

POSMall can stage and import USA tax records from configured official-source adapters. A scheduled
tax refresh is intentionally opt-in at two levels:

1. Enable **Auto-update USA taxes daily** in POSMall general settings.
2. Open each source-backed tax row and enable **Auto-update this tax from source**.

The server must also run the POSMall tax updater from cron during a low-traffic hour. POSMall shows
an installation-specific fixed daily time in the backend so copied cron entries do not all hit
official sources at the same minute. The recommendation is deterministic per installation, and 80%
of installations are distributed across the 00:00-05:59 low-traffic window.

Copy the exact command shown in this installation's POSMall settings. Do not copy a fixed example
from another website, because every installation should keep its own stable recommended time:

```cron
<minute> <hour> * * * cd /path/to/site && php artisan posmall:usa-taxes:update >> /dev/null 2>&1
```

If the project already uses Laravel's scheduler, POSMall also registers the tax updater internally
at the same installation-specific daily time, but the direct cron entry above is the clearest setup
for this feature. Do not run tax source updates every minute.

Automatic updates only refresh existing source-backed rows that are individually opted in. They do
not create new live tax rows. If a source returns `0%` for a row that previously had a positive
rate, POSMall skips the automatic update and leaves the row for manual review.

Manual reviewed imports can still import legitimate zero-rate records. POSMall helps maintain
rates, but the merchant remains responsible for tax correctness and compliance.

Backend UX notes:

- The Taxes list shows an **Auto-update** column for each tax row.
- The column contains an expandable info hint that explains the required daily low-traffic-hour
  cron entry and whether POSMall could detect it for the current system user.
- In the Taxes list, the top cron callout is shown only when the main Taxes table has rows. It is
  titled **USA tax auto-update** when at least one tax row has an `Applies to states` value, and
  **Tax auto-update** for source-backed non-state tax rows.
- Clicking the Auto-update switch in the list updates the row flag in place. It must not open the
  tax edit form; row navigation remains available when clicking outside the switch/info control.
- Other functional controls inside the Taxes table, such as info details, inputs, buttons,
  dropdowns and AJAX controls, also keep their own behavior instead of opening the row editor.
- **Enable source auto-update** turns the row flag on only for imported/source-backed rows.
- **Disable all auto-update** clears the row flag from all rows.
- Product, service, category and payment-method Tax relation popups use the same Tax form field,
  so the per-row opt-in can be changed from the places where a tax is attached to business data.
- POSMall tries to inspect the current system user's crontab in read-only mode. If the tax updater
  entry is missing or cannot be checked from PHP, the backend shows the required cron command.
  The plugin does not modify server cron automatically.
