# Meeting Scheduler
## Technical White Paper

**Scheduling is a custody problem, not a calendar widget**

**Organization:** Right&Above  
**Version:** 1.0  
**Publication date:** September 2026  
**Document status:** Public technical white paper  
**Source:** GPLv2-or-later reference implementation and runnable demo

# Scheduling is a custody problem, not a calendar widget

A reasoned approach to self-hosted meeting booking for organisations that already run WordPress and Google Workspace.

Meeting Scheduler is a WordPress plugin that keeps guest booking on your domain, derives real availability from Google Calendar FreeBusy, creates Calendar events (with Meet when available), and stores schedules, bookings, and encrypted OAuth credentials in an organisation-controlled PostgreSQL database. This paper argues that the failure mode in meeting scheduling is not missing “pick a slot” UI — it is the absence of a booking surface that respects the calendar you already trust without relocating guest data and brand to a third-party SaaS.

---

## Contents

1. Abstract
2. The problem in industry context
3. What would have to be true for scheduling to be trustworthy
4. Approach
5. Who does what
6. Architecture, as a consequence of the approach
7. Evidence from the implementation
8. Scope, applicability, and limits
9. Conclusion
10. Notes and sources

## Executive Summary

Meeting scheduling is often treated as a link-sharing convenience. In practice, the difficult part is keeping availability truthful, the guest experience on-domain, and booking records under organisational control — while the organiser’s time already lives in Google Calendar.

This paper presents a narrower alternative to a general Calendly-class SaaS: a self-hosted WordPress plugin for organisations that already operate WordPress and Google Workspace. Its central design principle is that **Google Calendar remains the system of record for free/busy and confirmed events**, while the organisation hosts the booking UI and the booking ledger.

The system signs organisers in with Google (the same OAuth grant covers Calendar access), offers guests only FreeBusy-safe slots, writes confirmed meetings back to Calendar, and keeps schedules, bookings, and encrypted tokens in PostgreSQL beside WordPress. Optional AI assistance may help find slots conversationally, but remains off unless the deployer configures an LLM endpoint.

The paper is intentionally not a claim of customer ROI or SaaS displacement rates. Its evidence is the implementation model, architecture, security choices, and reproducible public artefacts.

## Abstract

Organisations that already run WordPress and Google Workspace increasingly need Calendly-style self-service booking. The default path is a separate SaaS scheduler: connect a calendar, publish a link, done. That path is convenient, but it relocates guest identity, availability logic, and often meeting metadata to a vendor tenancy — adding subscription cost, a second brand surface, and a second processing agreement for personal data.

The appointment-scheduling software category is large and still growing: market analysts describe hundreds of millions of dollars in annual spend and double-digit CAGRs as online booking becomes an expected customer experience.[1][2] WordPress remains the dominant CMS — on the order of ~40% of all websites and a majority of known CMS installations according to W3Techs.[3] Pure-play SaaS schedulers (Calendly and peers) dominate mindshare for good reasons,[4] yet they optimise for a different job than “keep booking on the site we already operate.”

This paper analyses that gap and describes a narrower design. Calendar FreeBusy is the availability authority. Confirmed bookings become Calendar events. Guests stay on the organisation’s domain. OAuth tokens at rest are encrypted. Background mail and Calendar sync require real cron. The system is not a full CRM or payments platform. It does one job: meeting scheduling on your stack.

---

## 1. The problem in industry context

### 1.1 Scheduling is a multi-party protocol

A meeting booking is not a calendar annotation. It is a protocol with five moving parts:

1. **Discovery** — when the organiser is free, without exposing private event titles to the guest.
2. **Offer** — a guest-facing surface that presents only bookable slots.
3. **Commitment** — confirmation (sometimes with email verification) that binds both parties.
4. **Materialisation** — an event where both will see it (Calendar + Meet when available).
5. **Revision** — cancel and reschedule without restarting the email thread.

