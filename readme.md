# PerForm Pro

Paid add-on for the free [PerForm](https://wordpress.org/plugins/perform-forms/)
form plugin. Separate plugin, **not** distributed via WordPress.org — sold with a
license key and auto-updated from a dedicated endpoint.

## Architecture

PerForm Pro docks onto the free core through its **bridge layer** (see
`includes/Bridge/README.md` in the free core). It never modifies core files; it
only hooks the published, frozen extension points:

| Hook | Purpose |
|------|---------|
| `perform_pro_features` (filter) | Advertises Pro capabilities so the core's `Features` façade flips on |
| `perform_register_modules` (action) | Wires Pro subsystems once the core has booted |
| `perform_block_dirs` (filter) | Registers Pro blocks / field types from this plugin's own build dir |
| `perform_spam_providers` (filter) | Registers external CAPTCHA providers (Turnstile, hCaptcha, reCAPTCHA) |

The hard dependency on the free core is enforced two ways:
1. `Requires Plugins: perform-forms` header (WordPress 6.5+).
2. A runtime version guard (`PERFORM_PRO_MIN_CORE`) that pauses Pro and shows an
   admin notice if the core is missing or too old to expose the bridge.

## Status

- **M-b (current):** near-empty scaffold. Proves the dock + dependency check
  work end-to-end. No modules moved yet.
- **M-c (next):** move Conditional Logic, Multi-Step, Webhooks, CSV Export and
  SMTP out of the free core into this add-on, wired via the bridge.

## Capabilities (per the Free/Pro matrix)

Conditional logic · Multi-step forms · Webhooks · Submissions CSV export ·
SMTP (Basic Auth + OAuth2) · External CAPTCHA providers.
