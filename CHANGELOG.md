# Changelog

## 1.0.2 – 2026-11-24
- Security: the remote password no longer appears in logged stack traces of unexpected errors (#2). Credentials are passed as an object with private fields, password parameters are marked `#[\SensitiveParameter]`
- Fix: a local edit that conflicts with a newer remote version (HTTP 412) no longer overwrites the remote change on the next sync (#1). The object is left alone until the next pull brings the remote version, as documented ("the remote server wins"); the same applies to deletes and to entries the server refused (403/405)
- Fix: when a server answers a PUT without an ETag, the ETag is fetched with a PROPFIND, so the next change is still sent with `If-Match`

## 1.0.1 – 2026-11-06
- Security: response size limit (default 20 MB, app config `max_response_mb`) – a hostile server can no longer exhaust memory or stall the background job
- Security: hrefs from the remote server are only followed on the calendar's own origin (discovery: same domain), so credentials never go to another host
- Security: blocks CGNAT/benchmark/multicast/NAT64 ranges and this server's own addresses; only ports 443/8443 unless private addresses are allowed by the admin
- Security: rate limits on all endpoints that trigger outgoing requests
- Background job: least recently synced first, 240 s budget per run, every calendar isolated
- UI: hint that sharing a connected calendar with write access lets others write with your login

## 1.0.0 – 2026-11-06
- First release: self-service connection of CalDAV calendars with two-way sync