If any of those five lives in a different system of record, the organisation no longer has one booking truth. It has a SaaS history, a Calendar history, and a mailbox history that disagree under stress.

This is why “we have Google Calendar” does not solve guest booking. A calendar stores the organiser’s time. It does not, by itself, offer a branded, self-service commitment flow to an external guest.

### 1.2 The market proves the friction is real — and mostly captured by SaaS

The appointment scheduling software market exists because email back-and-forth is an operational tax. Self-booking, reminders, and calendar sync shorten time-to-meeting and reduce no-shows. Independent estimates differ in absolute dollars, but directionally agree on sustained growth into the next decade.[1][2]

Demand-side evidence points the same way: market reports routinely cite strong preference for online booking over phone in patient and consumer contexts, and willingness to switch providers when online booking is missing.[1] Corporate and professional-services buyers show analogous behaviour: if the competitor shares a booking link and you share an email thread, you lose tempo.

Category commentary routinely places Calendly at a large share of the pure-play scheduling footprint.[4] That success is deserved. It is also a concentration of guest PII and workflow configuration in a vendor tenancy.

### 1.3 SaaS creates a second system of record next to Workspace

For Google Workspace shops, a SaaS scheduler that *mirrors* Calendar is useful — and still parallel. Seats, admin roles, notification templates, retention, and DPA subprocessors must stay consistent with Workspace. Under GDPR-like regimes, the scheduler is another processor: another agreement, another export/delete path, another region decision.[5][6]

European buyers in particular increasingly ask whether personal data from booking flows can stay closer to their own infrastructure.[5] Self-hosting does not magically create compliance, but it changes the control plane.

### 1.4 Brand and trust sit on the booking page

The booking page is often a guest’s first interactive moment with the organisation. Redirecting to a third-party scheduler subdomain (or a heavily branded embed) is functionally fine and psychologically different from completing the flow on the organisation’s own domain. Teams that invest in WordPress as their primary digital property have a rational interest in keeping conversion-critical flows on-domain.[3]

### 1.5 Why not “just another WordPress booking plugin”?

Generic WordPress appointment plugins keep the UI on-domain, but often treat Google Calendar as an afterthought export. Availability then lives in MySQL while the organiser’s real day lives in Calendar. Dual ledgers drift. The opposite extreme — a full self-hosted scheduling platform beside WordPress — maximises control and adds another runtime, IdP, and ops surface.

The proposed system deliberately narrows the problem:

| Requirement | Email negotiation | Pure SaaS scheduler | Generic WP plugin (MySQL-only) | Meeting Scheduler |
|---|---|---|---|---|
| Self-hosted booking UI | N/A | No | Yes | **Yes** |
| Google Calendar FreeBusy as availability truth | Manual | Usually yes | Often partial / bolt-on | **Yes** |
| Confirmed meetings written to Calendar (+ Meet) | Manual | Usually yes | Product-dependent | **Yes** |
| Guest stays on organisation domain | Yes (badly) | Embed / redirect | Yes | **Yes** |
| Booking ledger in organisation-controlled DB | Mailbox | Vendor | WP MySQL | **PostgreSQL** |
| OAuth tokens encrypted at rest | N/A | Vendor | Product-dependent | **AES-256-GCM** |
| Optional self-hosted LLM assistant | No | Product-dependent | Rare | **Optional** |

This is positioning, not a claim that every SaaS or WP plugin lacks these capabilities. The relevant distinction is that Meeting Scheduler makes **calendar custody + on-domain booking** the centre of the design rather than features surrounding a multi-tenant suite.

---

## 2. What would have to be true for scheduling to be trustworthy

A scheduling system is trustworthy when a competent outsider can answer four questions from the systems of record, without asking a person:

