# Pilot Checklist — 360px Walkthrough

For a human tester, on a phone (or a browser resized to a 360px-wide viewport). Go through
every row in order. Note anything that scrolls sideways, overlaps, is hard to tap, or is
hard to read, in the "Problem?" column.

Rows 1–17 are the original 360px UI walkthrough. Rows 18–25 were added for the first
production deploy (docs/DEPLOY_RUNBOOK.md §10) and need a **real phone on real HTTPS** —
they will not pass locally over `http://`. Same "Problem?" column, same rule: work through
them in order and write down anything that doesn't behave. Row 26 was added with Phase 12.1
(live classes) and needs `LIVE_CLASSES_ENABLED=true` plus both Jitsi keys to complete —
everything up to the final Join can be walked through locally.

| # | Screen | Steps | What to check | Problem? |
|---|--------|-------|----------------|----------|
| 1 | Login | Open the tenant login page | Form fits without side-scrolling; help line visible below the form; tap/call links work if a phone is configured | |
| 2 | Dashboard | Log in as owner | Getting-started card readable as a single column; each step link works | |
| 3 | People (cards) | Go to People | Each person is a card (name, badges, phone/email, devices line, buttons) — no 7-column table | |
| 4 | People (actions) | As owner, then as staff | Same buttons appear as on desktop for what each role can do | |
| 5 | Add person | Tap "Add person" | Form fits; "Import from spreadsheet" link visible | |
| 6 | Import — template | Tap "Import from spreadsheet", download template | CSV downloads with a phone's default app | |
| 7 | Import — upload | Upload a small CSV | Preview loads without side-scrolling; row statuses readable | |
| 8 | Import — confirm | Tap "Create N students" | Redirects to the credentials sheet | |
| 9 | Credentials sheet | View the sheet | Each row's WhatsApp/Copy buttons are tappable with a thumb; Print and Download buttons visible | |
| 10 | Credentials sheet — WhatsApp | Tap a WhatsApp button | Opens WhatsApp (or wa.me) with the message pre-filled | |
| 11 | Credentials sheet — expiry | Wait or return after 15 minutes | Friendly "no longer available" message, no broken layout | |
| 12 | Enrolment | Enrol a student in a course | Form usable one-handed | |
| 13 | Progress | Open a course's progress page | Cards/table readable; filter and sort controls reachable without zooming | |
| 14 | Progress export | Tap "Export" | CSV downloads | |
| 15 | Help page | Open Help from the header | Sections readable, no tiny text, no horizontal scroll | |
| 16 | Lesson page | Open a lesson as a student | Video/player fits screen; no layout overflow | |
| 17 | Settings | Open Settings, set contact phone and logo | Form usable one-handed | |
| 18 | HTTPS | Type the `http://` tenant URL into the phone browser | Redirects to `https://` before the login form renders; no "Not secure" warning in the address bar | |
| 19 | PWA install | On a real Android phone, open the tenant site over `https://`, then Chrome's menu → "Add to Home screen" / install prompt | App installs with the institute's own icon (or the default generated one); opening it shows the app in its own window with no browser URL bar | |
| 20 | PWA offline | Turn on airplane mode, then reopen the installed app | Friendly offline page renders; no raw browser error, no blank white screen | |
| 21 | Branding propagation | As owner, change the institute name/colour/logo in Settings; reopen the installed PWA within a few minutes | New name and colour appear — the Cloudflare cache rule bypassing `/sw.js` and `/manifest.webmanifest` (docs/DEPLOY.md §1.5) is what makes this fast | |
| 22 | Video (real Bunny) | As owner, upload a small lesson video; log in as a student on a second phone and play it | Upload reaches 100% and the lesson shows a finished state; the student can start playback; no public/unsigned video URL is visible | |
| 23 | Payments (UPI) | As owner, set the institute UPI id in Settings; as a student, open the Pay page for a course | UPI id shown, the `upi://` link/QR is tappable; after uploading a payment screenshot the owner can see it and approve or reject it | |
| 24 | Device limit | As the same student, log in on a second phone while the first is still logged in | The first device is evicted with a clear message (Phase 8), not a silent session break | |
| 25 | Student password reset | As owner, open People → a student → "Reset password" | A temporary password is shown **once** and can be copied; that student's other devices are signed out on their next request (SECURITY.md "Device limits"), and the student logs back in with the temporary password and is forced to change it | |
| 26 | Live classes | As owner, tap "Live classes" in the header, then the course, then "Schedule a live class" for a time a minute or two ahead; as an enrolled student, watch the dashboard card | The list shows Course / Title / Starts / Status / Attendance with working upcoming/past/all filters and tappable Edit / Join / Attendance buttons; the card flips from "Next: … at …" to "Live now: …" with a Join link; the class page and its Join button fit the screen with no sideways scroll | |

**General checks on every screen above:** no horizontal scrollbar appears; every button is
at least comfortably tappable with a thumb; text is legible without pinch-zoom.
