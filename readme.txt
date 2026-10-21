=== Million Dollar Script - Local Money Map ===
Contributors: milliondollarscript
Tags: million dollar script, local money map, sponsorship, paid placement, qr
Requires at least: 6.0
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 0.2.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Run local sponsorship maps, trails, offers, QR target links, printable QR codes, and moderated sponsor spot campaigns.

== Description ==

Local Money Map adds local-first sponsorship trail campaigns to Million Dollar Script. Site owners can accept sponsor spot requests, charge spot or featured fees, moderate listings, collect visitor check-ins, and publish responsive local map pages with clear sponsored-offer disclosure.

Public sponsor spots include a recognizable location label, optional validated coordinates, direct map links, and an optional interactive OpenLayers/OpenStreetMap view. Administrators can disable external map tiles while preserving the accessible location list.

Provider-mode sponsor spots remain private until both administrator approval and successful payment are confirmed. Manual-mode approval confirms an offline or free placement.

Every approved spot gets a local QR target URL, an SVG QR download, and printable QR sheet options. QR codes are generated locally with a packaged standards-based PHP library and no external service. Very large print batches and high-volume campaign services can still be offloaded to an optional hosted service when needed.

== Changelog ==

= 0.2.1 =
* Replaced the hand-rolled QR encoder with the maintained BaconQrCode library.
* Added iconv portability for shared hosts and documented every packaged third-party license.
* Verified generated SVG QR codes with an independent decoder.

= 0.2.0 =
* Added interactive mapped locations and direct map links.
* Added strict shared validation, consent, configurable rate limits, and race-safe capacity checks.
* Added pagination, direct notifications, privacy paging, retention cleanup, and payment failure handling.
* Added explicit light/dark semantic notice colors and expanded production fixtures.

= 0.1.1 =
* Added local SVG QR code generation for approved sponsor spots.
* Added admin QR previews, individual QR downloads, and printable QR sheets.
* Updated public sponsor cards to show QR target links and QR downloads.

= 0.1.0 =
* Initial local-first Local Money Map implementation.