| Question | What “good” looks like |
|---|---|
| Is this slot actually free? | Derived from FreeBusy (or equivalent), not from a static weekly grid that ignores real meetings. |
| Where does the confirmed meeting live? | In the organiser’s Calendar (with Meet when configured), not only in a plugin table. |
| Where did the guest’s data go? | Into infrastructure the organisation controls, with a stated retention/deletion story. |
| Who is the organiser, cryptographically? | A Google OAuth session with PKCE/`state`, not a shared password on a booking admin screen. |

Those questions imply design constraints. They are worth stating before the product, because they are the reasons the product is shaped the way it is.

1. **Calendar must be authoritative for free/busy.** A booking UI that ignores FreeBusy will double-book the moment the organiser accepts a meeting outside the tool.
2. **Confirmation must materialise an event.** A row in a plugin database that never becomes a Calendar event is a second ledger waiting to diverge.
3. **The guest surface must be on-domain.** Brand, cookies, accessibility, and content-security policy are site concerns; outsourcing the page outsources trust.
4. **Credentials at rest must be treated as secrets.** Refresh tokens that can create events in someone’s Calendar are not “just config.”
5. **Async work needs honest ops.** Mail and Calendar sync that only run when a visitor hits WordPress will fail silently in production.
6. **Scope must stay narrow.** Payments, CRM pipelines, and multi-vendor SMS are different products. Expanding into them recreates the SaaS suite the design was meant to avoid.
7. **AI is optional infrastructure.** Conversational booking is valuable only if the deployer chooses the model endpoint — including a self-hosted LLM.

If a design violates any of these, it will recreate SaaS lock-in or spreadsheet-style drift with a nicer theme.

---

## 3. Approach

Meeting Scheduler is a WordPress plugin for organisations that already run WordPress and Google Workspace. It is not a full CRM. Organisers sign in with Google; guests book on public pages; confirmed meetings land in Google Calendar.

The rest of this section is the reasoning, not the feature list.

### 3.1 Calendar is the unit of truth

Availability windows defined in the plugin are *offers*, not the final word. Before a slot is shown as bookable, the system consults Google Calendar FreeBusy for the organiser. A confirmed booking creates an event on the organiser’s primary calendar (with a Meet link when Workspace/Google configuration allows). Cancel flows remove or update that event rather than leaving an orphan in Calendar.

This split is deliberate. WordPress hosts the commitment UX; Google hosts the time ledger the organiser already lives in.

### 3.2 One OAuth grant, two jobs

Organiser identity and Calendar access share a Google OAuth client. The redirect flow uses OAuth `state` and PKCE. The same grant that proves “who is booking as organiser” authorises FreeBusy reads and event writes. That avoids a second password database and a second “connect your calendar” afterthought.

### 3.3 Custody in PostgreSQL beside WordPress

Schedules, bookings, communication preferences, and Google credential rows live in PostgreSQL — separate from the WordPress MySQL/MariaDB content database. Google tokens are stored encrypted with AES-256-GCM keyed from WordPress salts. The booking ledger is therefore queryable, backupable, and placeable in a chosen region without living in a multi-tenant scheduler.

### 3.4 Hold the guest on-domain through revision

Public booking, optional email verification, cancel, and reschedule use links and pages on the organisation’s site (short owner-slug URLs). Captcha ships inside the plugin so a special theme is not required. The guest does not need a SaaS account.

### 3.5 Background work is first-class

Confirmation mail, cancellation cleanup, and schedule mirroring into Calendar are deferred to WP-Cron hooks. Production deployments are expected to disable WordPress’s internal “run cron on visit” behaviour and hit `wp-cron.php` from system cron. That is operational honesty: scheduling side-effects are not a best-effort side effect of traffic.

### 3.6 Optional intelligence without mandatory cloud AI

When `APEXIANLAB_LLM_*` constants are set, an assistant can help find slots and perform book / reschedule / cancel in chat against an OpenAI-compatible API (optional speech-to-text). When they are unset, the product still works. AI is an interface over the same domain services, not a second booking engine.

---

## 4. Who does what

The product surface follows the constraints in section 2. It is included here so the approach can be evaluated against the actual jobs, not against an abstract architecture.

