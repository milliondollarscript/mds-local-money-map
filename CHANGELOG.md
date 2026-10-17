# Changelog

## Unreleased

- Added opt-in uninstall cleanup through the core extension policy for extension-owned campaigns, spots, check-ins, settings, and transients.

## 0.2.1 - 2026-07-16

- Replaced the hand-rolled QR encoder with BaconQrCode while preserving the existing QR matrix and SVG interfaces.
- Added the Symfony iconv polyfill so QR generation remains reliable on shared hosts without native `iconv`.
- Added third-party license notices and verified generated SVG QR codes with an independent decoder.
- Schedule retention during `init` so first activation remains warning-free alongside plugins that add translated cron intervals.

## 0.2.0 - 2026-07-13

- Open existing campaigns on the Spots queue while keeping new campaign creation on Campaign settings.
- Split campaign configuration from sponsor spot and check-in operations with directly addressable Campaign settings and Spots tabs.
- Added public location labels, validated optional coordinates, an interactive OpenLayers/OpenStreetMap view, direct map links, and a campaign map privacy toggle.
- Added shared browser/REST validation for required contact and location data, HTTP(S)-only links, real dates, coordinates, and explicit submission consent.
- Added database locking around campaign capacity, accurate counts, and paginated campaign, sponsor spot, check-in, public, REST, and QR print collections.
- Added direct email notifications for submissions and moderation changes, plus explicit payment-checkout failure handling.
- Added page-wise WordPress privacy export/erasure and scheduled retention anonymization for stale private sponsor and check-in data.
- Restricted public records, redirects, and QR delivery to active campaigns with completed moderation and payment gates.
- Added explicit accessible success/error colors for light, dark, and system admin themes and responsive public pagination.
- Expanded the WordPress fixture past 100 records and verified generated QR SVGs with a real decoder.

## 0.1.2 - 2026-07-13

- Published each REST route through the flat Million Dollar Script endpoint manifest.
- Matched discovery security levels to the public-read and administrator-only runtime callbacks.
- Removed private contact, retention, payment, and charged-amount fields from public REST responses.
- Aligned declared LLM-safe actions with the public board and spot discovery routes.

## 0.1.1

- Added local QR image generation and printable QR sheets.

## 0.1.0

- Added the initial Local Money Map extension.
