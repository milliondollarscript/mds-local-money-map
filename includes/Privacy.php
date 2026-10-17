<?php
/**
 * WordPress privacy export and erasure integration.
 *
 * @package MillionDollarScript\Extensions\LocalMoneyMap
 */

namespace MillionDollarScript\Extensions\LocalMoneyMap;

if (!defined('ABSPATH')) {
    exit;
}

final class Privacy {

    private const PAGE_SIZE = 50;

    public static function exporter($email_address, $page = 1) {
        $page = max(1, absint($page));
        $spots = Repository::spots_by_email_page($email_address, $page, self::PAGE_SIZE);
        $claims = Repository::claims_by_email_page($email_address, $page, self::PAGE_SIZE);
        $items = [];

        foreach ($spots['items'] as $spot) {
            $items[] = [
                'group_id' => 'mds-local-money-map',
                'group_label' => __('Local Money Map submissions', 'mds-local-money-map'),
                'item_id' => 'local-money-map-spot-' . absint($spot['id'] ?? 0),
                'data' => [
                    ['name' => __('Sponsor spot', 'mds-local-money-map'), 'value' => (string) ($spot['title'] ?? '')],
                    ['name' => __('Description', 'mds-local-money-map'), 'value' => (string) ($spot['description'] ?? '')],
                    ['name' => __('Contact name', 'mds-local-money-map'), 'value' => (string) ($spot['requester_name'] ?? '')],
                    ['name' => __('Email', 'mds-local-money-map'), 'value' => (string) ($spot['requester_email'] ?? '')],
                    ['name' => __('Sponsor URL', 'mds-local-money-map'), 'value' => (string) ($spot['external_url'] ?? '')],
                    ['name' => __('Moderation status', 'mds-local-money-map'), 'value' => (string) ($spot['status'] ?? '')],
                    ['name' => __('Payment status', 'mds-local-money-map'), 'value' => (string) ($spot['payment_status'] ?? '')],
                    ['name' => __('Created', 'mds-local-money-map'), 'value' => (string) ($spot['created_at'] ?? '')],
                ],
            ];
        }

        foreach ($claims['items'] as $claim) {
            $items[] = [
                'group_id' => 'mds-local-money-map',
                'group_label' => __('Local Money Map submissions', 'mds-local-money-map'),
                'item_id' => 'local-money-map-checkin-' . absint($claim['id'] ?? 0),
                'data' => [
                    ['name' => __('Check-in name', 'mds-local-money-map'), 'value' => (string) ($claim['display_name'] ?? '')],
                    ['name' => __('Email', 'mds-local-money-map'), 'value' => (string) ($claim['email'] ?? '')],
                    ['name' => __('Check-in note', 'mds-local-money-map'), 'value' => (string) ($claim['proposal'] ?? '')],
                    ['name' => __('Proof URL', 'mds-local-money-map'), 'value' => (string) ($claim['work_url'] ?? '')],
                    ['name' => __('Moderation status', 'mds-local-money-map'), 'value' => (string) ($claim['status'] ?? '')],
                    ['name' => __('Created', 'mds-local-money-map'), 'value' => (string) ($claim['created_at'] ?? '')],
                ],
            ];
        }

        $done = $page >= absint($spots['pages'] ?? 1) && $page >= absint($claims['pages'] ?? 1);

        return ['data' => $items, 'done' => $done];
    }

    public static function eraser($email_address, $page = 1) {
        unset($page);
        $spots = Repository::spots_by_email_page($email_address, 1, self::PAGE_SIZE);
        $claims = Repository::claims_by_email_page($email_address, 1, self::PAGE_SIZE);
        $removed = 0;

        foreach ($spots['items'] as $spot) {
            if (Repository::anonymize_spot(absint($spot['id'] ?? 0))) {
                $removed++;
            }
        }
        foreach ($claims['items'] as $claim) {
            if (Repository::anonymize_claim(absint($claim['id'] ?? 0))) {
                $removed++;
            }
        }

        $spots_remaining = Repository::spots_by_email_page($email_address, 1, 1);
        $claims_remaining = Repository::claims_by_email_page($email_address, 1, 1);

        return [
            'items_removed' => $removed > 0,
            'items_retained' => $removed > 0,
            'messages' => $removed > 0 ? [__('Personal contact and proof fields were erased. Non-identifying public, moderation, payment, and accounting records were retained where required.', 'mds-local-money-map')] : [],
            'done' => empty($spots_remaining['items']) && empty($claims_remaining['items']),
        ];
    }
}
