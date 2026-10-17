<?php
/**
 * Local Money Map local repository.
 *
 * @package MillionDollarScript\Extensions\LocalMoneyMap
 */

namespace MillionDollarScript\Extensions\LocalMoneyMap;

if (!defined('ABSPATH')) {
    exit;
}

final class Repository {

    public const BOARD_POST_TYPE = 'mds_lmm_campaign';
    public const BOUNTY_POST_TYPE = 'mds_lmm_spot';
    public const CLAIM_POST_TYPE = 'mds_lmm_checkin';

    public static function maybe_seed() {
        if (self::boards(1)) {
            return;
        }

        self::save_board([
            'title' => __('Launch Local Money Map', 'mds-local-money-map'),
            'slug' => 'launch-local-money-map',
            'description' => __('Build a local trail of sponsor spots, offers, event stops, and QR target links for visitors to explore.', 'mds-local-money-map'),
            'status' => 'active',
            'currency' => self::default_currency(),
            'posting_fee' => 25,
            'featured_fee' => 50,
            'localmap_limit' => 50,
            'payment_mode' => 'manual',
            'claims_enabled' => '1',
            'map_enabled' => '1',
            'disclosure' => __('Sponsor spots and offers may be paid placements. Offer redemption, availability, and fulfillment are handled by each participating business.', 'mds-local-money-map'),
            'terms_url' => '',
            'contact_email' => get_option('admin_email'),
            'retention_days' => 365,
            'submission_limit_per_hour' => 10,
        ]);
    }

