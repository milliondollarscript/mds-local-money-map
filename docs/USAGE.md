# Local Money Map

Local Money Map lets a site owner run local sponsor trails, town or event maps, business offer campaigns, and QR-safe sponsor spot links.

## Practical Examples

- Build a downtown sponsor trail where shops purchase map spots and display printable QR codes at their locations.
- Publish a festival map for vendors, stages, community partners, and featured offers, with or without an external interactive tile map.
- Run a local shopping campaign whose printed flyers link each QR code to a stable sponsor page that can be updated without reprinting the code.

### Example workflow: a Main Street sponsor trail

1. Open **Million Dollar Script -> Local Money Map**, create an active campaign, set spot pricing, and decide whether the interactive OpenStreetMap view fits the site's privacy policy.
2. Add the **Local Money Map** block to a campaign page and accept sponsor spot submissions.
3. Review payment and moderation under **Spots**, add or verify coordinates where appropriate, and approve the listing.
4. Download that spot's local QR code or a bounded multi-code print sheet. Scanning the code opens the approved sponsor target while the campaign remains editable in WordPress.

## Setup

1. Activate Million Dollar Script and Local Money Map.
2. Open **Million Dollar Script -> Local Money Map**.
3. Review the seeded campaign or create a new campaign.
4. Configure campaign status, slug, description, spot fee, featured fee, capacity, map display, disclosure text, terms URL, contact email, submission limit, and retention period.
5. Choose manual payment handling or Million Dollar Script payment-provider checkout.
6. Add the campaign to a page with the block or shortcode.

## Admin Workspace

Existing campaigns open on **Spots** for sponsor moderation, check-ins, search, and pagination. Use **Campaign settings** to create or configure a campaign. The selected campaign stays active while moving between tabs, and each tab can be opened directly by URL.

## Public Display

Use the **Local Money Map** block from the Million Dollar Script block category, or add:

```text
[mds_local_money_map id="1"]
```

The block can select a campaign by ID. The slug field is optional and is only used when no campaign ID is selected.

Public views can show approved spots, location details, an interactive map, direct map links, QR target links, and check-in forms depending on campaign settings. Lists are paginated so large campaigns remain usable on shared hosting and mobile devices.

## Locations And Map Privacy

Every submission requires a public location or area label. Latitude and longitude are optional, but both must be supplied together and must fall within their valid ranges. Spots with coordinates appear on the interactive map; spots without coordinates remain available in the location list with an OpenStreetMap search link.

The interactive map reuses the OpenLayers library bundled with Million Dollar Script. It loads map tiles from OpenStreetMap in each visitor's browser, which discloses the visitor's IP address and browser request data to that service. Disable **Show the interactive location map** when external tile requests do not fit the site's privacy policy. Location cards and direct map links continue to work when the interactive map is disabled.

## QR Codes

Approved spots receive stable local QR target URLs using `?mds_lmm_spot=<id>` redirects. The extension generates SVG QR codes locally with a packaged standards-based PHP library, so it does not require GD, Imagick, shell tools, or an external QR API.

Administrators can download individual QR codes and print QR sheets in large, four-per-page, or eight-per-page layouts. Print jobs are divided into bounded batches instead of silently dropping sponsor spots or creating an unbounded shared-host request.

## Moderation

Sponsor spot submissions are held for review. Administrators can approve, reject, mark in review, complete, or archive spots. Check-ins can also be reviewed when enabled.

In provider mode, moderation and payment are separate requirements. A sponsor spot appears publicly only after an administrator approves it and its payment reaches a paid, processing, or completed state. These events can arrive in either order. Rejected, cancelled, failed, or refunded payments remove the spot from public display while preserving its moderation record.

## Payments

Local Money Map uses the Million Dollar Script payment-provider API when provider checkout is enabled. It does not call WooCommerce or other gateways directly. Currency follows the active Million Dollar Script or WooCommerce currency when available.

In manual mode, approving a spot confirms its manual or free payment state. Verify any offline payment before approving the spot.

If provider checkout cannot start, the sponsor spot remains private, its payment is marked failed, and the visitor receives a clear message that no charge was made. The extension never reports a failed checkout as a successful paid submission.

## Legal And Privacy

Local Money Map can contribute a **Local Sponsorship Map Terms** page through the Million Dollar Script extension setup flow. Review it before publishing.

Site owners are responsible for local advertising claims, QR code placement, physical signage, sponsor disclosures, map-provider disclosures, privacy requests, refunds, and any location-specific requirements.

Sponsor and check-in forms require explicit consent. WordPress privacy tools export and erase records in bounded pages, and the daily retention job anonymizes stale contact/proof data according to each campaign's retention setting while preserving non-identifying moderation, public, payment, and accounting records where required.

## REST API

```text
GET  /wp-json/million-dollar-script/v1/local-money-map/boards
GET  /wp-json/million-dollar-script/v1/local-money-map/boards/{id}/spots
POST /wp-json/million-dollar-script/v1/local-money-map/spots
POST /wp-json/million-dollar-script/v1/local-money-map/spots/{id}/moderate
POST /wp-json/million-dollar-script/v1/local-money-map/claims
POST /wp-json/million-dollar-script/v1/local-money-map/claims/{id}/moderate
```

Write and moderation endpoints are administrator-scoped unless a future API policy explicitly allows another actor.

The two public read endpoints accept `page` and `per_page` parameters and return display-ready active campaign and sponsor spot fields only. They do not expose contact names or email addresses, retention settings, payment-provider state, charged amounts, or other private operational records.

## Shared-Host Notes

QR generation and bounded printable sheets are handled locally. Very large print jobs, automatic geocoding, route optimization, fraud scoring, and high-volume campaign analytics are better candidates for an optional hosted service.
