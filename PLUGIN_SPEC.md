# PLUGIN_SPEC — Kiyoh Reviews for Magento 2

**Shape:** A (Magento 2 module) + B (Kiyoh publication API and Kiyoh-Connect)  
**Owner:** Kiyoh plugins  
**Version:** 1.5.1

## Host

- Platform: Magento Open Source / Adobe Commerce 2.4.6+
- Runtime: PHP 8.2+
- Distribution: Composer package `kiyoh/reviews`, private install. Not a Magento Marketplace submission in this change.

## Contract

- **Invitations and catalog sync** (existing): order status triggers a review invite; product save/delete and cron sync product codes. Kiyoh publication API on `www.kiyoh.com` or `www.klantenvertellen.nl`.
- **Health** (1.4.0, default hosts in 1.5.1): `POST {mobile}/api/integrations/health` per store view, once per comma-separated Kiyoh Mobile URL. The default is `https://kiyoh-mobile.com,https://kiyohmobileui-staging.up.railway.app`. A failure on one host is logged for that host and does not skip the others. See `Model/Health`.
- **Sign-in** (1.5.0), Kiyoh-Connect 1.2.0 public client `kiyoh-magento2`:
  1. `POST {issuer}/shops/register` JSON: `client_id`, `shop_url`, `redirect_uri`. No signature. HTTP 200 once returns `client_id`, `shop_origin`, `redirect_uri`, `shop_id`, `shop_secret`, and `status` (`active` or `pending`). The module stores the shop credential encrypted before the popup opens. The same return address reuses that credential and does not register again.
  2. Popup `GET {issuer}/authorize` with `response_type=code`, `client_id`, `redirect_uri`, `scope=integration:connect`, `state`, `code_challenge`, `code_challenge_method=S256`.
  3. Browser returns to `https://{shop-host}/kiyoh/oauth/callback?code&state`.
  4. `POST {issuer}/token` form, `grant_type=authorization_code`: `client_id`, `shop_id`, `shop_secret`, `code`, `code_verifier`, `redirect_uri`. HTTP 401 `invalid_client` means the shop credential was rejected.
  5. `GET {issuer}/integration/credentials` with `Authorization: Bearer`. Body fields used: `locationId`, `tenantId`, `locationName`, `inviteApiKey`.
  6. Refresh grant on `POST {issuer}/token`: `grant_type=refresh_token`, `refresh_token`, `client_id`, `shop_id`, `shop_secret`. `POST {issuer}/revoke`: `token`, `client_id`, `shop_id`, `shop_secret`. Uninstall and Disconnect also `POST {issuer}/shops/revoke` with `client_id`, `shop_id`, `shop_secret`.
- Sample payloads: `tests/fixtures/kiyoh-connect/`.
- Wire names live in `Model/Connect/ConnectFields.php`.

Issuers: `https://connect.kiyoh.com` (tenant 98, server `kiyoh.com`) and `https://connect.klantenvertellen.nl` (tenant 99, server `klantenvertellen.nl`). `shop_url` is the store base URL. `redirect_uri` is the host root plus `/kiyoh/oauth/callback`.

The private Kiyoh-Connect repository was not readable from the build environment (HTTP 404). This spec follows the public-client contract decided for PR `KVMicroServices/Kiyoh-Connect#1` (v1.2.0).

## External API

- Base URLs: the two Connect issuers above, plus the publication hosts selected by Server.
- API version pinned: Kiyoh-Connect 1.2.0. Publication API paths already used by `ApiService` (`/v1/invite/external`, location statistics, product sync).
- Auth: public OAuth client. PKCE S256, exact `redirect_uri`, and the per-shop credential on the token endpoint. Then the location invite API key on `X-Publication-Api-Token`. Scope `integration:connect` only. No shared client secret and no HMAC key.
- Rate limits: HTTP 429. `Retry-After` wins over a JSON `retryAfterSeconds` (minimum 1 second, cap 3600, default 60). The admin request does not sleep; it shows the wait. Health reporting stores the delay per mobile URL.
- Pagination: not used on Connect. Product sync is batched by the existing API (max 200, health marks up to 500 codes).
- Stable IDs: location ID + store scope for credentials; OAuth `state` for one login; issuer + redirect URI for the shop credential.
- Webhooks: none. Health reports `webhooks.lastReceivedAt` as null.
- ToS: credentials are the merchant's location key, used only to send invites and sync products for that location.

## Behaviour

