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
- [ ] **Register the redirect URI** in both the AWeber and GoTo OAuth apps:
  `http://lead-import.test/integrations/oauth/callback`
- [ ] **Run a queue worker** (`php artisan queue:work`) — sends to providers happen on the
  `database` queue and won't be delivered without it.

## Known limitations / possible follow-ups

- [ ] **GoToWebinar requires first + last name** on every registrant. Leads without a name
  are rejected by GoTo and marked failed. Consider a fallback (e.g. derive a name from the
  email) if this bites.
- [ ] **BirdSend tags are keyed by name** (its write API is name-based), so renaming a tag
  in BirdSend breaks an existing list mapping. Could resolve name→id at push time if needed.
- [ ] **AWeber/Mailchimp field mapping** only covers name + phone/country (mapped to
  existing custom fields where present). Revisit if more fields are needed.

## Done (recent)

- [x] Providers: GetResponse, Systeme.io, Mailchimp, BirdSend (API key); AWeber,
  GoToWebinar (OAuth2, with token refresh).
- [x] Decoupled import from sending — lists are contact groups; a list can be **sent to
  many destinations** independently, each tracked per-contact (dedup per destination).
- [x] Fixed large-CSV import not advancing past the mapping step (file now stored on disk
  instead of in Livewire state; contacts bulk-inserted in chunks).
