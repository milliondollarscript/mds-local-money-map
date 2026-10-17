<?php
/**
 * WP-CLI fixture for Local Money Map QR generation.
 *
 * Usage:
 * wp --path=/var/www/html eval-file wp-content/plugins/mds-local-money-map/tests/qr-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\MillionDollarScript\\Extensions\\LocalMoneyMap\\QrCode') || !class_exists('\\MillionDollarScript\\Extensions\\LocalMoneyMap\\Repository')) {
    echo wp_json_encode(['skipped' => 'local_money_map_unavailable']) . "\n";
    return;
}

$admin = get_user_by('login', 'admin');
if ($admin) {
    wp_set_current_user($admin->ID);
}

$failures = [];
$created_posts = [];
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$invoke_private = static function ($object, $method, array $args = []) {
    $reflection = new ReflectionClass($object);
    $method_reflection = $reflection->getMethod($method);
    $method_reflection->setAccessible(true);

    return $method_reflection->invokeArgs($object, $args);
};

try {
    $matrix = \MillionDollarScript\Extensions\LocalMoneyMap\QrCode::matrix('https://example.test/?mds_lmm_spot=123');
    $assert(!is_wp_error($matrix), 'QR matrix should generate for a normal redirect URL.');
    $assert(is_array($matrix) && count($matrix) >= 21, 'QR matrix should be a square array with at least version 1 dimensions.');
    $assert(is_array($matrix) && count($matrix) === count($matrix[0] ?? []), 'QR matrix should be square.');

    $svg = \MillionDollarScript\Extensions\LocalMoneyMap\QrCode::svg('https://example.test/?mds_lmm_spot=123', 4, 4, 'Fixture QR');
    $assert(!is_wp_error($svg), 'QR SVG should generate for a normal redirect URL.');
    $assert(false !== strpos((string) $svg, '<svg'), 'QR SVG should contain an SVG root element.');
    $assert(false !== strpos((string) $svg, '<path'), 'QR SVG should contain a dark-module path.');
    $assert(false !== strpos((string) $svg, '<title>Fixture QR</title>'), 'QR SVG should include an escaped title when supplied.');

    $empty = \MillionDollarScript\Extensions\LocalMoneyMap\QrCode::svg('', 4, 4);
    $assert(is_wp_error($empty), 'Empty QR target should return WP_Error.');

    $too_long = \MillionDollarScript\Extensions\LocalMoneyMap\QrCode::svg(str_repeat('a', 5000), 4, 4);
    $assert(is_wp_error($too_long), 'Overlong QR target should return WP_Error.');

    $board_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::save_board([
        'title' => 'QR Fixture Campaign ' . wp_generate_uuid4(),
        'slug' => 'qr-fixture-' . wp_generate_password(8, false, false),
        'description' => 'Fixture campaign for QR tests.',
        'status' => 'active',
        'currency' => 'USD',
        'posting_fee' => 0,
        'featured_fee' => 0,
        'localmap_limit' => 150,
        'payment_mode' => 'manual',
        'claims_enabled' => '1',
        'disclosure' => '',
        'terms_url' => '',
        'contact_email' => get_option('admin_email'),
        'retention_days' => 30,
    ]);
    $assert($board_id > 0, 'Fixture campaign should be created.');
    if ($board_id) {
        $created_posts[] = $board_id;
    }

    $private_spot_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::create_localmap($board_id, [
        'title' => 'Pending QR Spot',
        'description' => 'Pending QR spot for private URL tests.',
        'requester_name' => 'Fixture',
        'requester_email' => 'fixture@example.test',
        'location_label' => 'Private fixture location',
        'external_url' => 'https://example.test/private',
        'terms_accepted' => '1',
    ]);
    $assert($private_spot_id > 0, 'Pending fixture spot should be created.');
    if ($private_spot_id) {
        $created_posts[] = $private_spot_id;
    }

    $public_spot_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::create_localmap($board_id, [
        'title' => 'Approved QR Spot',
        'description' => 'Approved QR spot for public URL tests.',
        'requester_name' => 'Fixture',
        'requester_email' => 'fixture@example.test',
        'location_label' => 'Toronto fixture location',
        'latitude' => '43.6532',
        'longitude' => '-79.3832',
        'reward_label' => 'Fixture reward',
        'external_url' => 'https://example.test/public',
        'terms_accepted' => '1',
    ]);
    $assert($public_spot_id > 0, 'Approved fixture spot should be created.');
    if ($public_spot_id) {
        $created_posts[] = $public_spot_id;
        \MillionDollarScript\Extensions\LocalMoneyMap\Repository::update_localmap_status($public_spot_id, 'approve');
    }

    $main = \MillionDollarScript\Extensions\LocalMoneyMap\Main::instance();
    $public_spot = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::localmap($public_spot_id);
    $private_spot = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::localmap($private_spot_id);

    $manifest = $main->api_endpoint_manifest([]);
    $manifest_by_id = [];
    foreach ($manifest as $endpoint) {
        $manifest_by_id[(string) ($endpoint['id'] ?? '')] = $endpoint;
    }

    foreach (['local-money-map-boards-read', 'local-money-map-spots-read'] as $endpoint_id) {
        $assert('public_read' === (string) ($manifest_by_id[$endpoint_id]['minimum_security_level'] ?? ''), 'Public Local Money Map reads should use public_read: ' . $endpoint_id);
    }
    foreach (['local-money-map-spots-create', 'local-money-map-spots-moderate', 'local-money-map-claims-create', 'local-money-map-claims-moderate'] as $endpoint_id) {
        $assert('wp_capability' === (string) ($manifest_by_id[$endpoint_id]['minimum_security_level'] ?? ''), 'Private Local Money Map writes should remain administrator-only: ' . $endpoint_id);
    }

    $public_board_payload = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::public_board_payload(\MillionDollarScript\Extensions\LocalMoneyMap\Repository::board($board_id));
    $public_spot_payload = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::public_spot_payload($public_spot);
    foreach (['contact_email', 'payment_mode', 'retention_days'] as $private_key) {
        $assert(!array_key_exists($private_key, $public_board_payload), 'Public campaign payload should omit private field: ' . $private_key);
    }
    foreach (['requester_email', 'payment_status', 'payment_amount', 'amount', 'retention_days'] as $private_key) {
        $assert(!array_key_exists($private_key, $public_spot_payload), 'Public spot payload should omit private field: ' . $private_key);
    }
    $assert(!array_key_exists('requester_name', $public_spot_payload), 'Public spot payload should omit the private requester name.');

    $assert(true === $invoke_private($main, 'localmap_qr_public', [$public_spot]), 'Approved spot should be public for QR delivery.');
    $assert(false === $invoke_private($main, 'localmap_qr_public', [$private_spot]), 'Pending spot should not be public for QR delivery.');

    $public_url = (string) $invoke_private($main, 'qr_image_url', [$public_spot_id]);
    $assert(false !== strpos($public_url, 'action=mds_local_money_map_qr'), 'Public QR image URL should use the QR admin-post action.');
    $assert(false !== strpos($public_url, 'spot_id=' . $public_spot_id), 'Public QR image URL should include the spot ID.');
    $assert(false === strpos($public_url, '_wpnonce='), 'Public approved QR image URL should not require an admin nonce.');

    $download_url = (string) $invoke_private($main, 'qr_image_url', [$public_spot_id, true]);
    $assert(false !== strpos($download_url, 'download=1'), 'Download QR URL should include the download flag.');

    $private_url = (string) $invoke_private($main, 'qr_image_url', [$private_spot_id]);
    $assert(false !== strpos($private_url, '_wpnonce='), 'Private pending QR image URL should include an admin nonce for managers.');

    $print_url = (string) $invoke_private($main, 'qr_print_url', [$board_id, 4]);
    $assert(false !== strpos($print_url, 'action=mds_local_money_map_qr_print'), 'QR print URL should use the print admin-post action.');
    $assert(false !== strpos($print_url, 'per_page=4'), 'QR print URL should include the requested print layout.');
    $assert(false !== strpos($print_url, 'batch=1'), 'QR print URL should include the requested print batch.');
    $assert(false !== strpos($print_url, '_wpnonce='), 'QR print URL should include an admin nonce.');

    $valid_submission = [
        'title' => 'Validation fixture spot',
        'description' => 'Validation fixture description.',
        'requester_email' => 'validation@example.test',
        'location_label' => 'Validation location',
        'external_url' => 'https://example.test/validation',
        'terms_accepted' => '1',
    ];
    $invalid_terms = $valid_submission;
    unset($invalid_terms['terms_accepted']);
    $assert(is_wp_error(\MillionDollarScript\Extensions\LocalMoneyMap\Repository::validate_localmap_submission($board_id, $invalid_terms)), 'Sponsor spot validation should require explicit terms acceptance.');
    $invalid_url = array_merge($valid_submission, ['external_url' => 'javascript:alert(1)']);
    $assert(is_wp_error(\MillionDollarScript\Extensions\LocalMoneyMap\Repository::validate_localmap_submission($board_id, $invalid_url)), 'Sponsor spot validation should reject non-HTTP sponsor URLs.');
    $invalid_date = array_merge($valid_submission, ['due_at' => '2026-02-30']);
    $assert(is_wp_error(\MillionDollarScript\Extensions\LocalMoneyMap\Repository::validate_localmap_submission($board_id, $invalid_date)), 'Sponsor spot validation should reject impossible calendar dates.');
    $invalid_coordinates = array_merge($valid_submission, ['latitude' => '91', 'longitude' => '-79']);
    $assert(is_wp_error(\MillionDollarScript\Extensions\LocalMoneyMap\Repository::validate_localmap_submission($board_id, $invalid_coordinates)), 'Sponsor spot validation should reject out-of-range coordinates.');

    for ($index = 1; $index <= 100; $index++) {
        $spot_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::create_localmap($board_id, [
            'title' => 'Paged spot ' . $index,
            'description' => 'Paged sponsor spot fixture.',
            'requester_email' => 'paged-spots@example.test',
            'location_label' => 'Paged fixture location ' . $index,
            'external_url' => 'https://example.test/paged-spot-' . $index,
            'terms_accepted' => '1',
        ]);
        if ($spot_id) {
            $created_posts[] = $spot_id;
        }
    }
    $spots_page_three = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::spots_page($board_id, 3, 50);
    $assert(102 === (int) ($spots_page_three['total'] ?? 0), 'Admin sponsor spot totals should remain accurate beyond 100 records.');
    $assert(3 === (int) ($spots_page_three['pages'] ?? 0), 'Sponsor spot collections should paginate beyond 100 records.');
    $assert(2 === count($spots_page_three['items'] ?? []), 'The final sponsor spot page should contain the remaining records.');

    for ($index = 1; $index <= 102; $index++) {
        $claim_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::create_claim($public_spot_id, [
            'display_name' => 'Privacy fixture ' . $index,
            'email' => 'privacy-fixture@example.test',
            'proposal' => 'Fixture check-in number ' . $index,
            'work_url' => 'https://example.test/proof-' . $index,
            'terms_accepted' => '1',
        ]);
        if ($claim_id) {
            $created_posts[] = $claim_id;
        }
    }
    $claims_page_three = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::claims_page($public_spot_id, 3, 50);
    $assert(102 === (int) ($claims_page_three['total'] ?? 0), 'Check-in totals should remain accurate beyond 100 records.');
    $public_spot = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::localmap($public_spot_id);
    $assert(102 === (int) ($public_spot['claim_count'] ?? 0), 'Sponsor spot check-in counts should remain accurate beyond 100 records.');
    $privacy_page_one = \MillionDollarScript\Extensions\LocalMoneyMap\Privacy::exporter('privacy-fixture@example.test', 1);
    $privacy_page_three = \MillionDollarScript\Extensions\LocalMoneyMap\Privacy::exporter('privacy-fixture@example.test', 3);
    $assert(false === (bool) ($privacy_page_one['done'] ?? true), 'Privacy export should continue when more than one page exists.');
    $assert(true === (bool) ($privacy_page_three['done'] ?? false), 'Privacy export should finish on the final matching page.');
    $erase_one = \MillionDollarScript\Extensions\LocalMoneyMap\Privacy::eraser('privacy-fixture@example.test', 1);
    $erase_two = \MillionDollarScript\Extensions\LocalMoneyMap\Privacy::eraser('privacy-fixture@example.test', 2);
    $erase_three = \MillionDollarScript\Extensions\LocalMoneyMap\Privacy::eraser('privacy-fixture@example.test', 3);
    $assert(false === (bool) ($erase_one['done'] ?? true) && false === (bool) ($erase_two['done'] ?? true), 'Privacy erasure should process large matching sets in bounded batches.');
    $assert(true === (bool) ($erase_three['done'] ?? false), 'Privacy erasure should report completion after all matching records are anonymized.');

    $retention_spot_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::create_localmap($board_id, [
        'title' => 'Retention spot',
        'description' => 'Terminal retention fixture.',
        'requester_name' => 'Retention Person',
        'requester_email' => 'retention-spot@example.test',
        'location_label' => 'Retention fixture location',
        'external_url' => 'https://example.test/retention-spot',
        'terms_accepted' => '1',
    ]);
    $created_posts[] = $retention_spot_id;
    \MillionDollarScript\Extensions\LocalMoneyMap\Repository::update_localmap_status($retention_spot_id, 'reject');
    update_post_meta($retention_spot_id, '_mds_lmm_private_data_updated_at', time() - (40 * DAY_IN_SECONDS));

    $retention_claim_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::create_claim($public_spot_id, [
        'display_name' => 'Retention Claimant',
        'email' => 'retention-claim@example.test',
        'proposal' => 'Retention check-in fixture.',
        'work_url' => 'https://example.test/retention-proof',
        'terms_accepted' => '1',
    ]);
    $created_posts[] = $retention_claim_id;
    update_post_meta($retention_claim_id, '_mds_lmm_private_data_updated_at', time() - (40 * DAY_IN_SECONDS));
    $retention_changed = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::cleanup_board_private_data($board_id, 30, 20);
    $assert($retention_changed >= 2, 'Retention cleanup should anonymize stale terminal sponsor and check-in contact records.');
    $assert('' === get_post_meta($retention_spot_id, '_mds_lmm_requester_email', true), 'Retention cleanup should erase stale terminal sponsor contact email.');
    $assert('' === get_post_meta($retention_claim_id, '_mds_lmm_email', true), 'Retention cleanup should erase stale check-in contact email.');

    $board_data = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::board($board_id);
    \MillionDollarScript\Extensions\LocalMoneyMap\Repository::save_board(array_merge($board_data, ['status' => 'paused']), $board_id);
    $assert(false === \MillionDollarScript\Extensions\LocalMoneyMap\Repository::is_spot_public($public_spot_id), 'Pausing a campaign should immediately disable public sponsor spots and QR delivery.');
    $assert(0 === (int) (\MillionDollarScript\Extensions\LocalMoneyMap\Repository::spots_page($board_id, 1, 20, true)['total'] ?? -1), 'Paused campaign should return no public sponsor spots.');
    \MillionDollarScript\Extensions\LocalMoneyMap\Repository::save_board(array_merge($board_data, ['status' => 'active']), $board_id);

    $provider_board_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::save_board([
        'title' => 'Provider Fixture Campaign ' . wp_generate_uuid4(),
        'slug' => 'provider-fixture-' . wp_generate_password(8, false, false),
        'description' => 'Provider publication gate fixture.',
        'status' => 'active',
        'currency' => 'USD',
        'posting_fee' => 10,
        'featured_fee' => 0,
        'localmap_limit' => 5,
        'payment_mode' => 'provider',
        'claims_enabled' => '1',
        'contact_email' => get_option('admin_email'),
        'retention_days' => 30,
    ]);
    $created_posts[] = $provider_board_id;

    $moderated_first_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::create_localmap($provider_board_id, [
        'title' => 'Moderated first spot',
        'description' => 'Must remain private before payment.',
        'requester_email' => 'fixture@example.test',
        'location_label' => 'Provider fixture location one',
        'external_url' => 'https://example.test/moderated-first',
        'terms_accepted' => '1',
    ]);
    $created_posts[] = $moderated_first_id;
    \MillionDollarScript\Extensions\LocalMoneyMap\Repository::update_localmap_status($moderated_first_id, 'approve');
    $assert('pending' === get_post_status($moderated_first_id), 'Provider-pending spot should remain private after moderation.');
    $assert([] === \MillionDollarScript\Extensions\LocalMoneyMap\Repository::spots($provider_board_id, ['public_only' => true]), 'Provider-pending spot should not be returned publicly.');
    \MillionDollarScript\Extensions\LocalMoneyMap\Repository::update_payment_status($moderated_first_id, 'paid');
    $assert('publish' === get_post_status($moderated_first_id), 'Moderated spot should publish after payment.');
    \MillionDollarScript\Extensions\LocalMoneyMap\Repository::update_payment_status($moderated_first_id, 'refunded');
    $assert('pending' === get_post_status($moderated_first_id), 'Refunded spot should be removed from public display.');

    $paid_first_id = \MillionDollarScript\Extensions\LocalMoneyMap\Repository::create_localmap($provider_board_id, [
        'title' => 'Paid first spot',
        'description' => 'Must remain private before moderation.',
        'requester_email' => 'fixture@example.test',
        'location_label' => 'Provider fixture location two',
        'external_url' => 'https://example.test/paid-first',
        'terms_accepted' => '1',
    ]);
    $created_posts[] = $paid_first_id;
    \MillionDollarScript\Extensions\LocalMoneyMap\Repository::update_payment_status($paid_first_id, 'paid');
    $assert('pending' === get_post_status($paid_first_id), 'Payment should not publish an unmoderated spot.');
    \MillionDollarScript\Extensions\LocalMoneyMap\Repository::update_localmap_status($paid_first_id, 'approve');
    $assert('publish' === get_post_status($paid_first_id), 'Paid spot should publish after moderation.');
} finally {
    foreach (array_reverse($created_posts) as $post_id) {
        wp_delete_post((int) $post_id, true);
    }
}

echo wp_json_encode([
    'status' => $failures ? 'failed' : 'passed',
    'failures' => $failures,
]) . "\n";
