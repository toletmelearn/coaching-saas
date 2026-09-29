# Video

## Provider: Bunny Stream

- **One Bunny Stream library per tenant.** This gives two things at once: isolation (a
  tenant's videos live in their own library, not mixed with other tenants' content in a
  shared library filtered by metadata) and per-tenant usage metering (Bunny reports storage
  and bandwidth per library, which maps directly to per-tenant billing/usage without extra
  bookkeeping).
- Library credentials (library id, API key) are stored per tenant, encrypted (see
  [SECURITY.md](SECURITY.md)).

## Upload

- Uploads are **resumable, direct-from-browser, via TUS** (the protocol Bunny Stream's
  upload API supports) — the browser uploads straight to Bunny, not through the Laravel
  app's own request/response cycle, so large lecture videos don't tie up app server workers
  or hit PHP upload size limits.
- The browser never holds a long-lived Bunny credential. Laravel generates a
  **server-signed upload signature** (scoped to the tenant's library, short-lived) that the
  browser attaches to the TUS upload request. This keeps the actual API key server-side
  while still allowing direct browser → Bunny upload.

## Webhooks

- Bunny webhooks (e.g. "encoding finished") are treated **only as a notification to check**,
  never as the source of truth for state changes. On receiving a webhook, the app re-fetches
  the video's status from the Bunny API and updates local state from that response — it does
  not trust the webhook payload's fields directly. This protects against replayed, delayed,
  or spoofed webhook calls changing state based on stale or forged data.

## Playback

- Playback URLs are **signed and expiring**, generated per request for an authenticated,
  enrolled user — the same signed-URL discipline as private storage in
  [SECURITY.md](SECURITY.md).

## Free previews

- Lessons marked as free-preview may use a plain **YouTube link** instead of Bunny Stream.
  These are stored with `provider = youtube` on the lesson/video record, and playback is
  just the public YouTube embed — no signing, no Bunny library, no watermarking, since the
  content is intentionally public.

## Watermarking — RESOLVED (Phase 5)

Resolved in favour of **option 2**, a dynamic overlay: an absolutely positioned,
`pointer-events: none` div showing the viewer's name, an identifier (last 4 digits of phone,
or the local part of their email if no phone), and today's date, escaped Blade output (never
raw HTML — student names are user input), repositioned to a random spot every 20–40 seconds
via a small inline script. Owner/staff previews show "Preview – {name}" instead. Chosen over
Bunny's static library watermark (option 1) specifically because it's traceable to the
individual viewer, not just proof a leak occurred — see SECURITY.md "Watermark is a
deterrent, not a guarantee" for what this does and doesn't protect against. See
docs/specs/phase-5-video.md for the full implementation.