- Trigger: admin clicks Connect, Test connection, or Disconnect. Invitations remain on order status. Health remains on save, invite, product sync, and daily cron `30 4 * * *`.
- Volume: one Connect registration per return address, then one token exchange per click. A later click with the same issuer and redirect URI reuses the stored shop credential and does not register again. Test connection is one statistics request, plus one refresh and one credentials call only when that statistics call fails and both a refresh token and a shop credential exist.
- Failure policy: Connect and publication errors become one sentence in the admin. They are not thrown into the order flow. 429 does not mark the login as accepted and does not write location credentials. A network or TLS failure does not clear a previously saved token. A 2xx register body without a shop credential, or HTTP 409, is an error when this shop has nothing stored. If the return address or server changed and Connect does not issue a new credential, the admin is told to disconnect and connect again.
- Idempotency key: `state` is single-use (deleted on read). Saving credentials overwrites the same config paths. Token revoke treats HTTP 400 as already revoked. Shop revoke treats HTTP 404 as already removed. Disconnect deletes the local shop credential only after the shop revoke succeeds, unless another scope still stores the same shop id (then only this scope's copy and its refresh token are removed).
- Resume: closing the popup before login leaves the shop credential stored so the next click can continue. The pending row expires after 15 minutes. Repeating Connect starts a new `state`. Killing the callback after Connect has issued the code requires a new click (the code is single-use at Connect).

## Permissions requested (and why)

- Magento ACL `Kiyoh_Reviews::config`: the same people who can paste an API token can start Connect.
- Public GET `/kiyoh/oauth/callback`: Kiyoh-Connect must redirect the browser to the shop host. The handler does nothing unless `state` matches a pending admin login.
- OAuth scope `integration:connect`: the only scope Connect issues for this client.

## Data stored, retention, uninstall behaviour

- Encrypted API token, refresh token, access token, shop id, and shop secret in `core_config_data`. Issuer and redirect URI for that shop credential, location ID, and location name in the same section. Pending PKCE verifier encrypted in `kiyoh_reviews_connect_login` for at most 15 minutes. Health state in `kiyoh_reviews_shop_health`, one row per store view, overwritten.
- A pasted API token sets `credential_source=manual` and clears the refresh and access tokens. It does not delete the shop credential.
- `bin/magento module:disable Kiyoh_Reviews` keeps that data.
- `bin/magento module:uninstall -r Kiyoh_Reviews` drops both tables, deletes `kiyoh_reviews/%` config, and best-effort revokes the Connect token and shop registration with the stored shop credential. A missing credential is logged. It does not delete orders or customers.

## Acceptance test

1. Connect on a store view opens `connect.kiyoh.com` or `connect.klantenvertellen.nl`, returns to `https://{that-host}/kiyoh/oauth/callback`, and saves that location's ID and invite API key. No environment secret is required. Test connection reports success. A second Connect on the same return address overwrites the same fields and does not expect a second shop credential.
2. Disconnect clears a Connect-sourced token and revokes the shop when this scope holds the only copy. A pasted token still saves and still passes Test connection. The pasted token does not delete the shop credential.
3. Replaying the callback, or waiting out the 15 minute pending login, does not create a second credential set.
4. HTTP 429 on the token request shows a retry message and does not save a token (`tests/fixtures/kiyoh-connect/rate-limited.json`).
5. An invite still succeeds with TLS verification left on.

## Security

- **Secrets:** the module embeds no client secret and no HMAC key. Connect issues a per-shop credential once; Magento's encryptor stores it. It is not shown on the configuration form, in the callback HTML, or in the admin JavaScript. API and refresh tokens are encrypted the same way. `.env` is not used.
- **Least privilege:** one admin ACL resource, one OAuth scope.
- **Input:** `code` and `state` must match `^[A-Za-z0-9\-._~]{8,512}$`. Credentials JSON must contain a numeric location ID, a tenant ID, and an invite API key. The shop credential must be a single non-empty line within the length cap. Location name is tag-stripped. Admin messages drop strings that look like secrets.
- **Output:** the callback page escapes the message. Config messages strip `%`.
- **Injection:** SQL uses bound `where` arrays. No `eval`, `unserialize`, or shell.
- **AuthZ:** admin actions require `Kiyoh_Reviews::config` and Magento's admin form key. The callback is authorized by the single-use `state` plus the admin having created it. Tenant 98/99 must match the selected Server or nothing is saved.
- **CSRF:** admin posts use `form_key`. OAuth `state` is the callback CSRF token.
- **SSRF:** Connect URLs are the two fixed issuers. The return address is derived from the store's secure base URL (HTTPS, no userinfo), not from a free-text URL field.
- **File handling:** N/A. No uploads.
- **TLS:** `CURLOPT_SSL_VERIFYPEER` true and `CURLOPT_SSL_VERIFYHOST` 2 on publication calls and on Connect. Redirects are not followed.
- **Webhooks:** N/A. None received.
- **Rate limiting:** the callback returns immediately unless `state` matches a row. Connect 429 is surfaced, not retried inside the admin request.
- **Logging:** Connect failures log the HTTP status. Response bodies are not logged (they can echo tokens). Network errors log a short cURL message.
- **Dependencies:** unchanged. Magento packages are not vendored here; `composer install` needs `repo.magento.com`.
- **Errors:** merchants see one sentence. Stack traces stay in the Magento log.
- **Uninstall / data:** documented above.
- **Host checks:** ACL on every new admin route. The module does not weaken Magento CSP or admin auth. No shop credential is placed in the admin JavaScript source; the authorize URL is returned only to a logged-in admin after the credential is stored.

## Open questions

- Kiyoh-Connect production was still 1.0.0 (`/shops/register` 404, health version 1.0.0) when this was built. End-to-end sign-in waits on Kiyoh-Connect 1.2.0 with client `kiyoh-magento2`.
- Wire names above are the Kiyoh-Connect 1.2.0 names for `kiyoh-magento2`. `ConnectFields` is still the only place those strings are defined, so a later rename stays in that class.
