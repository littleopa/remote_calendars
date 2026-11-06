# Changelog

## 1.0.1 – 2026-11-06
- Security: response size limit (default 20 MB, app config `max_response_mb`) – a hostile server can no longer exhaust memory or stall the background job
- Security: hrefs from the remote server are only followed on the calendar's own origin (discovery: same domain), so credentials never go to another host
- Security: blocks CGNAT/benchmark/multicast/NAT64 ranges and this server's own addresses; only ports 443/8443 unless private addresses are allowed by the admin
- Security: rate limits on all endpoints that trigger outgoing requests
- Background job: least recently synced first, 240 s budget per run, every calendar isolated
- UI: hint that sharing a connected calendar with write access lets others write with your login

## 1.0.0 – 2026-11-06
- First release: self-service connection of CalDAV calendars with two-way sync