| Who | What they do |
|---|---|
| Organisers | Sign in with Google, define availability (recurring, interval, or one-off), share a booking link, manage schedules. |
| Guests | Book on a public page against FreeBusy-safe slots; confirm, cancel, or reschedule via emailed links. |
| Site operators | Provision PostgreSQL, Google OAuth client, mail, cron, and optional LLM endpoints via `wp-config.php`. |
| Optional AI assistant | Conversationally finds slots and invokes the same book / reschedule / cancel paths when enabled. |

---

## 5. Architecture, as a consequence of the approach

The stack is a WordPress plugin (PHP 8.2+) with a dedicated PostgreSQL database and Google Calendar API integration. Secrets stay in `wp-config.php` constants; this paper does not describe any specific hosting vendor.

| Layer | Choice | Why it is in this paper |
|---|---|---|
| Host application | WordPress 6.0+ | Constraint 3: on-domain pages, themes, and ops the organisation already runs. |
| Plugin runtime | PHP 8.2+, namespaced `Apexianlab\Calendar\` | One module: booking UI, AJAX, OAuth, services. |
| Booking ledger | PostgreSQL 13+ (`gen_random_uuid()`) | Constraint: custody separate from WP content DB. |
| Identity + calendar | Google OAuth 2.0 + Calendar API (FreeBusy, events, Meet) | Constraints 1–2 and 4. |
| Security primitives | Nonces; OAuth `state` + PKCE; AES-256-GCM token storage; plugin captcha | Constraint 4 and public-form abuse resistance. |
| Notifications | SMTP or Gmail API; confirm / cancel / reschedule mail | Guest revision without SaaS accounts. |
| Async | WP-Cron hooks + real system cron in production | Constraint 5. |
| Optional AI | OpenAI-compatible LLM (+ optional STT) | Constraint 7. |

Two properties are load-bearing.

**The browser does not decide free/busy.** It displays slots the server derived from schedule rules and Google FreeBusy. A crafted client cannot invent availability the Calendar would reject.

**Side effects are deferred.** User-facing responses stay fast; mail and Calendar mutations run as explicit jobs. That is the difference between “we integrated Calendar” and “we hope the request thread finished the API calls.”

Logical flow:

```
Guest (browser)          WordPress plugin              Google APIs
     |                         |                            |
     |  public booking UI      |                            |
     |------------------------>|  FreeBusy query            |
     |                         |--------------------------->|
     |                         |<---------------------------|
     |  choose slot / confirm  |                            |
     |------------------------>|  persist booking (Postgres)|
     |                         |  create Calendar event     |
     |                         |--------------------------->|
     |  email confirm/cancel   |  (SMTP or Gmail API)       |
     |<------------------------|                            |
