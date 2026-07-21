# TODO

Outstanding work and known limitations, most actionable first.

## Deferred (agreed to leave for now)

- [ ] **Streaming import for large files.** The CSV import currently builds the full
  parsed/mapped/insert arrays in memory. Importing `Esheya.csv` (~51.5k rows) peaks at
  ~120 MB against PHP's 128 MB `memory_limit` — it works, but a larger file could OOM.
  Refactor `App\Actions\Contacts\ImportContacts` + `parseSource`/`runImport` in
  `resources/views/pages/lists/⚡show.blade.php` to parse → map → bulk-insert in chunks
  (~1,000 rows) so peak memory stays near-constant regardless of file size.

- [ ] **Raise PHP limits in Herd.** `upload_max_filesize` and `post_max_size` are both
  **5 MB** and `memory_limit` is **128 MB**. Uploads over 5 MB are rejected at the PHP
  layer. Bump these (e.g. 20–50 MB upload, 256 MB memory) for larger CSVs. Pair with the
  streaming refactor above.

## Setup required before OAuth providers work

- [ ] **AWeber & GoToWebinar credentials.** Set `AWEBER_CLIENT_ID`/`AWEBER_CLIENT_SECRET`
  and `GOTOWEBINAR_CLIENT_ID`/`GOTOWEBINAR_CLIENT_SECRET` in `.env` (placeholders already
  present).
- [ ] **Register the redirect URI** in the AWeber, GoTo and Zoho OAuth apps:
  `http://lead-import.test/integrations/oauth/callback` locally, and
  `https://<your-domain>/integrations/oauth/callback` on a deployed environment.
- [ ] **Zoho Campaigns credentials.** Set `ZOHO_CAMPAIGNS_CLIENT_ID`/`_SECRET` and
  `ZOHO_CAMPAIGNS_REGION` (default `com`). Register the app at `api-console.zoho.<region>`
  — Zoho data centres are separate, so the app must be registered in the same region the
  account lives in.
- [ ] **Run a queue worker** (`php artisan queue:work`) — sends to providers happen on the
  `database` queue and won't be delivered without it.

## Deploying to a live server

- [ ] **Register the live redirect URI** — `https://<your-domain>/integrations/oauth/callback`
  — in the AWeber, GoTo **and Zoho** OAuth apps. It is built from `route()`, so it follows
  the request host, not `APP_URL`. Providers accept multiple URIs, so the local `.test` one
  can stay. Zoho's must be added in the console for its region (`api-console.zoho.<region>`).
- [ ] **Live `.env`**: `APP_URL=https://<your-domain>`, `APP_ENV=production`,
  `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and the OAuth credentials —
  `AWEBER_*`, `GOTOWEBINAR_*`, `ZOHO_CAMPAIGNS_CLIENT_ID`/`_SECRET`/`_REGION`.
  Keep `SESSION_DRIVER=database` and `SESSION_SAME_SITE=lax`: the OAuth callback is a
  cross-site top-level redirect, and `strict` would drop the session and fail every
  connect with "Invalid OAuth state".
- [ ] **Back up `APP_KEY`.** Integration credentials are stored `encrypted:array`, so
  losing or rotating the key makes every connected integration undecryptable and every
  team has to reconnect.
- [ ] **Trust proxies if TLS terminates upstream** (load balancer, Cloudflare).
  `bootstrap/app.php` does not call `trustProxies()`, so behind a proxy `route()` generates
  `http://` URLs while the provider has `https://` registered — an opaque
  `redirect_uri_mismatch` at authorize time. Not needed on a single server terminating its
  own TLS.
- [ ] **`php artisan migrate --force`**, then `config:cache`, `route:cache`, `view:cache`.
  Re-run `config:cache` after any `.env` change.
- [ ] **Supervised `queue:work`** plus the scheduler (for `DripPacedDeliveries`), and
  **`php artisan queue:restart` on every deploy** — otherwise workers keep running the old
  driver code and deliveries sit on "Sending".
- [ ] **Smoke-test one contact per provider before a bulk send.** Zoho especially: its docs
  show `contactinfo` with unquoted keys, and the driver sends proper JSON on the strength of
  parser leniency the docs don't state. A code 2001 response means that assumption is wrong.

## Known limitations / possible follow-ups

- [ ] **Zoho Campaigns pushes no phone number.** Zoho's `contactinfo` is keyed by each
  account's own field display names and rejects the whole call on an unrecognised key, so
  only email/first/last are sent. Resolving real field names via `getallcontactfields`
  would allow phone and custom fields.
- [ ] **Zoho Campaigns rate limit is 500 calls/minute** and overrunning it locks the
  integration out for 30 minutes (also 12.5k/hour, 75k/day). `listsubscribe` is one call
  per contact, so pace large sends well under that — the per-hour setting on a send is the
  control. Nothing enforces this in code yet.
- [ ] **Zoho double opt-in.** On a double opt-in list `listsubscribe` returns success but
  the contact stays unconfirmed and receives nothing. Lists in use are single opt-in, so
  this is only a concern if that changes.
- [ ] **AWeber/Mailchimp field mapping** only covers name + phone/country (mapped to
  existing custom fields where present). Revisit if more fields are needed.

## Done (recent)

- [x] **GoToWebinar registrant names** now derive from the email's local part
  (`grace.hopper@…` → "Grace Hopper") when a contact has only the `there` greeting
  placeholder, instead of registering literally as "there". Rejection was already
  prevented by the `Subscriber` fallback, which now only catches emails with nothing
  name-like in them. Logic lives in `ContactPayload::resolvedNames()`.
- [x] **BirdSend tags are mapped by id**, with the current name resolved at push time
  (cached 5 min), so renaming a tag no longer orphans a list. Mappings created before
  this stored the name and are still honoured — a non-numeric id is treated as a name.
- [x] **Zoho Campaigns provider** (OAuth2). Needed three additive options on the shared
  OAuth layer, all defaulted so other providers are unaffected: `extraAuthorizeParams`
  (Zoho only issues a refresh token when `access_type=offline` is on the authorize
  request), `scopeSeparator` (comma-delimited) and `credentialsInBody`. The driver also
  handles Zoho's `Zoho-oauthtoken` auth scheme, its errors-inside-HTTP-200 convention, and
  its `contactinfo` JSON-string parameter. Region is set by `ZOHO_CAMPAIGNS_REGION`, with
  each connection pinned to the `api_domain` it was authorized against.
- [x] Providers: GetResponse, Systeme.io, Mailchimp, BirdSend (API key); AWeber,
  GoToWebinar, Zoho Campaigns (OAuth2, with token refresh).
- [x] Decoupled import from sending — lists are contact groups; a list can be **sent to
  many destinations** independently, each tracked per-contact (dedup per destination).
- [x] Fixed large-CSV import not advancing past the mapping step (file now stored on disk
  instead of in Livewire state; contacts bulk-inserted in chunks).