    public static function save_board(array $data, $board_id = 0) {
        $board_id = absint($board_id);
        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        if ('' === $title) {
            return 0;
        }

        $postarr = [
            'post_type' => self::BOARD_POST_TYPE,
            'post_title' => $title,
            'post_status' => 'publish',
        ];

        $result = $board_id
            ? wp_update_post(array_merge($postarr, ['ID' => $board_id]), true)
            : wp_insert_post($postarr, true);

        if (is_wp_error($result) || !$result) {
            return 0;
        }

        $board_id = absint($result);
        $status = sanitize_key((string) ($data['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
            $status = 'draft';
        }

        $payment_mode = sanitize_key((string) ($data['payment_mode'] ?? 'manual'));
        if (!in_array($payment_mode, ['manual', 'provider'], true)) {
            $payment_mode = 'manual';
        }

        $meta = [
            '_mds_lmm_slug' => sanitize_title((string) ($data['slug'] ?? $title)) ?: 'localmap-board-' . $board_id,
            '_mds_lmm_description' => self::bounded_textarea($data['description'] ?? '', 5000),
            '_mds_lmm_status' => $status,
            '_mds_lmm_currency' => self::effective_currency($data['currency'] ?? self::default_currency()),
            '_mds_lmm_posting_fee' => self::money($data['posting_fee'] ?? 0),
            '_mds_lmm_featured_fee' => self::money($data['featured_fee'] ?? 0),
            '_mds_lmm_spot_limit' => min(10000, max(1, absint($data['localmap_limit'] ?? 50))),
            '_mds_lmm_payment_mode' => $payment_mode,
            '_mds_lmm_checkins_enabled' => !empty($data['claims_enabled']) ? '1' : '0',
            '_mds_lmm_map_enabled' => !empty($data['map_enabled']) ? '1' : '0',
            '_mds_lmm_disclosure' => self::bounded_textarea($data['disclosure'] ?? '', 5000),
            '_mds_lmm_terms_url' => self::http_url($data['terms_url'] ?? ''),
            '_mds_lmm_contact_email' => self::valid_contact_email($data['contact_email'] ?? ''),
            '_mds_lmm_retention_days' => min(3650, max(1, absint($data['retention_days'] ?? 365))),
            '_mds_lmm_submission_limit_per_hour' => min(500, max(1, absint($data['submission_limit_per_hour'] ?? 10))),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($board_id, $key, $value);
        }

        return $board_id;
    }

    public static function board($board_id) {
        $post = get_post(absint($board_id));
        if (!$post || self::BOARD_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::board_payload($post);
    }

    public static function board_by_slug($slug) {
        $slug = sanitize_title((string) $slug);
        if (!$slug) {
            return null;
        }

        $posts = get_posts([
            'post_type' => self::BOARD_POST_TYPE,
            'post_status' => 'publish',
            'meta_key' => '_mds_lmm_slug',
            'meta_value' => $slug,
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);

        return $posts ? self::board_payload($posts[0]) : null;
    }

    public static function boards($limit = 50) {
        $posts = get_posts([
            'post_type' => self::BOARD_POST_TYPE,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => min(100, max(1, absint($limit))),
            'no_found_rows' => true,
        ]);

        return array_values(array_filter(array_map([__CLASS__, 'board_payload'], $posts)));
    }

    public static function boards_page($page = 1, $per_page = 20, $active_only = false) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $args = [
            'post_type' => self::BOARD_POST_TYPE,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ];
        if ($active_only) {
            $args['meta_query'] = [['key' => '_mds_lmm_status', 'value' => 'active', 'compare' => '=']];
        }

        return self::collection(new \WP_Query($args), [__CLASS__, 'board_payload'], $page, $per_page);
    }

    public static function create_localmap($board_id, array $data) {
        $board_id = absint($board_id);
        $validation = self::validate_localmap_submission($board_id, $data);
        if (is_wp_error($validation) || !self::acquire_board_lock($board_id)) {
            return 0;
        }

        $board = self::board($board_id);
        if (!$board || !self::has_open_localmap_slots($board_id)) {
            self::release_board_lock($board_id);
            return 0;
        }

        $title = self::bounded_text($data['title'] ?? '', 120);
        $description = self::bounded_textarea($data['description'] ?? '', 10000);

        $post_id = wp_insert_post([
            'post_type' => self::BOUNTY_POST_TYPE,
            'post_title' => $title,
            'post_content' => $description,
            'post_status' => 'pending',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            self::release_board_lock($board_id);
            return 0;
        }

        $post_id = absint($post_id);
        $featured = !empty($data['featured']);
        $amount = self::money($board['posting_fee'] ?? 0) + ($featured ? self::money($board['featured_fee'] ?? 0) : 0);

        $meta = [
            '_mds_lmm_campaign_id' => absint($board_id),
            '_mds_lmm_requester_name' => self::bounded_text($data['requester_name'] ?? '', 80),
            '_mds_lmm_requester_email' => sanitize_email((string) ($data['requester_email'] ?? '')),
            '_mds_lmm_category' => self::bounded_text($data['category'] ?? '', 80),
            '_mds_lmm_location_label' => self::bounded_text($data['location_label'] ?? '', 160),
            '_mds_lmm_latitude' => self::coordinate($data['latitude'] ?? '', -90, 90),
            '_mds_lmm_longitude' => self::coordinate($data['longitude'] ?? '', -180, 180),
            '_mds_lmm_reward_label' => self::bounded_text($data['reward_label'] ?? '', 120),
            '_mds_lmm_external_url' => self::http_url($data['external_url'] ?? ''),
            '_mds_lmm_due_at' => self::normalize_date($data['due_at'] ?? ''),
            '_mds_lmm_featured' => $featured ? '1' : '0',
            '_mds_lmm_status' => 'pending_review',
            '_mds_lmm_payment_status' => $amount > 0 ? 'requested' : 'not_required',
            '_mds_lmm_amount' => $amount,
            '_mds_lmm_currency' => self::effective_currency($board['currency'] ?? self::default_currency()),
            '_mds_lmm_completed_at' => '',
            '_mds_lmm_terms_accepted' => '1',
            '_mds_lmm_private_data_updated_at' => time(),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        self::release_board_lock($board_id);

        return $post_id;
    }

    /**
     * @return true|\WP_Error
     */
    public static function validate_localmap_submission($board_id, array $data) {
        $board = self::board(absint($board_id));
        $title = self::bounded_text($data['title'] ?? '', 120);
        $description = self::bounded_textarea($data['description'] ?? '', 10000);
        $email = sanitize_email((string) ($data['requester_email'] ?? ''));
        $location = self::bounded_text($data['location_label'] ?? '', 160);
        $external_url = trim((string) ($data['external_url'] ?? ''));
        $due_at = trim((string) ($data['due_at'] ?? ''));
        $latitude = trim((string) ($data['latitude'] ?? ''));
        $longitude = trim((string) ($data['longitude'] ?? ''));

        if (!$board || 'active' !== (string) ($board['status'] ?? '')) {
            return new \WP_Error('mds_lmm_closed', __('This campaign is not accepting sponsor spots.', 'mds-local-money-map'));
        }
        if (!self::has_open_localmap_slots(absint($board_id))) {
            return new \WP_Error('mds_lmm_full', __('This campaign has reached its sponsor spot capacity.', 'mds-local-money-map'));
        }
        if ('' === $title || '' === $description || '' === $location || !is_email($email) || '' === self::http_url($external_url)) {
            return new \WP_Error('mds_lmm_invalid_submission', __('Business or stop name, location, description, valid email, and an HTTP or HTTPS sponsor URL are required.', 'mds-local-money-map'));
        }
        if (empty($data['terms_accepted'])) {
            return new \WP_Error('mds_lmm_terms_required', __('Accept the campaign terms and sponsored-placement disclosure before submitting.', 'mds-local-money-map'));
        }
        if ('' !== $due_at && !self::valid_date($due_at)) {
            return new \WP_Error('mds_lmm_invalid_date', __('Use a real calendar date for the event date.', 'mds-local-money-map'));
        }
        if (('' === $latitude) !== ('' === $longitude)
            || ('' !== $latitude && (null === self::coordinate($latitude, -90, 90) || null === self::coordinate($longitude, -180, 180)))) {
            return new \WP_Error('mds_lmm_invalid_coordinates', __('Enter both latitude and longitude within their valid ranges, or leave both coordinate fields blank.', 'mds-local-money-map'));
        }

        return true;
    }

    public static function localmap($localmap_id) {
        $post = get_post(absint($localmap_id));
        if (!$post || self::BOUNTY_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::localmap_payload($post);
    }

    public static function spots($board_id, array $args = []) {
        $collection = self::spots_page(
            $board_id,
            1,
            min(100, max(1, absint($args['limit'] ?? 50))),
            !empty($args['public_only'])
        );

        return $collection['items'];
    }

    public static function spots_page($board_id, $page = 1, $per_page = 20, $public_only = false) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $board = self::board($board_id);
        if (!$board || ($public_only && 'active' !== (string) ($board['status'] ?? ''))) {
            return self::empty_collection($page, $per_page);
        }

        $meta_query = [
            'board_clause' => ['key' => '_mds_lmm_campaign_id', 'value' => absint($board_id), 'compare' => '='],
            'featured_clause' => ['key' => '_mds_lmm_featured', 'compare' => 'EXISTS'],
        ];
        if ($public_only) {
            $meta_query['status_clause'] = [
                'key' => '_mds_lmm_status',
                'value' => ['open', 'in_review', 'completed'],
                'compare' => 'IN',
            ];
        }

        $query = new \WP_Query([
            'post_type' => self::BOUNTY_POST_TYPE,
            'post_status' => $public_only ? ['publish'] : ['publish', 'pending', 'draft'],
            'meta_query' => $meta_query,
            'orderby' => ['featured_clause' => 'DESC', 'date' => 'DESC'],
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ]);

        return self::collection($query, [__CLASS__, 'localmap_payload'], $page, $per_page);
    }

    public static function create_claim($localmap_id, array $data) {
        $localmap_id = absint($localmap_id);
        $validation = self::validate_claim_submission($localmap_id, $data);
        if (is_wp_error($validation)) {
            return 0;
        }

        $localmap = self::localmap($localmap_id);

        $name = self::bounded_text($data['display_name'] ?? '', 80);
        $proposal = self::bounded_textarea($data['proposal'] ?? '', 5000);

        $post_id = wp_insert_post([
            'post_type' => self::CLAIM_POST_TYPE,
            'post_title' => sprintf(__('Check-in for %s', 'mds-local-money-map'), $localmap['title']),
            'post_content' => $proposal,
            'post_status' => 'pending',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        $post_id = absint($post_id);
        $meta = [
            '_mds_lmm_spot_id' => absint($localmap_id),
            '_mds_lmm_campaign_id' => absint($localmap['board_id'] ?? 0),
            '_mds_lmm_display_name' => $name,
            '_mds_lmm_email' => sanitize_email((string) ($data['email'] ?? '')),
            '_mds_lmm_work_url' => self::http_url($data['work_url'] ?? ''),
            '_mds_lmm_status' => 'pending_review',
            '_mds_lmm_terms_accepted' => '1',
            '_mds_lmm_private_data_updated_at' => time(),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        return $post_id;
    }

    /**
     * @return true|\WP_Error
     */
    public static function validate_claim_submission($localmap_id, array $data) {
        $localmap = self::localmap(absint($localmap_id));
        $board = $localmap ? self::board(absint($localmap['board_id'] ?? 0)) : null;
        $name = self::bounded_text($data['display_name'] ?? '', 80);
        $proposal = self::bounded_textarea($data['proposal'] ?? '', 5000);
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $work_url = trim((string) ($data['work_url'] ?? ''));

        if (!$localmap || !$board || empty($board['claims_enabled']) || !self::is_spot_public(absint($localmap_id)) || 'completed' === (string) ($localmap['status'] ?? '')) {
            return new \WP_Error('mds_lmm_claims_closed', __('Check-ins are not available for this sponsor spot.', 'mds-local-money-map'));
        }
        if ('' === $name || '' === $proposal || !is_email($email)) {
            return new \WP_Error('mds_lmm_invalid_claim', __('Name, valid email, and a check-in note are required.', 'mds-local-money-map'));
        }
        if ('' !== $work_url && '' === self::http_url($work_url)) {
            return new \WP_Error('mds_lmm_invalid_work_url', __('Use a valid HTTP or HTTPS proof URL.', 'mds-local-money-map'));
        }
        if (empty($data['terms_accepted'])) {
            return new \WP_Error('mds_lmm_claim_terms_required', __('Confirm that the check-in information may be reviewed and retained under the campaign terms.', 'mds-local-money-map'));
        }

        return true;
    }

    public static function claim($claim_id) {
        $post = get_post(absint($claim_id));
        if (!$post || self::CLAIM_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::claim_payload($post);
    }

    public static function claims($localmap_id = 0, array $args = []) {
        $collection = self::claims_page(
            $localmap_id,
            1,
            min(100, max(1, absint($args['limit'] ?? 50))),
            !empty($args['public_only'])
        );

        return $collection['items'];
    }

    public static function claims_page($localmap_id = 0, $page = 1, $per_page = 20, $public_only = false) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $meta_query = [];
        if ($localmap_id) {
            $meta_query[] = ['key' => '_mds_lmm_spot_id', 'value' => absint($localmap_id), 'compare' => '='];
        }
        $query = new \WP_Query([
            'post_type' => self::CLAIM_POST_TYPE,
            'post_status' => $public_only ? ['publish'] : ['publish', 'pending', 'draft'],
            'meta_query' => $meta_query,
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ]);

        return self::collection($query, [__CLASS__, 'claim_payload'], $page, $per_page);
    }

    public static function claim_count($localmap_id) {
        $query = new \WP_Query([
            'post_type' => self::CLAIM_POST_TYPE,
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_key' => '_mds_lmm_spot_id',
            'meta_value' => absint($localmap_id),
            'posts_per_page' => 1,
            'no_found_rows' => false,
        ]);

        return absint($query->found_posts);
    }

    public static function update_localmap_status($localmap_id, $status) {
        $localmap = self::localmap($localmap_id);
        if (!$localmap) {
            return false;
        }

        $status = sanitize_key((string) $status);
        if ('approve' === $status || 'reopen' === $status) {
            update_post_meta($localmap_id, '_mds_lmm_status', 'open');
            self::confirm_manual_payment($localmap);
            self::reconcile_publication($localmap_id);
            return true;
        }

        if ('review' === $status) {
            update_post_meta($localmap_id, '_mds_lmm_status', 'in_review');
            self::confirm_manual_payment($localmap);
            self::reconcile_publication($localmap_id);
            return true;
        }

        if ('complete' === $status) {
            update_post_meta($localmap_id, '_mds_lmm_status', 'completed');
            update_post_meta($localmap_id, '_mds_lmm_completed_at', current_time('mysql', true));
            self::confirm_manual_payment($localmap);
            self::reconcile_publication($localmap_id);
            return true;
        }

        if ('reject' === $status) {
            wp_update_post(['ID' => absint($localmap_id), 'post_status' => 'draft']);
            update_post_meta($localmap_id, '_mds_lmm_status', 'rejected');
            return true;
        }

        if ('archive' === $status) {
            wp_update_post(['ID' => absint($localmap_id), 'post_status' => 'draft']);
            update_post_meta($localmap_id, '_mds_lmm_status', 'archived');
            return true;
        }

        return false;
    }

    public static function update_claim_status($claim_id, $status) {
        $claim = self::claim($claim_id);
        if (!$claim) {
            return false;
        }

        $status = sanitize_key((string) $status);
        if ('approve' === $status) {
            wp_update_post(['ID' => absint($claim_id), 'post_status' => 'publish']);
            update_post_meta($claim_id, '_mds_lmm_status', 'approved');
            self::update_localmap_status(absint($claim['localmap_id']), 'review');
            return true;
        }

        if ('reject' === $status) {
            wp_update_post(['ID' => absint($claim_id), 'post_status' => 'draft']);
            update_post_meta($claim_id, '_mds_lmm_status', 'rejected');
            return true;
        }

        return false;
    }

    public static function update_payment_status($localmap_id, $status) {
        $localmap = self::localmap($localmap_id);
        if (!$localmap) {
            return false;
        }

        $status = sanitize_key((string) $status);
        if (!in_array($status, ['requested', 'pending', 'processing', 'paid', 'completed', 'failed', 'cancelled', 'expired', 'refunded', 'not_required'], true)) {
            return false;
        }

        update_post_meta(absint($localmap_id), '_mds_lmm_payment_status', $status);
        self::reconcile_publication($localmap_id);
        return true;
    }

    private static function confirm_manual_payment(array $localmap) {
        $board = self::board(absint($localmap['board_id'] ?? 0));
        if (!$board || 'manual' !== (string) ($board['payment_mode'] ?? 'manual')) {
            return;
        }

        $payment_status = sanitize_key((string) ($localmap['payment_status'] ?? ''));
        if ((float) ($localmap['amount'] ?? 0) > 0 && !in_array($payment_status, ['paid', 'completed', 'processing'], true)) {
            update_post_meta(absint($localmap['id'] ?? 0), '_mds_lmm_payment_status', 'paid');
        }
    }

    private static function reconcile_publication($localmap_id) {
        $localmap = self::localmap($localmap_id);
        if (!$localmap || !in_array((string) ($localmap['status'] ?? ''), ['open', 'in_review', 'completed'], true)) {
            return;
        }

        wp_update_post([
            'ID' => absint($localmap_id),
            'post_status' => self::payment_allows_publication($localmap) ? 'publish' : 'pending',
        ]);
    }

    private static function payment_allows_publication(array $localmap) {
        $subscription_end_value = trim((string) ($localmap['subscription_expires_at'] ?? ''));
        $subscription_end = $subscription_end_value ? strtotime($subscription_end_value . ' UTC') : false;
        if (false !== $subscription_end && $subscription_end <= time()) {
            return false;
        }

        if ((float) ($localmap['amount'] ?? 0) <= 0) {
            return true;
        }

        $status = sanitize_key((string) ($localmap['payment_status'] ?? ''));
        if (in_array($status, ['not_required', 'paid', 'completed', 'processing'], true)) {
            return true;
        }

        $board = self::board(absint($localmap['board_id'] ?? 0));

        return $board && 'manual' === (string) ($board['payment_mode'] ?? 'manual');
    }

    public static function is_spot_public($localmap_id) {
        $localmap = self::localmap($localmap_id);
        $board = $localmap ? self::board(absint($localmap['board_id'] ?? 0)) : null;

        return $localmap
            && $board
            && 'active' === (string) ($board['status'] ?? '')
            && in_array((string) ($localmap['status'] ?? ''), ['open', 'in_review', 'completed'], true)
            && 'publish' === get_post_status(absint($localmap_id))
            && self::payment_allows_publication($localmap);
    }

    public static function has_open_localmap_slots($board_id) {
        $board = self::board($board_id);
        if (!$board) {
            return false;
        }

        return self::used_localmap_slots($board_id) < absint($board['localmap_limit'] ?? 0);
    }

    public static function used_localmap_slots($board_id) {
        $query = new \WP_Query([
            'post_type' => self::BOUNTY_POST_TYPE,
            'post_status' => ['publish', 'pending'],
            'meta_key' => '_mds_lmm_campaign_id',
            'meta_value' => absint($board_id),
            'posts_per_page' => 1,
            'no_found_rows' => false,
        ]);

        return absint($query->found_posts);
    }

    public static function board_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'title' => get_the_title($post),
            'slug' => (string) get_post_meta($post->ID, '_mds_lmm_slug', true),
            'description' => (string) get_post_meta($post->ID, '_mds_lmm_description', true),
            'status' => (string) get_post_meta($post->ID, '_mds_lmm_status', true),
            'currency' => (string) get_post_meta($post->ID, '_mds_lmm_currency', true),
            'posting_fee' => (float) get_post_meta($post->ID, '_mds_lmm_posting_fee', true),
            'featured_fee' => (float) get_post_meta($post->ID, '_mds_lmm_featured_fee', true),
            'localmap_limit' => absint(get_post_meta($post->ID, '_mds_lmm_spot_limit', true)),
            'used_spots' => self::used_localmap_slots($post->ID),
            'payment_mode' => (string) get_post_meta($post->ID, '_mds_lmm_payment_mode', true),
            'claims_enabled' => '1' === (string) get_post_meta($post->ID, '_mds_lmm_checkins_enabled', true),
            'map_enabled' => '0' !== (string) get_post_meta($post->ID, '_mds_lmm_map_enabled', true),
            'disclosure' => (string) get_post_meta($post->ID, '_mds_lmm_disclosure', true),
            'terms_url' => (string) get_post_meta($post->ID, '_mds_lmm_terms_url', true),
            'contact_email' => (string) get_post_meta($post->ID, '_mds_lmm_contact_email', true),
            'retention_days' => absint(get_post_meta($post->ID, '_mds_lmm_retention_days', true)) ?: 365,
            'submission_limit_per_hour' => absint(get_post_meta($post->ID, '_mds_lmm_submission_limit_per_hour', true)) ?: 10,
        ];
    }

    public static function public_board_payload($board) {
        $board = is_array($board) ? $board : self::board_payload($board);
        if (!$board) {
            return null;
        }

        return array_intersect_key($board, array_flip([
            'id',
            'title',
            'slug',
            'description',
            'status',
            'currency',
            'posting_fee',
            'featured_fee',
            'localmap_limit',
            'used_spots',
            'claims_enabled',
            'map_enabled',
            'disclosure',
            'terms_url',
        ]));
    }

    public static function localmap_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'board_id' => absint(get_post_meta($post->ID, '_mds_lmm_campaign_id', true)),
            'title' => get_the_title($post),
            'description' => (string) $post->post_content,
            'status' => (string) get_post_meta($post->ID, '_mds_lmm_status', true),
            'requester_name' => (string) get_post_meta($post->ID, '_mds_lmm_requester_name', true),
            'requester_email' => (string) get_post_meta($post->ID, '_mds_lmm_requester_email', true),
            'category' => (string) get_post_meta($post->ID, '_mds_lmm_category', true),
            'location_label' => (string) get_post_meta($post->ID, '_mds_lmm_location_label', true),
            'latitude' => self::stored_coordinate(get_post_meta($post->ID, '_mds_lmm_latitude', true)),
            'longitude' => self::stored_coordinate(get_post_meta($post->ID, '_mds_lmm_longitude', true)),
            'reward_label' => (string) get_post_meta($post->ID, '_mds_lmm_reward_label', true),
            'external_url' => (string) get_post_meta($post->ID, '_mds_lmm_external_url', true),
            'qr_url' => add_query_arg('mds_lmm_spot', absint($post->ID), home_url('/')),
            'due_at' => (string) get_post_meta($post->ID, '_mds_lmm_due_at', true),
            'featured' => '1' === (string) get_post_meta($post->ID, '_mds_lmm_featured', true),
            'payment_status' => (string) get_post_meta($post->ID, '_mds_lmm_payment_status', true),
            'subscription_expires_at' => (string) get_post_meta($post->ID, '_mds_lmm_subscription_expires_at', true),
            'amount' => (float) get_post_meta($post->ID, '_mds_lmm_amount', true),
            'currency' => (string) get_post_meta($post->ID, '_mds_lmm_currency', true),
            'completed_at' => (string) get_post_meta($post->ID, '_mds_lmm_completed_at', true),
            'claim_count' => self::claim_count($post->ID),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    public static function public_spot_payload($spot) {
        $spot = is_array($spot) ? $spot : self::localmap_payload($spot);
        if (!$spot) {
            return null;
        }

        return array_intersect_key($spot, array_flip([
            'id',
            'board_id',
            'title',
            'description',
            'status',
            'category',
            'location_label',
            'latitude',
            'longitude',
            'reward_label',
            'external_url',
            'qr_url',
            'due_at',
            'featured',
            'completed_at',
            'claim_count',
            'created_at',
        ]));
    }

    public static function claim_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'localmap_id' => absint(get_post_meta($post->ID, '_mds_lmm_spot_id', true)),
            'board_id' => absint(get_post_meta($post->ID, '_mds_lmm_campaign_id', true)),
            'display_name' => (string) get_post_meta($post->ID, '_mds_lmm_display_name', true),
            'email' => (string) get_post_meta($post->ID, '_mds_lmm_email', true),
            'proposal' => (string) $post->post_content,
            'work_url' => (string) get_post_meta($post->ID, '_mds_lmm_work_url', true),
            'status' => (string) get_post_meta($post->ID, '_mds_lmm_status', true),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    public static function spots_by_email_page($email, $page = 1, $per_page = 50) {
        $email = sanitize_email((string) $email);
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        if (!is_email($email)) {
            return self::empty_collection($page, $per_page);
        }

        $query = new \WP_Query([
            'post_type' => self::BOUNTY_POST_TYPE,
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_key' => '_mds_lmm_requester_email',
            'meta_value' => $email,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ]);

        return self::collection($query, [__CLASS__, 'localmap_payload'], $page, $per_page);
    }

    public static function claims_by_email_page($email, $page = 1, $per_page = 50) {
        $email = sanitize_email((string) $email);
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        if (!is_email($email)) {
            return self::empty_collection($page, $per_page);
        }

        $query = new \WP_Query([
            'post_type' => self::CLAIM_POST_TYPE,
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_key' => '_mds_lmm_email',
            'meta_value' => $email,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ]);

        return self::collection($query, [__CLASS__, 'claim_payload'], $page, $per_page);
    }

    public static function anonymize_spot($localmap_id) {
        $localmap_id = absint($localmap_id);
        if (!self::localmap($localmap_id)) {
            return false;
        }

        update_post_meta($localmap_id, '_mds_lmm_requester_email', '');
        update_post_meta($localmap_id, '_mds_lmm_requester_name', '');
        update_post_meta($localmap_id, '_mds_lmm_private_data_updated_at', time());

        return true;
    }

    public static function anonymize_claim($claim_id) {
        $claim_id = absint($claim_id);
        if (!self::claim($claim_id)) {
            return false;
        }

        update_post_meta($claim_id, '_mds_lmm_email', '');
        update_post_meta($claim_id, '_mds_lmm_display_name', '');
        update_post_meta($claim_id, '_mds_lmm_work_url', '');
        update_post_meta($claim_id, '_mds_lmm_private_data_updated_at', time());

        return true;
    }

    public static function cleanup_board_private_data($board_id, $retention_days, $limit = 100) {
        $board_id = absint($board_id);
        $cutoff = time() - (max(1, absint($retention_days)) * DAY_IN_SECONDS);
        $limit = min(250, max(1, absint($limit)));
        $changed = 0;

        foreach (self::retention_spots($board_id, $cutoff, $limit) as $post) {
            $spot = self::localmap_payload($post);
            $terminal = in_array((string) ($spot['status'] ?? ''), ['completed', 'rejected', 'archived'], true)
                || ('publish' !== $post->post_status && in_array((string) ($spot['payment_status'] ?? ''), ['failed', 'cancelled', 'expired', 'refunded'], true));
            if ($terminal && self::anonymize_spot($post->ID)) {
                $changed++;
            }
        }

        $remaining = max(0, $limit - $changed);
        if ($remaining) {
            foreach (self::retention_claims($board_id, $cutoff, $remaining) as $post) {
                if (self::anonymize_claim($post->ID)) {
                    $changed++;
                }
            }
        }

        return $changed;
    }

    private static function normalize_date($value) {
        $value = sanitize_text_field((string) $value);
        if ($value && self::valid_date($value)) {
            return $value;
        }

        return '';
    }

    private static function money($value) {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'amount')) {
            return \MillionDollarScript\Commerce\Currency::amount($value);
        }

        return round(max(0, (float) $value), 2);
    }

    public static function default_currency() {
        $settings = get_option('mds3_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $fallback = '';

        if (function_exists('get_woocommerce_currency')) {
            $fallback = get_woocommerce_currency();
        }
        if (!$fallback && !empty($settings['currency'])) {
            $fallback = (string) $settings['currency'];
        }
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency')) {
            return \MillionDollarScript\Commerce\Currency::current_code($settings);
        }

        return self::currency($fallback ?: 'USD');
    }

    public static function effective_currency($value = '') {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'effective_code')) {
            return \MillionDollarScript\Commerce\Currency::effective_code($value);
        }

        if (self::currency_locked()) {
            return self::default_currency();
        }

        return self::currency($value ?: self::default_currency());
    }

    public static function currency_locked() {
        $settings = get_option('mds3_settings', []);
        $settings = is_array($settings) ? $settings : [];

        return class_exists('\\MillionDollarScript\\Commerce\\Currency') && \MillionDollarScript\Commerce\Currency::provider_locks_currency($settings);
    }

    private static function currency($value) {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'normalize_code')) {
            return \MillionDollarScript\Commerce\Currency::normalize_code($value, self::default_currency());
        }

        $code = strtoupper(substr(preg_replace('/[^A-Z]/', '', strtoupper(sanitize_text_field((string) $value))), 0, 3));

        return '' !== $code ? $code : 'USD';
    }