```

---

## 6. Evidence from the implementation

A design paper that never touches the artefact it describes is a prospectus. Two kinds of evidence are available here: that the domain choices exist as concrete code and schema, and that the public artefacts are reproducible.

### 6.1 Domain choices as code

The repository pins the approach in section 3 as implementation, not aspiration:

- FreeBusy-backed availability checks in the availability service before slots are treated as free.
- Google OAuth handler covering sign-in and Calendar scopes, with PKCE and `state`.
- Credential rows storing access/refresh material encrypted via AES-256-GCM helpers on the database connection.
- Schema for schedules, bookings, means of communication, and Google credentials in PostgreSQL migrations.
- WP-Cron hooks for confirmation mail, cancellation cleanup, and schedule sync/delete against Calendar.
- Optional AI assistant module that calls the same booking services when LLM constants are present.

That is the standard of evidence this paper can honestly offer: the constraints are written down in code paths operators can inspect. It is not a field study of conversion lift after replacing Calendly. Organisations evaluating the system should treat the public demo and the source tree as the primary artefacts, and this paper as the argument for why those artefacts are shaped that way.

### 6.2 Representative verification cases

The following cases illustrate invariants a reviewer should check against the implementation and a staging Calendar:

| Case | Input condition | Expected invariant |
|---|---|---|
| Busy overlap | Organiser has a Calendar event overlapping a schedule window | Slot is not offered (FreeBusy) |
| Confirm | Guest completes booking (with verification off or after verify) | Postgres booking row + Calendar event exist |
| Cancel | Guest uses cancel link | Calendar event removed/updated; parties notified |
| Token at rest | Organiser connected Google | Stored tokens are ciphertext (AES-256-GCM), not plaintext |
| Cron disabled incorrectly | Only “visit-driven” WP-Cron, low traffic | Mail/Calendar jobs lag — operators must use real cron |
| AI unset | No LLM constants | Booking UI still functions; assistant routes inactive |

### 6.3 Public artefacts

GPLv2-or-later source and release artefacts: https://github.com/raa-org/meeting-scheduler

Live demo: https://apexianlab-calendar.rightandabove.com/

---

## 7. Scope, applicability, and limits

**Who should use it.** Organisations that already run WordPress and Google Workspace, want Calendly-like booking without sending guests to a third-party SaaS, and are willing to operate PostgreSQL, a Google OAuth client, mail, and real cron.

**Who should not.** Organisations that need a fully managed multi-product scheduling suite (payments, complex round-robin routing products, global SMS, deep Salesforce automation) with zero ops. Organisations not on Google Calendar as the availability authority. Organisations unwilling to host a WordPress plugin responsibly (updates, salts, database backups).

**What this paper does not claim.** It does not claim measured conversion improvement at a named customer. It does not claim feature parity with every Calendly enterprise SKU. It does not describe a specific production host or secret. The live demo and the GPLv2-or-later source are the public artefacts; this paper is the argument.

---

## 8. Conclusion

Meeting scheduling goes wrong when availability, commitment, and custody live in different places. Email keeps intent that nobody materialises. SaaS keeps a polished commitment flow whose centre of gravity is a vendor tenancy. Generic plugins keep an on-domain form whose centre of gravity is not FreeBusy.

The approach argued here is narrower. Keep Google Calendar authoritative for free/busy and events. Keep the guest on WordPress. Keep the booking ledger and OAuth material in infrastructure you control. Encrypt tokens at rest. Run async work on real cron. Treat AI as optional. The product that follows from those constraints is a self-hosted meeting scheduler, not a brief for a larger SaaS suite.

A white paper should be judged by whether a reader can disagree with the argument. The disagreement worth having is this: either scheduling is a widget, in which case a SaaS link is enough, or scheduling is custody of time and guest data, in which case the booking surface and the ledger have to live where the organisation can name them.

---

## Notes

## References and Public Artefacts

GPLv2-or-later source and runnable demo: https://github.com/raa-org/meeting-scheduler

Live demo: https://apexianlab-calendar.rightandabove.com/

1. Market Data Forecast, *Appointment Scheduling Software Market* (market size / CAGR discussion; online booking preference statistics cited in-report). https://www.marketdataforecast.com/market-reports/appointment-scheduling-software-market
2. Fortune Business Insights, *Appointment Scheduling Software Market* (application mix incl. calendar management; growth outlook). https://www.fortunebusinessinsights.com/appointment-scheduling-software-market-108614
3. W3Techs, usage statistics for WordPress (share of websites / CMS market). https://w3techs.com/technologies/details/cm-wordpress
4. Industry comparisons summarising appointment-scheduling vendor share (e.g. Calendly vs alternatives). Example: https://wpamelia.com/calendly-alternatives/
5. Market commentary on European demand for GDPR-oriented online booking. Example: https://www.tucalendi.com/en/blog/european-online-booking-software-why-more-and-more-companies-are-looking-for-gdpr-solutions-518
6. TIMIFY, *Make Your Appointment Booking System GDPR Compliant*. https://www.timify.com/en/blog/make-your-appointment-gdpr-compliant/
