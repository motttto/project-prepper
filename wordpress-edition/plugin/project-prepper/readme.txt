=== Project Prepper ===
Contributors: motttto
Tags: inventory, rental, equipment, availability, booking
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.131.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Equipment inventory, projects & rentals for teams — categories with number ranges, availability checks and rental management.

== Description ==

Project Prepper manages equipment inventory and rentals directly in WordPress:

* Dashboard with KPIs and recent activity
* Inventory items with automatic inventory numbers (number range per category prefix)
* Categories with icon and prefix
* Search and filters across name, number, manufacturer, tags
* Projects: plan events with equipment bookings, a schedule, checklists, tasks, a cost breakdown with VAT, budget and revenue, materials, a team & contacts list and attached files from the media library — confirmed projects block inventory just like rentals
* Groups: share projects with a team of WordPress users — group projects are only visible to their members, site-level projects keep working as before; list the project members (participants) from the owning group with a free-form role, hold decisions where group members vote (approve/reject/abstain) with majority or unanimous resolution, run date or choice polls where members pick yes/no/maybe per option (Doodle style), distribute the project profit among members as percentage or fixed shares, and record a cooperation agreement with per-member signature tracking (draft → in signing → active once everyone has signed)
* Rental management: borrower, period, deposit, fee
* Availability checks across overlapping rental and project booking periods
* Status flow: Reserved → On loan → Returned (or Cancelled)
* Inquiry pipeline: public request form feeds an admin pipeline (New → Contacted → Offer → Won/Lost), one-click conversion into a rental
* Public frontend via shortcodes and Gutenberg blocks: equipment list, availability check, request form, item detail pages
* XLSX/CSV import and export
* Email notifications with editable templates
* iCal calendar feed of all reserved/active rentals
* Calendar month view of rentals, projects and schedule entries
* Roles & capabilities: Prepper Manager and Prepper Member
* GDPR tools: export and anonymization of borrower data via the WordPress privacy tools
* Activity log of all changes

Target audience: event technology crews, clubs and associations, rental businesses, workshops and maker spaces.