    private static function bounded_text($value, $length) {
        $value = sanitize_text_field((string) $value);

        return function_exists('mb_substr') ? mb_substr($value, 0, absint($length)) : substr($value, 0, absint($length));
    }

    private static function bounded_textarea($value, $length) {
        $value = sanitize_textarea_field((string) $value);

        return function_exists('mb_substr') ? mb_substr($value, 0, absint($length)) : substr($value, 0, absint($length));
    }

    private static function http_url($value) {
        $url = esc_url_raw((string) $value, ['http', 'https']);
        $parts = wp_parse_url($url);
        if (!$url || !is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }

    private static function valid_contact_email($value) {
        $email = sanitize_email((string) $value);
        if (is_email($email)) {
            return $email;
        }

        $fallback = sanitize_email((string) get_option('admin_email'));

        return is_email($fallback) ? $fallback : '';
    }

    private static function coordinate($value, $minimum, $maximum) {
        $value = trim((string) $value);
        if ('' === $value || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if (!is_finite($number) || $number < (float) $minimum || $number > (float) $maximum) {
            return null;
        }

        return round($number, 6);
    }

    private static function stored_coordinate($value) {
        $value = trim((string) $value);

        return '' === $value || !is_numeric($value) ? null : (float) $value;
    }

    private static function valid_date($value) {
        $value = sanitize_text_field((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private static function acquire_board_lock($board_id) {
        global $wpdb;

        return 1 === (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', 'mds_lmm_board_' . absint($board_id)));
    }

    private static function release_board_lock($board_id) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'mds_lmm_board_' . absint($board_id)));
    }

    private static function retention_spots($board_id, $cutoff, $limit) {
        return self::retention_posts(
            self::BOUNTY_POST_TYPE,
            '_mds_lmm_campaign_id',
            absint($board_id),
            '_mds_lmm_requester_email',
            absint($cutoff),
            absint($limit),
            [
                'relation' => 'OR',
                ['key' => '_mds_lmm_status', 'value' => ['completed', 'rejected', 'archived'], 'compare' => 'IN'],
                ['key' => '_mds_lmm_payment_status', 'value' => ['failed', 'cancelled', 'expired', 'refunded'], 'compare' => 'IN'],
            ]
        );
    }

    private static function retention_claims($board_id, $cutoff, $limit) {
        return self::retention_posts(
            self::CLAIM_POST_TYPE,
            '_mds_lmm_campaign_id',
            absint($board_id),
            '_mds_lmm_email',
            absint($cutoff),
            absint($limit)
        );
    }

    private static function retention_posts($post_type, $parent_key, $parent_id, $email_key, $cutoff, $limit, array $extra_meta = []) {
        $base_meta = [
            ['key' => $parent_key, 'value' => absint($parent_id), 'compare' => '='],
            ['key' => $email_key, 'value' => '', 'compare' => '!='],
        ];
        if ($extra_meta) {
            $base_meta[] = $extra_meta;
        }
        $common = [
            'post_type' => $post_type,
            'post_status' => ['publish', 'pending', 'draft'],
            'orderby' => 'date',
            'order' => 'ASC',
            'posts_per_page' => max(1, absint($limit)),
            'no_found_rows' => true,
        ];
        $modern = get_posts(array_merge($common, [
            'meta_query' => array_merge($base_meta, [[
                'key' => '_mds_lmm_private_data_updated_at',
                'value' => absint($cutoff),
                'type' => 'NUMERIC',
                'compare' => '<=',
            ]]),
        ]));
        $remaining = max(0, absint($limit) - count($modern));
        if (!$remaining) {
            return $modern;
        }

        $legacy = get_posts(array_merge($common, [
            'meta_query' => array_merge($base_meta, [['key' => '_mds_lmm_private_data_updated_at', 'compare' => 'NOT EXISTS']]),
            'date_query' => [['before' => gmdate('Y-m-d H:i:s', absint($cutoff)), 'inclusive' => true]],
            'posts_per_page' => $remaining,
        ]));

        return array_merge($modern, $legacy);
    }

    private static function collection(\WP_Query $query, $callback, $page, $per_page) {
        $total = absint($query->found_posts);

        return [
            'items' => array_values(array_filter(array_map($callback, $query->posts))),
            'page' => max(1, absint($page)),
            'per_page' => max(1, absint($per_page)),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / max(1, absint($per_page)))),
        ];
    }

    private static function empty_collection($page, $per_page) {
        return [
            'items' => [],
            'page' => max(1, absint($page)),
            'per_page' => max(1, absint($per_page)),
            'total' => 0,
            'pages' => 1,
        ];
    }
}
