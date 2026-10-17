# Million Dollar Script - Local Money Map

Local Money Map is a local-first MDS 3.0 extension for local sponsorship trails, town/event maps, business offers, and QR-safe spot links.

## Features

- Campaigns with spot fees, featured spot fees, capacity limits, interactive-map control, disclosure text, contact email, terms URL, submission limits, and private-data retention settings.
- Public location labels, optional validated coordinates, an interactive OpenLayers/OpenStreetMap view, and accessible sponsor-location cards with direct map links.
- Public sponsor spot form with nonce protection, honeypot spam reduction, strict contact/location/link/date validation, explicit consent, and configurable HMAC rate limiting.
- Public visitor check-in/submission forms for approved open spots.
- Admin moderation for sponsor spot approval, rejection, in-review state, completion, archive, and check-in approval/rejection.
- Locally generated QR target URLs and SVG QR codes for every approved spot using `?mds_lmm_spot=<id>` redirects.
- Individual QR downloads and paginated printable QR sheets with large, four-per-page, and eight-per-page layouts.
- Optional MDS 3.0 payment-provider checkout for spot/featured fees without direct WooCommerce calls.
- Independent moderation and payment gates keep provider-mode sponsor spots private until both requirements are satisfied.
- Currency defaults from the active MDS 3.0/WooCommerce currency instead of hardcoded USD.
- Direct email notifications for new submissions and moderation changes.
- Page-wise privacy export and erasure plus scheduled retention anonymization for stale private sponsor and check-in data.
- Paginated REST read endpoints for active campaigns/spots plus admin-scoped write/moderation endpoints registered in the MDS 3.0 API manifest.
- MDS2-compatible submenu fallback for older installs.

## Shortcode

```text
[mds_local_money_map id="1"]
```

## REST API

- `GET /wp-json/million-dollar-script/v1/local-money-map/boards`
- `GET /wp-json/million-dollar-script/v1/local-money-map/boards/{id}/spots`
- `POST /wp-json/million-dollar-script/v1/local-money-map/spots` (admin-scoped)
- `POST /wp-json/million-dollar-script/v1/local-money-map/spots/{id}/moderate` (admin-scoped)
- `POST /wp-json/million-dollar-script/v1/local-money-map/claims` (admin-scoped check-in endpoint)
- `POST /wp-json/million-dollar-script/v1/local-money-map/claims/{id}/moderate` (admin-scoped check-in endpoint)

## Shared-Host Notes

Local Money Map creates stable QR target links, SVG QR downloads, and bounded printable QR batches locally with a packaged standards-based PHP library. It does not require GD, Imagick, external APIs, queues, or shell tools. The optional interactive map reuses the OpenLayers library bundled with Million Dollar Script and loads OpenStreetMap tiles in the visitor's browser; disable it when external tile requests are not appropriate. Very large print jobs, geocoding, fraud scoring, route optimization, and high-volume campaign analytics remain strong candidates for an optional hosted service because shared hosts may have limited CPU headroom.

## Changelog

### 0.2.1

- Replaced the hand-rolled QR encoder with BaconQrCode and added an iconv polyfill for shared-host portability.
- Added third-party license notices and decoder-backed QR validation.

### 0.2.0

- Added actual mapped locations, hardened submissions and payments, pagination, notifications, privacy retention, and production UX/security coverage.

### 0.1.1

- Added local SVG QR code generation for approved sponsor spots.
- Added admin QR previews, individual QR downloads, and printable QR sheets.
- Updated public sponsor cards to show QR target links and QR downloads.

### 0.1.0

- Initial local-first Local Money Map implementation.
