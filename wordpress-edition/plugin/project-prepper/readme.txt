=== Project Prepper ===
Contributors: motttto
Tags: inventory, rental, equipment, availability, booking
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.80.0
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
