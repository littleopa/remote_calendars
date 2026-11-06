# Remote Calendars for Nextcloud

Calendar subscriptions in Nextcloud are read-only. **Remote Calendars** lets every user connect a
calendar on another CalDAV server with their own credentials. It appears in the Calendar app as a
normal, editable calendar and is kept in sync in both directions.

## Features

- Self-service: *Personal settings → Remote calendars* – address, username, password, choose calendars
- Auto-discovery (`current-user-principal`, `calendar-home-set`, `/.well-known/caldav`, or direct calendar URL)
- Local changes are pushed immediately (end of request), remote changes are fetched by a background job (default every 5 minutes)
- ETag/`If-Match` based: conflicts are resolved in favour of the remote server, nothing is overwritten blindly
- Read-only remote calendars stay read-only (local edits are reverted)
- Safety net: a suddenly empty remote calendar never wipes the local copy
- Passwords stored encrypted (`ICrypto`), https only (ports 443/8443), private/reserved networks and the
  server's own addresses blocked by default, redirects and hrefs only followed on the same origin,
  DNS pinned per request, response size limit, rate limits
- Sharing: if a connected calendar is shared with write access, changes of those users are sent with the owner's login
- Notification when the login stops working (e.g. password changed)
- Admin settings (*Groupware*): suggested servers, sync interval, allow private networks
- English and German UI

## How it works

Every connection maps one remote collection to one local calendar. For each entry the app stores the
local URI/ETag and the remote href/ETag (`oc_extkal_objects`).

1. **Push** – local entries whose ETag differs from the stored one are `PUT` to the server
   (`If-Match`, or `If-None-Match: *` for new ones); missing ones are `DELETE`d.
2. **Pull** – `PROPFIND` (Depth 1, `getetag`) on the remote collection, changed entries are fetched
   with `calendar-multiget` (fallback: `GET`) and written locally; vanished entries are removed locally.

Removing a connection deletes only the Nextcloud copy, never the remote calendar.
Deleting the calendar in the Calendar app ends the connection as well.

## Requirements

Nextcloud 35, system cron recommended.

## License

AGPL-3.0-or-later
