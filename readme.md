# Flinkform Pro

Paid add-on for the free [Flinkform](https://wordpress.org/plugins/flinkform/)
form plugin. Separate plugin, **not** distributed via WordPress.org — sold with a
license key and auto-updated from a dedicated endpoint (licensing integration:
planned via Freemius, not yet wired).

## Features (v1.2.0)

- **Stripe Payments** — collect payments directly in forms via the **Stripe
  Payment Element**: credit card, **SEPA Direct Debit**, Apple Pay, Google Pay
  and Link (PCI-compliant, payment data never touches the server). Fixed
  amount or product choices with radio buttons. Server-side verification binds
  amount AND currency to the form definition (fail-closed, tamper-proof), and
  a UNIQUE-keyed payments table guarantees one intent = one submission
  (replay protection). Automatic Stripe receipt via `receipt_email`. Admin
  settings page for API keys (AES-256 encrypted). Supports EUR, USD, GBP, CHF.
- **Payment status model** — every verified PaymentIntent is tracked
  (status, amount, currency, method) and rendered on the submission detail
  screen. Asynchronous methods (SEPA) are accepted as `processing` and
  settled later by the **Stripe webhook receiver**
  (`/flinkform-pro/v1/stripe-webhook`, native `hash_hmac` signature check, no
  SDK): succeeded / failed / canceled / refunded, with a
  `flinkform_payment_status_updated` action for site integrations.
- **Calculation field** — live quote/configurator totals from author formulas
  (`({field:qty} * 49.90) + {field:setup}`). Safe tokenise → shunting-yard →
  RPN evaluator (no `eval`), mirrored in JS (live preview) and PHP
  (authoritative server-side recompute on submit). Insert-field dropdown in
  the inspector, decimals, prefix/suffix.
- **File Upload field** — single or **multi-file (up to 10 per field)**,
  per-field type allow-list (ext+content sniffing), size cap enforced client-
  and server-side, atomic rollback when one file of a set is rejected,
  randomised storage in a script-execution-blocked uploads subdirectory,
  download links on the submission detail view, automatic file deletion with
  the submission (GDPR cascade).
- **SMTP delivery** — route all `wp_mail()` through a configured SMTP provider.
  7 provider presets (Gmail, Outlook, SendGrid, Mailgun, Brevo, Postmark,
  Amazon SES), AES-256-encrypted credentials, conflict detection with other
  SMTP plugins, test-email diagnostics with custom recipient.
- **SMTP send log** — per-mail history (recipient, subject, sent/failed with
  the exact PHPMailer error). GDPR-lean: no mail bodies, configurable
  retention (default 30 days), covered by the personal-data exporter and eraser.
- **Webhooks** — per-form webhooks with JSON/form-encoded payloads, custom
  headers, field mapping, conditions, cron-driven dispatch with retries and a
  full delivery log (pruned daily after 90 days, filterable). SSRF-hardened.
- **CSV export** — export filtered submissions (incl. date range) from the
  admin list. Payment status/amount/currency columns appear automatically when
  exported submissions carry payments. Formula-injection safe.
- **Custom CSS** — per-form CSS panel in the editor. Sanitised against XSS
  (`@import`, `url()`, `expression()`, `-moz-binding`, `</style>` breakout).
- **Newsletter integrations** — Brevo, Mailchimp and CleverReach signups
  with a mandatory consent-field gate, async dispatch and double opt-in.

Requires the free Flinkform core **1.3.0+** (duplicate-submission idempotency
and the `flinkform_admin_format_value` seam live there).

### Roadmap (next — full plan in PRO_ROADMAP.md)

- Calculation-driven payment amounts (priceMode `calculation`)
- Redirect payment methods (EPS & co.) via return-URL flow
- PDF receipts attached to confirmation mails
- External CAPTCHA providers (Turnstile, hCaptcha) for operators who want them
- SMTP OAuth2 (Google Workspace, Microsoft 365)
- Licensing + update delivery via Freemius

## Architecture

Flinkform Pro docks onto the free core through its **bridge layer** (see
`includes/Bridge/README.md` in the free core). It never modifies core files; it
only hooks the published, frozen extension points:

| Hook | Purpose |
|------|---------|
| `flinkform_pro_features` (filter) | Advertises Pro capabilities so the core's `Features` façade flips on |
| `flinkform_register_modules` (action) | Wires Pro subsystems once the core has booted |
| `flinkform_block_dirs` (filter) | Registers Pro blocks / field types from this plugin's own build dir |
| `flinkform_field_blocks` + `flinkform_process_submission` (filters) | Register add-on field types (file upload, payment) |
| `flinkform_spam_providers` (filter) | Registers external CAPTCHA providers |

See [SECURITY.md](SECURITY.md) for the full security model and GDPR compliance documentation.

The hard dependency on the free core is enforced two ways:
1. `Requires Plugins: flinkform` header (WordPress 6.5+).
2. A runtime version guard (`FLINKFORM_PRO_MIN_CORE`, currently 1.3.0) that
   pauses Pro and shows an admin notice if the core is missing or too old to
   expose the bridge.

## Development

```bash
npm install   # install dependencies
npm run build # production build of the editor bundle
```

PHP classes follow PSR-4 under `includes/` (namespace `FlinkformPro\`).
Pro database tables (webhooks, webhook deliveries, mail log) are created via
`Database\Schema` and dropped only on uninstall — never on deactivation, so a
license lapse never destroys customer data.
