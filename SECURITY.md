# Security Policy - Flinkform Pro

Last audit: 2026-06-22 (v1.1.0)

## Security Model

### Stripe Payments (PCI-DSS)
- Card data is handled **exclusively** by Stripe Elements (client-side JS from Stripe's CDN).
- Card numbers, CVV, expiry dates **never touch the WordPress server**.
- Only the Stripe PaymentIntent ID is stored with the submission.
- The server **verifies** every PaymentIntent status with Stripe before saving the submission.
- The server **validates** that the paid amount matches the configured price (anti-tampering).
- The Stripe Secret Key is stored AES-256-CBC encrypted (see below), never exposed to the frontend.
- The Stripe Publishable Key (public by design) is output as a `data-` attribute for Stripe.js.

### Credential Storage (AES-256)
All sensitive credentials (Stripe Secret Key, SMTP passwords, newsletter API keys) are encrypted at rest:
- **Cipher:** AES-256-CBC with a random 16-byte IV per encryption.
- **Key derivation:** SHA-256 of `wp_salt('auth')` (32 bytes).
- **Format:** `flinkform_enc_v1:<base64(iv || ciphertext)>` - versioned for future cipher upgrades.
- **Fail-closed:** Returns empty string on decryption failure, never plaintext fallback.
- Passwords are **never** sent back to the browser (empty field + "keep existing" pattern).

### File Upload Security
- Extension **and** content sniffing via `wp_check_filetype_and_ext()`.
- Per-field size cap, clamped to server `upload_max_filesize`.
- Randomised filenames (16 hex chars + sanitised original).
- Protected upload subdirectory: `.htaccess` blocks script execution, `index.html` blocks directory listing.
- Path traversal prevention: `..` is rejected in `url_to_path()`.
- Files are deleted automatically with the submission (GDPR cascade).

### Custom CSS Sanitisation
Per-form CSS is sanitised before output to prevent XSS:
- `wp_strip_all_tags()` as first pass.
- Blocked patterns: `expression()`, `behavior:`, `javascript:`, `@import`, `url()`, `-moz-binding`.
- `</style>` and `<!--` sequences are stripped to prevent tag breakout.

### Webhook SSRF Prevention
- All webhook URLs are validated with `wp_http_validate_url()` on save.
- Outbound requests use `'reject_unsafe_urls' => true` (blocks private/loopback/reserved IPs).

### SQL Injection Prevention
- All database queries use `$wpdb->prepare()` with parameterised placeholders.
- ORDER BY columns are validated against an allow-list.

### CSRF Protection
- All state-changing operations are protected by WordPress nonces.
- The Stripe PaymentIntent REST endpoint uses a scoped nonce (`flinkform_stripe_intent`).

### Authorization
- All admin operations require `manage_options` capability.
- The PaymentIntent REST endpoint is public (visitors pay without logging in) but nonce-protected.

## GDPR / DSGVO Compliance

### Privacy Policy Disclosures
`includes/Privacy.php` registers suggested privacy policy content for:
- Stripe Payments (card data handled by Stripe, only intent ID stored)
- Webhooks (data transmitted to admin-configured third-party URLs)
- SMTP (mail routed through configured provider)
- File uploads (randomised storage, auto-deletion)
- Newsletter integrations (data sent to Brevo/Mailchimp/CleverReach with consent gate)
- Mail log (recipients + subject only, never message body)

### Data Subject Rights
- **Right of access (Art. 15):** Personal data exporters for webhook delivery log and mail log.
- **Right to erasure (Art. 17):**
  - Webhook delivery rows: cascade-deleted with submission.
  - Mail log rows: email-based eraser.
  - Upload files: cascade-deleted with submission.
  - Stripe payment data: only the intent ID (no card data on server).
- **Data minimisation:** Mail log stores recipients + subject, never body. Webhook log excludes `response_body` from exports.

### Consent Mechanisms
- Newsletter signups require a **mandatory consent field** - no signup without active opt-in.
- Newsletter subscriber PII is not stored in cron args (opaque transient ticket pattern).

## Known Limitations

| ID | Description | Severity | Status |
|----|-------------|----------|--------|
| M2 | PaymentIntent endpoint has no rate limiting beyond nonce | Medium | Planned |
| M3 | Payment confirmed before server validation (no auto-refund on validation failure) | Medium | Planned |
| L1 | Webhook delivery log has no automatic retention/purge | Low | Planned |
| L2 | AES-256-CBC (no authenticity check, GCM planned for v2) | Low | By design |

## Reporting Vulnerabilities

If you discover a security vulnerability, please report it to **hallo@dbw-media.de**.
Do not open a public issue.