The plugin is fully translatable; a complete German (de_DE) translation is bundled.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` or install the ZIP via Plugins → Add New.
2. Activate the plugin — the database tables are created automatically.
3. Open the "Project Prepper" menu item in the admin.

== Frequently Asked Questions ==

= How do visitors request equipment? =

Add the `[pp_request_form]` shortcode (or the "PP: Request form" block) to any page. Submissions land in the inquiry pipeline under Project Prepper → Inquiries and can be converted into a rental with one click.

= Can I show my inventory publicly? =

Yes. Use the `[pp_inventory]` shortcode or the "PP: Equipment list" block. Only non-sensitive fields are shown — purchase price, serial numbers and borrower data are never exposed. Each item also gets a public detail page under `/equipment-item/{number}`.

= Does the plugin send emails? =

Yes, optionally: borrowers receive confirmation emails on reservation, handout and return, and the site admin is notified about new inquiries. Templates are editable under Project Prepper → Settings. Emails are sent via `wp_mail()`, so SMTP plugins work out of the box.

= Is the plugin GDPR-friendly? =

Borrower data can be exported and anonymized via the WordPress core privacy tools (Tools → Export/Erase Personal Data, search by email address).

== Screenshots ==

1. Inventory admin: KPI cards, quick-add form, search, category filters and the item list with "on loan" badges.
2. Rentals admin: create a rental with line items, hand out, take back or cancel — with status badges.
3. Inquiry pipeline: requests from the public form move through New → Contacted → Offer → Won/Lost and convert into rentals with one click.
4. Public equipment list via the [pp_inventory] shortcode or block — search, category badges and daily rates.
5. Public request form via the [pp_request_form] shortcode or block — visitors pick equipment and a period.
6. Public item detail page with description, tags, condition and (optionally) the daily rate.

== Credits ==

This plugin bundles the SheetJS Community Edition (https://sheetjs.com, file `admin/js/vendor/xlsx.full.min.js`)
for the XLSX import/export in the admin. SheetJS CE is licensed under the Apache License 2.0
(https://www.apache.org/licenses/LICENSE-2.0), Copyright (C) 2013-present SheetJS LLC.

This plugin bundles the Inter typeface (https://rsms.me/inter/, files `admin/fonts/inter-*.woff2`)
for the admin UI, so no external font request is made at runtime. Inter is licensed under the
SIL Open Font License 1.1 (see `admin/fonts/LICENSE`), Copyright (c) 2016 The Inter Project Authors.

== Changelog ==

= 0.131.0 =
* New: live search in every search box — the list is filtered as you type, without reloading the page. Clearing the search field immediately brings all items back.
* Applies to “My inventory”, the collective inventory, the equipment picker in a project, the network view and the public inventory list.
* The existing search is kept: pressing Enter or “Search” still runs the server-side search — that one also finds details which are not visible in the list (serial number, tags, notes).
* Note for members: after the update you may have to reload the page once for the new search to take effect.

= 0.130.0 =
* New: sets in the inventory — an item can now carry a parts list (“30 m light chain = 3× 10 m segment + 1× feed-in”). The new “Set contents” section in the item form turns your own items into parts of a set.
* Sets are automatically expanded into their parts when booked: availability, approvals and the packing list work with the real parts — booking parts individually automatically reduces the number of free sets. The equipment picker shows “x sets free” along with the parts list.
* Booked sets appear as one group on the project Equipment tab and are changed or removed as a whole (no half sets); the approval requests of a set arrive as a single combined email.
* Inventory lists show sets with a “Set” badge, their parts list and the calculated set count.
* Note: this update contains a small database extension (a new parts-list table plus one extra field). It runs automatically during the update; existing data stays untouched.
* Sets are initially available through project bookings; external rentals and loan requests with sets will follow in a later update.

= 0.129.0 =
* New: the collective inventory can now be filtered by category — filter pills above the table (“All” plus one per category, with icon and count), just like in the personal inventory. One click shows only the items of that category; works combined with the search.

= 0.128.0 =
* Improved: booking equipment — the date fields are now visible and pre-filled with the project period, both when booking and when editing a booking line. Submitted unchanged, bookings keep inheriting the project period as before — if the project moves, they move with it automatically.
* New: bundled emails instead of one email per device — booking several approval-required items in one go now sends only ONE request email per owner, listing all positions.
* New: the approvals screen decides in bulk — approve, reject or postpone each request and send everything with one “Send decisions” click; every requester receives ONE result email. Two new editable email templates.
* Improved: the inventory “Manage” modal is now ONE form for photo, item data and all collective shares — no more separate save button per section. Changes are applied automatically when the window is closed.
* Improved: creating an item in a single step — photo and collective shares can be set right in the create form, without the detour through “Manage”.
* Changed: “Owner approval required” is now pre-selected for new shares (create form, Manage modal and full-inventory share). Existing shares keep their setting.

= 0.127.0 =
* New: “Download all feedback (CSV)” button on the Feedback screen — every entry submitted from the member portal in one file, ready to open in Excel or hand to someone else.
* The file contains time, sender name, type, message, the portal page it came from and the current status; email addresses are deliberately left out, since the export is usually passed on.

= 0.126.0 =
* New: delivery performance — the plugin now manages its own rule block in the site's .htaccess (on Apache/LiteSpeed hosts): compression for HTML/CSS/JS and one-month browser caching for static assets. Pages and stylesheets that used to travel uncompressed on shared hosting now arrive a fraction of the size, making portal page changes noticeably faster.
* The rules are written on activation and refreshed automatically after each plugin update; deactivating the plugin removes them again. The WordPress block and any other rules in .htaccess stay untouched, and every directive is guarded so nothing breaks if a server module is missing.
* New: hover prefetch in the member portal — links are preloaded the moment you point at (or touch) them, so the next page is often already in the browser cache when you click. Only safe same-origin pages are prefetched; actions, exports and login/logout links are never preloaded, and data-saver mode is respected.

= 0.125.0 =
* New: invitation onboarding happens entirely in the member portal — invited people no longer need wp-admin to get their account.
* The collective invitation email now contains a personal join link that opens a “Join the collective” card on the portal login page with the invited email pre-filled — just pick a name and password to create the account and accept the invitation.
* After signing up, new members are logged in right away and either join directly (single-member collective) or see that the members now vote on their admission.
* The login page also offers “Received an invitation? Create your account” for people without the link — entering exactly the invited email address works too, even while self-registration is switched off.
* If an account with the invited address already exists, the join link points you to the normal sign-in instead.

= 0.124.1 =
* Fix: dark mode — dark text colors on status chips, notice boxes, KPI values, calendar chips, vote buttons and the platform banner were unreadable on the dark background; now bright, readable colors everywhere (light mode unchanged).
* Fix: the password field on the member login was white in dark mode instead of matching the app look.

= 0.124.0 =
* New: SMTP settings under Project Prepper → Settings — all plugin emails (invitations, loan requests, booking approvals, login codes) can now be sent through a real mailbox instead of the server's PHP mail (better deliverability, less spam classification).
* New: configurable sender name and address — replaces the wordpress@… default sender address.
* Improved: on failure the test email now shows the mail server's actual error message (e.g. "SMTP login failed") instead of just "could not send".

= 0.123.0 =
* App look aligned: the indigo edge glow of the live app now applies portal-wide across all views (buttons, links, tabs, chips, cards in projects/dashboard/groups/public inventory), light and dark. Pure CSS change, no schema or i18n change.

= 0.122.0 =
* Security hardening: closed a cross-collective data leak — a group member could previously read inventory, rental and inquiry data of other collectives via the REST API and the operator admin pages. Members now only see their own collectives; the member portal keeps its full scope.
* Fixed a media permission gap (an item document could be deleted without belonging to that item) and neutralized formula/CSV injection in the inventory export.
* Smaller hardening: escaped the public item page title, added access checks on project delete/remove, restricted the updater download to GitHub hosts.
* Login throttling is now ON by default (5 failed attempts per IP block logins for 15 minutes) — operators can turn it off under Settings → Security.
* Schema 0.38.0: no table changes; the version bump re-applies the tightened member role on existing installs.

= 0.121.0 =
* Packing list: each booking now has a second status “Tested” next to “Packed” — an own saved status with its own checkbox on the printed A4 list.
* The tested status stays after reload and is shared with everyone in the group; the header counter shows both, e.g. “N of M packed · K tested”.
* Schema 0.37.0: adds a tested_at column on project items (additive, no migration needed).

= 0.120.0 =
* Fix: creating, editing or deleting an item in “My inventory” no longer jumps back to the dashboard — you now stay in the inventory view after the action.

= 0.119.0 =
* Projects: new “Packing list” tab — a print-ready A4 list of the equipment booked for a project (quantity, photo, item with number and description, condition, and a packed checkbox).
* The packed status is saved per booking, stays after reload and is shared with everyone in the group, with a “N of M items packed” counter.
* A print button and print stylesheet output just the list on A4 with the project header and a check box per item; the list is read-only for viewers outside the owning collective.
* Schema 0.36.0: adds a packed_at column on project items (additive, no migration needed).

= 0.118.0 =
* Collectives: a richer per-group detail page with Overview / Settings tabs — members now show their email, the header shows the founding date, and invitations show the personal message, an “invited by …” line, clear status (waiting / voting / joined) and reminder counts. You can attach a message when inviting, resend the invite (counted) and, during voting, remind members who haven’t voted yet.

= 0.117.0 =
* Collectives: open invitations can now be resent (sends the invite email again, as often as needed) and deleted, right from the collective card.

= 0.116.0 =
* Fix: inviting someone to a collective by email always showed a generic “Something went wrong” — it now shows the actual reason (invalid email, already a member, already invited, daily limit, not a member). Inviting or founding a collective now returns you to the collectives view.

= 0.115.0 =
* Equipment: existing bookings can now be edited (quantity, dates, notes) instead of re-booking. If the item needs approval and the change is material (more quantity or a different period), it goes back to pending and the owner is asked to approve again; a smaller quantity or a note-only change keeps the existing approval.

= 0.114.0 =
* Equipment: an approval workflow for booking shared gear — booking an item that its owner shared with “approval required” now creates a pending request; the owner gets an email and an in-portal “Approvals” view to accept or decline it under their own terms. The picker now shows each item’s owner, daily rate, sharing conditions and whether approval is required; each booking line shows its approval status.

= 0.113.0 =
* Equipment: the booking picker now caps each item’s quantity at what’s actually available and disables items with nothing free, so you can’t select more than exists.

= 0.112.0 =
* Equipment: items already booked for the current project are now flagged “already booked (N×)” in the booking picker, so you can see at a glance what’s in the project.

= 0.111.0 =
* Updates: a “Check for updates now” button on the Security admin page forces a fresh update check (clearing the plugin's own cache and WordPress's) and jumps straight to the update screen when a new version is available. The failed-check cache window was also shortened from 15 to 2 minutes.

= 0.110.0 =
* Calendar: two-way CalDAV — external calendar apps (Apple Calendar, Thunderbird, DAVx5) can now subscribe to your portal calendars and create, edit and delete events that sync back. Sign in with your username and calendar token; the CalDAV URL is shown in the calendar’s subscribe section next to the read-only iCal feed.

= 0.109.0 =
* Portal: presence indicators — a lightweight heartbeat shows which members of your collectives are currently online (green dot and an “N online” count on the collective card and dashboard).

= 0.108.0 =
* Collectives: Telegram notifications — the site operator sets a bot token, founders add their group's chat ID, and the portal posts a short message to the group's Telegram chat for new inquiries, equipment bookings and RSVP confirmations.

= 0.107.0 =
* Collectives: founders can now remove members and dissolve a collective directly in the portal (dissolving keeps the projects and moves them to the site level). Plus internal code-quality fixes (output escaping and prepared-SQL annotations).

= 0.106.0 =
* Projects: the equipment picker now appears inline in the Equipment tab (like the inventory list) instead of a modal, and shows a thumbnail image for each item.

= 0.105.0 =
* Projects: book several pieces of equipment at once — the equipment picker now uses checkboxes with a per-item quantity instead of single-select.

= 0.104.0 =
* Member portal: week time-grid calendar view with a subscribable personal iCal feed; group logo upload; team RSVP on group inquiries; poll votes can be cleared; image lightbox for project files; task accept/decline flow; and up/down reordering for schedule, checklists and checklist items.

= 0.103.0 =
* Member portal: app-style tab bars and full functionality across all sections — inquiries with pipeline detail view, offer fields and KPIs; rentals with tabs and edit modal; calendar with own calendars (colors) and event management; polls with participant matrix, deadlines and filters; costs with plan/actual per line; group editing; workspace-aware dashboard. The active tab is kept across all actions.

= 0.101.0 =
* Projects: the project detail now uses an app-style tab bar (overview, schedule, equipment, team & contacts, materials, costs, checklists, tasks, polls, agreement, files, profit) instead of one long page — the active tab is kept across actions.

= 0.100.0 =
* Projects: the full project detail is now editable in the member portal — schedule, tasks, checklists, materials, costs & budget, team & contacts, files, revenue and profit shares (previously read-only).

= 0.99.1 =
* Projects: booking equipment now happens in an app-style modal with live search instead of a dropdown — the item list shows inventory number, owner, availability in the project period and daily rate.

= 0.99.0 =
* Projects: book equipment directly in the member portal — in the active collective workspace the "Booked equipment" section now lets you book items from the collective's shared pool (with an availability hint showing how many are free in the project period), edit quantity, period and notes of existing bookings, and remove them. Overbooking is blocked with a clear error message.

= 0.98.12 =
* "My inventory" now lists all items on one page — the 12-per-page pagination has been removed. Search and category filters work unchanged.

= 0.98.11 =
* Fix: buttons that open a pop-up ("Share inventory", "New poll", "Borrow", feedback) did nothing — a click guard meant to ignore interactive child elements also caught the trigger button itself. Clicking these buttons now opens their dialog again; clicks on links and buttons inside list rows are still passed through as before.

= 0.98.10 =
* Inventory import: unknown categories from a CSV/XLSX file are now created automatically as new categories (with a three-letter prefix) instead of landing in the default category — matching the Excel import of the main app.
* Inventory import: the condition "Befriedigend" from app exports is now imported as "Fair" instead of silently becoming "Good".

= 0.98.9 =
* Critical fix: hidden pop-ups no longer stack up. A change in 0.98.8 accidentally forced every dialog to be visible at once, so closed windows piled up on the screen. Dialogs are now only shown when actually opened.
* Dark mode: the member portal now follows your device's light/dark setting automatically, matching the main app. Light mode looks exactly as before; in dark mode the background, top bar and panels switch to the dark palette (the sidebar stays dark in both modes).
* Dark-mode polish: the feedback pop-up and all pop-up form fields (including number fields) now render correctly in dark mode instead of appearing white.

= 0.98.8 =
* Inventory "manage" pop-up in the app look: the detail/edit window now mirrors the main app — the photo sits at the top, the edit fields are laid out in a two-column grid, sharing and documents follow as sections below, and a fixed footer keeps "Close" on the right and "Delete" on the left while the body scrolls.
* Richer "share whole inventory": when you share your entire inventory with a group you can now set a default daily rate, default approval requirement and default conditions (just like single-item sharing). These defaults only apply to items that are newly shared — items you already shared keep their individual terms.

= 0.98.7 =
* Update checks are now token- and limit-free on every site: the in-plugin updater first reads update info from a small static manifest served over GitHub's CDN — no 60-per-hour rate limit and no token required. The GitHub API (with the optional token from 0.98.6) stays in place as an automatic fallback. The manifest source can be overridden via the PP_UPDATE_MANIFEST constant or the pp_updater_manifest filter.

= 0.98.6 =
* More reliable auto-updates: the in-plugin updater can now use an optional GitHub token. Without one, GitHub limits update checks to 60 requests per hour per server IP, which fails intermittently on shared hosting. With a token the limit rises to 5000 per hour. Set it via the PP_UPDATE_TOKEN constant in wp-config.php (most secure) or under Project Prepper → Security. For a public repository a token without any scopes is enough.
* Faster retry after a rate limit: failed update checks (e.g. a GitHub 403) are now only cached for 15 minutes instead of 6 hours, so "Check again" reaches GitHub again much sooner.

= 0.98.5 =
* Visual polish: the member portal pop-up windows (inventory detail, feedback, share inventory, new poll, borrow) now match the look of the main app exactly — a tidier, more compact card with a clear title row, the inventory number shown as a small badge underneath, calmer spacing and a softer shadow.

= 0.98.4 =
* Inventory detail in the member portal now opens in a proper pop-up window instead of expanding the row inline — clearer overview, especially on larger inventories.
* Richer group sharing: when you share an item with one of your collectives you can now set a daily rate, decide whether handover needs your approval, and attach conditions — both ready-made presets (e.g. non-commercial use only, deposit required, prior instruction) and free-text notes.
* New feedback channel: members can send feedback (bug, idea or something else) straight from the portal via a button in the top bar. Operators read it under Manage → Feedback, with an unread badge.
* "Share inventory", "New poll" and "Borrow" now open in a pop-up window instead of an inline drop-down, matching the rest of the portal.
* On update the database is automatically migrated (schema 0.27.0): new sharing options and the feedback inbox.

= 0.98.3 =
* Privacy: the whole site is now private to the outside world — only the login is publicly reachable. Visitors who are not logged in are sent straight to the member portal (login) from every public page: the start page, equipment catalogue, request form, archives, search and 404. The old public marketing start page with unprotected inventory is gone.
* Exceptions that stay reachable without logging in: the portal page itself plus the legally required Imprint and Privacy policy pages (detected by their shortcodes or the official WordPress privacy page, regardless of slug). robots.txt and favicon are untouched.
* Logged-in members browse freely as before; only the marketing start page redirects them into the portal.
* The login screen now links to Imprint and Privacy policy underneath it, so the legal disclosure duty (§ 5 DDG) is met.
* For developers: the restriction can be turned off with the filter "pp_restrict_public_pages", and individual pages can be made public again via "pp_page_is_public".

= 0.98.2 =
* Fix (updates): clicking "Check again" on Dashboard → Updates now also clears the plugin's own 6-hour update cache and queries GitHub immediately. Previously a freshly published release could stay invisible for up to 6 hours. Routine background checks still use the cache, so there is no extra traffic.

= 0.98.1 =
* Fix: corrected the plugin's "Plugin URI" header. It used to point at the Next.js web app; it now points at the plugin's actual home and update source on GitHub, so the "Visit plugin site" link on the WordPress Plugins page leads to the right place.

= 0.98.0 =
* Member portal — inventory is now workspace-aware: when a group is the active workspace, the inventory page shows that group's shared equipment pool (every item members have shared into the group) instead of always showing your personal items. The shared view is read-only and lists the owner per item, with a count of shared items and a friendly hint when nothing has been shared yet. Solo mode is unchanged and still shows "My inventory".
* Wider, denser desktop layout: on screens from 1024px up the content area now uses up to 1440px (was 1180px) and a slightly smaller base font, so more of your lists and tables fit on screen.
* Wording: the "Browse" labels in the borrowing area are now "Search" ("Search & borrow", "Search the shared …"), matching how the feature is actually used.

= 0.97.0 =
* Member portal — new "Costs" view (group mode): a global cost overview aggregated across all projects of the active group, mirroring the app's `/costs` page — KPI cards (projects, planned, actual), category filter pills and a per-item list (project link · category · description · amount, with a "planned" marker for items that have no actual yet). Solo mode shows the same hint as the app (costs live inside each project). Leak-safe: the source is the member's own group projects only.
* Member portal — group detail: the member roster is now a proper list (member count header, each member with founder/member role badge and join date, "you" marker) instead of a comma-separated line of names.

= 0.96.0 =
* Member portal — project detail: added read-only Participants, Costs & budget (net/gross/VAT/budget/revenue/profit), Profit distribution (per-member shares with calculated amounts) and a Cooperation agreement summary (status, version, signature roster). These are visible to group members only — viewing the detail already requires membership of the owning group, so financial figures are never exposed to outsiders.
* Member portal — calendar: own external rentals (reserved/active) now appear alongside collective loans, and members who may view operator rentals get a link to subscribe to the read-only iCal feed.

= 0.82.0 =
* Control centre: the admin Projects tab is now a read-only moderation list (number, name, period, owner, bookings, status, with status filters and a remove action). Members create and edit projects in the member portal; the backend is for oversight, not data entry. The large editing modal was removed.

= 0.81.0 =
* Privacy/control centre: the admin Inquiries tab now only shows inquiries from the public request form (no member owner). Inquiries that belong to a member or group are private to the member portal and only counted here as an aggregate — the operator no longer sees their content.

= 0.80.0 =
* Member portal: turn an inquiry into a project. In a group workspace, an open inquiry gets a "Create project" button that spins up a group project from its data (name, dates, client, notes) and marks the inquiry won. Solo inquiries stay bookkeeping (no solo projects).

= 0.79.0 =
* Member portal: group members can now create, edit and delete their group's projects directly in the portal (core fields: name, status, dates, venue, client, notes) — no more switching to the admin. Access is scoped to the active group workspace.

= 0.78.0 =
* Member portal: inquiries are now a member feature. Each member (solo or group workspace) keeps their own inquiries in a new "Inquiries" view — create, edit, move through the pipeline (New → Contacted → Offer → Won/Lost) and delete, all scoped to the active workspace. A dashboard KPI and nav item were added. (Turning a won inquiry into a project follows in a later step.)

= 0.77.0 =
* Users & permissions: "View as" lets an operator open the member portal as any member (not other administrators or operators) to see exactly what they see; a sticky banner switches back. Secure by design — operator-only, nonce-protected, reversible and logged.

= 0.76.0 =
* Deliverability & health: Settings gets a "Send test email" button (sends to your own address and reports whether WordPress accepted it), and the Federation tab gets a "Check partners now" button that pings every configured partner live and lists which instances are reachable.

= 0.75.0 =
* Calendar tab reduced to its control-centre role: the full month grid is replaced by the iCal feed mechanic (feed URL, copy, regenerate token) plus a read-only "upcoming entries" moderation list (next 90 days). Members manage the calendar in their portal.

= 0.74.0 =
* Inventory mechanic: the backend Categories tab now manages template categories the operator maintains; categories members create themselves stay private
* Member portal: members can create their own inventory categories and adopt the operator’s suggested templates ("My categories" panel); the item form groups own categories and templates separately

= 0.73.0 =
* Admin: the activity feed now labels more action types in plain language (item, collective, inventory, network and GDPR actions)

= 0.72.0 =
* Admin: the Platform page now opens with a "Needs attention" cockpit — open join votes, open and overdue borrow requests, incoming network requests and unreachable partners at a glance

= 0.71.0 =
* Member portal: when the operator requires it, members must read and accept the terms of use before using the portal; re-acceptance is requested whenever the terms change

= 0.70.0 =
* Admin: new "Instance" page to configure your platform — identity & purpose, the economy model (free, donation, membership, fees, pro tier) and your terms of use. Federation now publishes the identity from here

= 0.69.0 =
* Admin: the Inventory tab is now a read-only moderation list (search, filter, remove) — members add and edit their own items in the member portal; the backend is for oversight, not data entry

= 0.68.0 =
* Admin: inventory, projects, groups, rentals, inquiries, calendar, categories and federation are now combined into a single "Manage" menu item with tabs — a much shorter menu

= 0.67.0 =
* Admin: the control-centre pages (Platform, Users & permissions, Settings, Email templates, Security, Federation) are now bundled under a single operator permission and reserved for administrators

= 0.66.0 =
* Admin: dedicated "Email templates" page — edit every email the plugin sends (rentals, inquiry, group invitation, borrow request/decision, login code), each with its available placeholders; moved out of Settings

= 0.65.0 =
* Admin: new "Users & permissions" control-centre page — set each account's role, toggle fine-grained permissions, see group memberships and last login (administrators only)

= 0.64.0 =
* Self-update from GitHub releases: instances now see "update available" and can one-click update straight from wp-admin, even though the plugin is distributed outside wordpress.org

= 0.63.0 =
* Admin: the Platform, Security and Federation screens are now part of the unified JavaScript app (REST-backed) like every other module — consistent look and no full-page reloads when saving

= 0.62.0 =
* Federation: an approved cross-instance borrow is now a real loan — it reserves a unit and blocks availability (local and federated stay in sync); the owner can mark it returned, which frees the unit and updates the requesting instance

= 0.61.0 =
* Member portal: calendar now has a Month/Week toggle — the week view shows all events per day without truncation

= 0.60.0 =
* Member portal: borrowing & lending now separates active requests from a collapsible history of completed transactions (returned, declined, cancelled), both directions

= 0.59.0 =
* Member portal: a notification bell in the top bar shows pending invitations, join votes and incoming borrow requests (local and federated), each linking to the right page

= 0.58.0 =
* Member portal: edit your display name and upload a profile photo (shown in the top bar and member lists); falls back to initials

= 0.57.0 =
* Member portal: browse view now has a date-range filter — see how many units of each shared item are free for your exact dates, and the borrow form is pre-filled with that period

= 0.56.0 =
* Member portal: self-service data export — download a JSON copy of your profile, inventory, collectives and borrow records (GDPR Art. 15/20)

= 0.55.0 =
* Member portal: attach PDF or image documents (e.g. manuals, receipts) to your own inventory items, with download links and removal

= 0.54.0 =
* Member portal: members can now leave a group themselves (with confirmation); the last founder is protected from leaving

= 0.53.0 =
* Member portal: "My inventory" now has a search box and pagination (12 items per page), so large inventories stay manageable

= 0.52.0 =
* Member portal: borrowing now checks availability up front — a request for a period with no free units is rejected immediately with a clear message (instead of failing later at the owner's approval), and the browse list shows how many units are free today

= 0.51.0 =
* Member portal: the inventory add/edit form now has more fields — manufacturer, model, serial number, location, dimensions and tags (matching the CSV columns)

= 0.50.0 =
* Member portal: members can now upload a photo for each of their own inventory items (and remove it again); a thumbnail shows in the list. Images go into the WordPress media library; only JPG/PNG/GIF/WebP are accepted

= 0.49.0 =
* Member portal: "My inventory" now has CSV export and import — download your items as a semicolon-separated CSV (opens in Excel) and bulk-add items by uploading a CSV with the same columns. Works in Solo and in a group; the import also fills fields the quick-add form doesn't (manufacturer, model, serial number, tags, etc.)

= 0.48.0 =
* Member portal: the poll form now starts with three option boxes and an "+ Add option" button to add more (small enhancement script; without JavaScript the starting boxes still work)

= 0.47.0 =
* Member portal: when creating a poll, each option now has its own input box instead of one textarea with one option per line (applies to project and group polls)

= 0.46.0 =
* Member portal: a standalone "Polls" tab now appears in group mode (like the web app) — group-wide polls (appointment finding / decisions) that are not tied to a project; members vote yes/no/maybe per option and can create or close polls
* Reuses the existing poll mechanics; a poll can now belong to a group directly (group_id) as well as to a project

= 0.45.0 =
* Member portal: the sidebar labels now follow the active workspace, like the web app — in Solo they read "My inventory / My lending / My projects / My groups", in a group they read "Inventory / Lending / Projects / All groups"

= 0.44.0 =
* Member portal: a workspace switcher in the sidebar lets members switch between "Solo" and each of their groups; projects, the calendar and browsing are scoped to the selected workspace
* Terminology unified to "group" throughout the member portal (previously "collective"), matching the rest of the plugin

= 0.43.0 =
* Federated borrowing (requesting side): on the Network page members can now "ask to borrow" an item from a partner instance — the request is sent to that instance and a "My network requests" list shows its status (requested → approved/declined), polled from the partner
* Requests carry only the member's name and contact email; no account is created on the partner instance
* Completes the cross-instance borrowing loop started in 0.42.0

= 0.42.0 =
* Federated borrowing (supplying side): an instance can now accept borrow requests from partner instances for its members' items — a new opt-in "Federated borrowing" switch on the Federation page (off by default)
* Requests are trusted only from configured partner instances and are moderated by the item owner in the portal ("Network requests for your items": approve or decline), with the requester's contact shown for arranging handover
* Abuse protection: per-instance daily rate limit, honeypot, required contact email, usable items only; the requesting instance polls the decision via an unguessable token
* The matching requesting-side UI ("ask to borrow" in the Network page) follows in the next release

= 0.41.0 =
* Federation (cross-instance): members get a new "Network" page that shows the shared inventory of connected partner instances, grouped by instance with postal code and topic, and a filter by item, postal code or topic
* A new public, opt-in endpoint exposes this instance's usable catalogue as anonymised whitelist data (no owner, serial numbers, prices unless rates are made public) for partner instances to read
* Read-only for now — items link to the partner instance's public page; borrowing across instances is not yet available

= 0.40.0 =
* Member portal: decisions and polls are now interactive inside a project — members of the owning collective can vote on decisions (approve/reject/abstain) and on polls (yes/no/maybe per option), and can create new decisions and polls themselves
* Authors can close a decision or close/reopen a poll; results and tallies update immediately, with the same resolution rules as the web app
* Voting is strictly limited to active members of the project's collective
* Fix: member form submissions (founding/joining collectives, sharing inventory, borrow requests, voting) were being redirected away before they ran, because the form endpoint also triggers the admin guard; that endpoint is now exempt, so all member actions work for portal-only members

= 0.39.0 =
* Member portal: new "Calendar" page — a read-only month view that brings together the projects of your collectives, their schedule entries and your own loans, with month navigation and a colour-coded legend
* Calendar entries link back to the relevant project or to the lending page
* Strictly scoped to what the member may see (their collectives only); no site-wide rental data is shown

= 0.38.0 =
* Member portal: new "My projects" page — members can browse the projects of the collectives they belong to and open a read-only detail view (overview, booked equipment, schedule, tasks, checklists, materials, team, contacts and files)
* A "Projects" KPI card was added to the member dashboard
* Financial and governance sections (costs, budget, profit shares, decisions, polls, agreement) are intentionally not shown to members in this view

= 0.37.0 =
* Member portal redesigned as a full-screen app, matching the look of the web app: a dark sidebar with navigation (Dashboard, My inventory, Lending, My collectives), a top bar with the member's name and sign-out, and a dashboard landing page with KPI cards and a "How the platform works" panel
* The portal is now navigable view by view instead of one long scrolling page; the existing features (collectives, inventory sharing, browsing and borrowing) keep working unchanged
* The shell renders through its own page template, so it looks the same regardless of the active theme

= 0.36.0 =
* The Platform admin page now shows a "Recent activity" feed — who founded a collective, invited someone, shared an item, requested or returned a loan, and more — so operators can follow what is happening across the member portal at a glance
* Activity is read from the existing log and shown with human-readable, translated labels

= 0.35.0 =
* Federation directory: operators can list partner instance URLs on the Federation page; the plugin fetches each one's public profile and shows a "Known instances" table with postal code, topic and counts (cached for an hour, unreachable instances are flagged)
* Outbound requests go only to operator-configured URLs and read just the public discovery endpoint — no personal data leaves the site

= 0.34.0 =
* Federation (first step): a new "Federation" admin page lets an instance opt in to being discoverable by other Project Prepper instances via postal code and topic — OFF by default
* Public discovery endpoint /wp-json/project-prepper/v1/federation/info returns the instance's coarse public profile (name, postal code, topic, collective and member counts) only when enabled; otherwise 404 — no personal data is ever exposed
* Cross-instance browsing/borrowing will build on this in a later step

= 0.33.0 =
* Two-factor polish: members can now resend the login code from the code screen (limited to 3 resends per attempt), and the 2FA email now uses an editable template like the other notifications
* The 2FA code email always sends regardless of the global notifications switch (it is security-critical)

= 0.32.0 =
* Self-service registration on the portal (Security → "Self-registration", still OFF by default): when enabled, visitors get a "Create an account" form on the member portal and become members; off keeps the platform invitation-only
* New accounts are created as members, signed in directly, and any pending email invitations to collectives are linked automatically
* Guards: valid email required, duplicate emails rejected, minimum 8-character password, and a honeypot against spam bots

= 0.31.0 =
* Two-factor login for members is now live (Security → "Two-factor for members", still OFF by default): when enabled, members sign in on the portal in two steps — password, then a one-time 6-digit code sent to their email
* Hardened: codes are stored only as a hash, expire after 10 minutes, allow at most 5 attempts, and use generic error messages (no account enumeration)
* Admins and managers are unaffected and keep the normal wp-admin login; with 2FA off the portal login is unchanged

= 0.30.0 =
* New "Platform" admin page: the member-portal processes come together for operators — KPIs (collectives, member inventory, open invitations, active loans, open requests), open join invitations with voting status, recent borrow requests, and all collectives with member counts
* New "Security" admin page: frontend hardening for the member portal, all OFF by default — login throttling (lockout after repeated failed logins per IP), a collectives-per-user limit and an invitations-per-day limit (anti-snowball), an optional self-registration switch (off = invitation only), and a prepared two-factor switch (saved, full flow to follow)
* Nothing changes unless an operator enables it; with everything off the behaviour is exactly as before

= 0.29.0 =
* Availability for borrowing: a request can only be approved if a unit of the item is actually free in that period — overlapping approved loans are counted against the item quantity, so no overbooking
* Email notifications (member portal): the invitee is emailed on a collective invitation, the owner on a new borrow request, and the borrower when their request is approved or declined — all via wp_mail with editable templates, and all respecting the global notifications on/off switch

= 0.28.0 =
* Browse & borrow in the member portal: members see the equipment their collectives share and can request to borrow an item for a period — non-commercial, no fees
* The item owner approves or declines each request; both sides can mark an approved loan as returned, and the borrower can cancel a pending request
* "My borrow requests" and "Borrow requests for your items" lists with clear status (requested, approved, declined, cancelled, returned)
* Strict guards: you can only borrow items shared with a collective you belong to, never your own, and only the owner decides

= 0.27.0 =
* My inventory in the member portal: members add, edit and delete their own equipment on the front end (owned per user), with name, category, quantity, condition and an optional daily rate
* Share your items with your collectives: toggle each item on or off for any collective you belong to — shared items become visible to that collective (browse & borrow follows)
* Strict per-user scoping: you only ever see and change your own items, and can only share with collectives you are a member of
* Deleting an item also removes its collective shares

= 0.26.0 =
* Collectives self-service in the member portal: members can found a collective, invite others by email, accept/decline invitations and vote on who joins — all on the front end, no wp-admin
* Join voting mirrors the web app: when an invitee accepts, the active members vote and the person joins only on unanimous approval (a single rejection blocks it); with a sole founder the join is immediate
* Email invitations are linked to the matching account automatically on registration
* Admins/managers keep the back-end override (add or remove members directly in the Groups admin)

= 0.25.0 =
* Member portal (foundation): a new front-end portal page ([pp_member_portal], created automatically) where members sign in and see their collectives — members work entirely on the website, not in wp-admin
* Members (role "Prepper Member") are now kept out of wp-admin: they are redirected to the portal and the admin bar is hidden, while admins and managers keep full back-end access
* Access is invitation-only by design — the portal shows a sign-in form and a note that accounts are set up by the platform operators (no open registration)
* Per-user inventory ownership groundwork: inventory items and categories gained an optional owner (owner_user_id); existing data stays collective-owned (unchanged)
* Front page reframed as a collective platform landing (found/join a collective, bring in your inventory, lend to each other) instead of a single-operator rental catalog

= 0.24.0 =
* Frontend visual alignment: removed the dark-mode override so the public shortcodes and blocks always render light, matching the theme and web app (previously the cards turned dark on a dark OS while the page stayed white)
* Bundled Inter for the public shortcodes/blocks as well, so typography matches the web app everywhere
* The Inter font files now live under assets/fonts and are shared between the admin UI and the front end (no duplicate files, no external request)

= 0.23.0 =
* Dashboard start page now matches the web app: a personal greeting ("Hello {name}") with the site name, and a dismissible "How Project Prepper works" banner with the four-step workflow
* The banner can be hidden per browser; the KPI cards, upcoming list and recent activity remain below
* Removed the dark-mode override so the admin always renders light like the web app (fixes low-contrast text on the WordPress admin background)

= 0.22.0 =
* Visual alignment with the web app: the admin UI now bundles the Inter font locally (self-hosted, no external request) so typography matches the live app
* The project detail dialog now uses a tabbed layout (Overview, Equipment, Schedule, Team & contacts, Material & transport, Costs, Profit, Checklists, Tasks, Polls, Decisions, Agreement, Files) instead of one long scroll — the segmented tab bar mirrors the app
* Refined section headers (uppercase, muted) and inventory list styling: item condition is shown as coloured text and the inventory number as a small monospace badge under the name
* No functional or schema changes — styling, markup wrappers and font bundling only

= 0.21.0 =
* New "Calendar" section: a read-only month view that merges existing data — rentals (reserved/active/returned), project periods and project schedule entries — into one grid
* Multi-day rentals and projects appear on every day of their period; schedule entries show their start time; events link to the related rental or project
* Month navigation (previous/next/today), today highlight, German weekday labels and a colour-coded legend; the existing iCal feed URL is linked below the grid
* Group access is respected: project and schedule events of group projects are only visible to members of the owning group; rentals are site-wide
* No two-way sync / CalDAV (the app's CalDAV is a separate standalone service) — this is display only, alongside the existing read-only iCal feed

= 0.20.0 =
* New "Polls" section in the project detail (group projects only): create date polls (Doodle-style scheduling) or choice polls and let the active members of the owning group vote yes/no/maybe per option
* Each option shows a tally (yes/maybe/no) and the option with the most yes votes is marked as the best option; the author or an admin can close, reopen or delete a poll (polls stay open until closed manually — there is no automatic resolution)
* Voting requires only project view access plus group membership; creating, closing and deleting require the author or an admin — non-members of a group project cannot see or vote in its polls

= 0.19.0 =
* New Dashboard as the plugin's start page: KPI cards for inventory (items, total pieces, out today, daily value), active and reserved rentals, open inquiries and running/planned projects
* An "Upcoming" section lists rentals and projects starting within the next 14 days; a "Recent activity" feed shows the latest changes with a human-readable label and the acting user
* Inventory moved from the top-level menu entry to its own "Inventory" submenu — all other pages keep their place
* Project counts on the dashboard respect group access: members only see the group projects they belong to

= 0.18.0 =
* New "Cooperation agreement" section in the project detail (group projects only): record a free-form contract and track each group member's signature
* One agreement per project with a status flow: draft (freely editable) → in signing (the contract text is locked, members sign or decline) → active (set automatically once every active group member has signed) — and it can be terminated at any time
* The signature roster shows every group member as signed (with date), declined or pending; a single decline prevents activation without resetting the agreement — the author or an admin then revises it (which clears all signatures and bumps the version) or terminates it
* Signing requires only project view access plus group membership; the author or an admin manages the lifecycle (open, revise, terminate, delete) — non-members of a group project cannot see or sign it

= 0.17.0 =
* New "Profit sharing" section in the project detail (group projects only): distribute the project's profit pool among members of the owning group
* The profit pool is the project profit from the Costs section (revenue minus actual non-excluded costs); each share is a percentage of that pool or a fixed amount
* Per-row calculated amounts plus a summary block: allocated total, unallocated remainder and an over-allocation warning highlighted in red
* When no revenue is set the pool is undefined: percentage rows show no amount yet, fixed amounts still count
* Reading or editing profit shares requires project view/edit access plus group membership — non-members of a group project cannot see them

= 0.16.0 =
* New "Decisions" section in the project detail (group projects only): create decisions and let the active members of the owning group vote — approve, reject or abstain
* Two resolution modes: unanimous (approved once every member approves, rejected as soon as one member rejects) or majority (resolved only once all members have voted, then more approvals than rejections wins)
* Each member casts one vote and may change it while the decision is open; the running tally and your own vote are shown
* The author or an admin can close a stuck open decision early, or delete a decision with all its votes
* Voting requires only project view access plus group membership — non-members of a group project cannot see or vote on its decisions

= 0.15.0 =
* New "Project members" section in the project detail (group projects only): list the participants of a project, picked from the owning group's members, each with a free-form role and an optional note
* The member picker only offers group members who are not already listed; a participant must be an active member of the project's owning group
* Site-level projects (no group) show a hint instead of the roster
* Member routes respect the group access guard: non-members of a group project cannot list or change its members

= 0.14.0 =
* New Groups feature (foundation): create groups of WordPress users and assign a project to a group
* Group projects are only visible to their members (and admins); site-level projects (no group) keep behaving exactly as before — the change is fully additive
* New "Groups" admin page: list groups, create them, manage members (add site users as founder/member, remove; the last founder is protected)
* New "Group" selector in the project detail and create form ("— site level —" by default)
* New capability "pp_groups_manage" granted to Administrator and Prepper Manager
* Deleting a group returns its projects to site level (projects are never deleted)

= 0.13.1 =
* Inventory item detail now lists the projects an item is booked in (name, status, quantity and period) — the named counterpart to the aggregated "out" badge
* Items are deep-linkable in the admin via #pp-item-{id} (used by the project booking links)

= 0.13.0 =
* New Files section in the project detail: attach files from the WordPress media library to a project; each file shows as a link with its MIME type and can be removed
* Detaching a file only removes the link — the media item stays in the library; orphaned links (deleted media) are flagged

= 0.12.0 =
* New Materials section in the project detail: consumables with name, quantity, unit and optional cost, plus a total material cost
* New Team & Contacts section in the project detail: team members (name, role, department) and external contacts (name, role, company, email, phone)

= 0.11.0 =
* New Costs section in the project detail: cost items with category (personnel, material, inventory, external services, other), description, planned and actual net amounts, VAT rate per line and an "exclude from profit" flag
* Project budget and revenue (net) fields with a summary block: planned/actual net and gross, budget variance (highlighted when over budget) and profit (revenue minus actual costs)

= 0.10.0 =
* New Schedule section in the project detail: chronological run-of-show entries with date, optional time range (from/to), title and location
* Add and remove schedule entries inline; entries are sorted by date and start time, undated entries last

= 0.9.1 =
* Inventory overview now reflects projects too: the "out today" KPI, the per-item badge and the "out" filter count confirmed/running project bookings in addition to rentals (same logic as the availability check)
* Renamed the inventory badge/filter wording from "on loan" to the more accurate "out", since items can now be out for a project as well as a rental

= 0.9.0 =
* New Projects module: projects with their own number range (P-YYYY-NNNN), status flow Draft → Planned → Confirmed → Running → Done (or Cancelled), venue and client data
* Equipment bookings per project — line items with optional own period (otherwise inheriting the project period) and availability checks
* Availability is now checked across rentals AND confirmed/running projects: a confirmed project blocks inventory for overlapping rentals and vice versa
* Checklists per project: multiple lists with checkable entries
* Tasks per project: title, priority, due date and an open → in progress → done status toggle
* New capabilities pp_projects_view and pp_projects_edit (Administrator and Prepper Manager get both, Prepper Member can view)

= 0.8.2 =
* Directory assets for wordpress.org (banner, icon, screenshots) and a Screenshots section in the readme

= 0.8.1 =
* Plugin Check compliance: all database queries use %i identifier placeholders with $wpdb->prepare()
* All request parameters are unslashed and sanitized before use
* "Tested up to" bumped to WordPress 7.0

= 0.8.0 =
* Internationalization: all source strings are now in English; a complete German (de_DE) translation is bundled (admin UI, frontend, emails, blocks)
* JavaScript admin UI and block editor use wp.i18n with script translations
* Email default templates, export column headers and condition labels are translatable
* Import column auto-mapping now recognizes German and English headers (and no longer mismatches "Serial number" columns)
* readme.txt rewritten in English for the wordpress.org directory

= 0.7.0 =
* Öffentliche Artikel-Detailseite unter /equipment-item/{inventarnummer} — Theme-überschreibbares Template, Inventar-Karten verlinken darauf; defekte/ausgemusterte Artikel liefern 404 (außer für eingeloggte Inventar-Nutzer)
* Neue Einstellung "Tagessätze öffentlich zeigen" für die Detailseite (Kaufpreis und Seriennummer sind öffentlich nie sichtbar)
* Anfragen-Pipeline wie in der App: Neu → Kontaktiert → Angebot → Gewonnen | Verloren (mit erzwungenen Übergängen); "In Verleih übernehmen" markiert die Anfrage als Gewonnen
* "PDF anzeigen"-Link direkt in der Inventar-Liste: ein Dokument öffnet sofort, mehrere öffnen das Detail-Modal
* Neue Artikel-Felder "Eigentum & Abschreibung": Eigentumsart, Finanzierungsquelle, Abschreibungsmethode, Nutzungsdauer, Restwert (reine Dokumentation)
* Kategorien zusammenführen: alle Artikel wandern in eine Ziel-Kategorie, die Quelle wird gelöscht

= 0.6.0 =
* Excel-Import/-Export im XLSX-Format (SheetJS, lokal gebündelt — kein CDN): Export-Button erzeugt inventar-JJJJ-MM-TT.xlsx mit den aktuellen Filtern, CSV-Export bleibt als zweiter Button erhalten
* Import-Dialog akzeptiert zusätzlich .xlsx/.xls — gleiche Spalten-Zuordnung wie beim CSV-Import, Datums-Zellen werden automatisch als JJJJ-MM-TT übernommen
* Import übernimmt jetzt auch das Kaufdatum (wurde bisher ignoriert), auch im Format TT.MM.JJJJ
* CSV-Export berücksichtigt den Filter "Ausgeliehen"

= 0.5.0 =
* Foto und PDF-Dokumente direkt beim Anlegen eines Artikels hochladen
* Inventar-Filter "Ausgeliehen": Toggle neben den Kategorie-Pills, Badge "n unterwegs" in der Liste
* Anfrage-Detail: Zeile klickbar, Modal mit allen Feldern, vollständiger Nachricht, Equipment-Liste und Aktionen

= 0.4.0 =
* Verleih bearbeiten: Header-Felder und Positionen (Menge, Tagessatz) nachträglich änderbar — mit Verfügbarkeitsprüfung
* Tagessatz pro Position direkt beim Anlegen eines Verleihs
* Anfrage → Verleih übernehmen (ein Klick im Anfragen-Admin)
* Öffentliches Frontend blendet defekte/ausgemusterte Artikel aus (übersteuerbar via show_all="yes")

= 0.1.0 =
* Grundgerüst: Inventar, Kategorien, Verleih mit Verfügbarkeitsprüfung, REST-API, Rollen/Capabilities, Activity-Log.
