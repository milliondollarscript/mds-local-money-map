<?php
/**
 * Local order-path fixture for Local Money Map.
 *
 * A sponsored stop reaches the map through a small chain of decisions, and the extension
 * learns about each one from the core rather than owning any of it: the owner's review,
 * the fee being settled, the paid window a subscription keeps open. This fixture walks that
 * chain through the real hooks and checks the parts that matter - payment does not skip the
 * review, another extension's payment events must not move this stop, an expired payment or
 * an expired window takes it back down, and a site that takes payment itself settles the
 * fee when it approves the stop.
 *
 * Run with:
 * ./scripts/wp eval-file wp-content/plugins/mds-local-money-map/tests/mds3-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

use MillionDollarScript\Extensions\LocalMoneyMap\Main;
use MillionDollarScript\Extensions\LocalMoneyMap\Repository;

if (!class_exists(Main::class) || !class_exists(Repository::class)) {
    fwrite(STDERR, "Local Money Map classes are not loaded.\n");
    exit(1);
}

wp_set_current_user(1);

$failures = [];
$assert = static function ($condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$board_ids = [];
$spot_ids = [];

$status_meta = static function (int $spot_id, string $key): string {
    return trim((string) get_post_meta($spot_id, $key, true));
};

$state = static function (int $spot_id): array {
    $record = Repository::localmap($spot_id);
    return [
        'status' => (string) ($record['status'] ?? ''),
        'payment' => (string) ($record['payment_status'] ?? ''),
        'window' => (string) ($record['subscription_expires_at'] ?? ''),
        'post' => (string) get_post_status($spot_id),
        'public' => Repository::is_spot_public($spot_id),
    ];
};

$make_spot = static function (string $tag, string $payment_mode, array &$board_ids, array &$spot_ids) {
    $board_id = Repository::save_board([
        'title' => 'Fixture Board ' . $tag . ' ' . wp_generate_password(6, false, false),
        'slug' => 'fixture-board-' . $tag . '-' . wp_generate_password(6, false, false),
        'description' => 'Created by the order-path fixture.',
        'status' => 'active',
        'payment_mode' => $payment_mode,
        'currency' => 'USD',
        'posting_fee' => '25.00',
        'featured_fee' => '0.00',
        'localmap_limit' => 5,
        'disclosure' => 'Sponsored placement, disclosed the way the extension asks.',
        'contact_email' => 'fixture@example.test',
        'retention_days' => 30,
        'submission_limit_per_hour' => 5,
    ]);
    $spot_id = Repository::create_localmap($board_id, [
        'title' => 'Fixture Stop ' . $tag,
        'description' => 'A business stop created by the order-path fixture.',
        'requester_name' => 'Fixture Owner',
        'requester_email' => 'fixture-owner@example.test',
        'category' => 'market',
        'location_label' => '1 Fixture Lane',
        'latitude' => '43.65',
        'longitude' => '-79.38',
        'external_url' => 'https://example.test/fixture-' . $tag,
        'terms_accepted' => true,
    ]);
    $board_ids[] = absint($board_id);
    $spot_ids[] = absint($spot_id);
    return [absint($board_id), absint($spot_id)];
};

try {
    // 1. A stop that has just been submitted is waiting on the owner, and is not on the map.
    [$provider_board, $spot] = $make_spot('provider', 'provider', $board_ids, $spot_ids);
    $assert($provider_board > 0, 'The fixture board should be created.');
    $assert($spot > 0, 'A sponsored stop should be created on an active board.');
    $opened = $state($spot);
    $assert('pending_review' === $opened['status'], 'A new stop should wait for the owner to review it.');
    $assert('pending' === $opened['post'], 'A stop that has not been approved should stay unpublished.');
    $assert(!$opened['public'], 'A stop that has not been approved should not be on the map.');
    $assert(25.0 === (float) (Repository::localmap($spot)['amount'] ?? 0), 'The stop should carry the board fee as its amount.');

    // 2. The core reports the fee is settled. That records the payment; it does not skip the review.
    do_action('million-dollar-script/payment/source/status', 'local-money-map', $spot, 'paid', ['provider' => 'fixture']);
    $after_paid = $state($spot);
    $assert('paid' === $after_paid['payment'], 'The paid event for this extension should record the fee as settled.');
    $assert(!$after_paid['public'], 'Settling the fee must not put a stop on the map before the owner has reviewed it.');

    // 3. An event aimed at another extension, or at a stop that does not exist, changes nothing.
    do_action('million-dollar-script/payment/source/status', 'mds-sponsorboard', $spot, 'refunded', ['provider' => 'fixture']);
    do_action('million-dollar-script/payment/source/status', 'local-money-map', 0, 'refunded', ['provider' => 'fixture']);
    $after_foreign = $state($spot);
    $assert('paid' === $after_foreign['payment'], 'A payment event for another extension should not move this stop.');
    $assert($after_foreign === $after_paid, 'Events for an unknown stop should not change anything.');

    // 4. The owner approves it: now it is on the map.
    $assert(true === Repository::update_localmap_status($spot, 'approve'), 'Approving a stop should be accepted.');
    $approved = $state($spot);
    $assert('open' === $approved['status'], 'An approved stop should be open.');
    $assert('publish' === $approved['post'], 'An approved, paid stop should be published.');
    $assert(true === Repository::is_spot_public($spot), 'An approved, paid stop should be reachable on the map.');

    // 5. An expired payment takes it back off.
    do_action('million-dollar-script/payment/source/status', 'local-money-map', $spot, 'expired', ['provider' => 'fixture']);
    $expired = $state($spot);
    $assert('expired' === $expired['payment'], 'An expired payment should be recorded.');
    $assert(!$expired['public'], 'A stop whose payment has run out should come off the map.');

    // 6. A subscription window is only ever moved forward, and a bad value never clears it.
    $future = gmdate('Y-m-d H:i:s', time() + 45 * DAY_IN_SECONDS);
    do_action('million-dollar-script/commerce/source/activate', 'local-money-map', $spot, ['subscription' => ['current_period_end' => $future]]);
    $assert($future === $state($spot)['window'], 'Activating a subscription should record its period end.');

    $sooner = gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS);
    do_action('million-dollar-script/commerce/source/renew', 'local-money-map', $spot, ['subscription' => ['current_period_end' => $sooner]]);
    $assert($future === $state($spot)['window'], 'A shorter term must not shorten a window that is already paid for.');

    $later = gmdate('Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS);
    do_action('million-dollar-script/commerce/source/renew', 'local-money-map', $spot, ['subscription' => ['current_period_end' => $later]]);
    $assert($later === $state($spot)['window'], 'A longer term should extend the window.');

    do_action('million-dollar-script/commerce/source/activate', 'local-money-map', $spot, ['subscription' => ['current_period_end' => 'not a date']]);
    $assert($later === $state($spot)['window'], 'A malformed period end must not wipe the stored window.');

    // 7. The fee coming back puts it on the map again, while the paid window is open.
    do_action('million-dollar-script/payment/source/status', 'local-money-map', $spot, 'paid', ['provider' => 'fixture']);
    $assert(true === Repository::is_spot_public($spot), 'A stop whose fee is settled again should be back on the map while its window is open.');

    // 8. A window that has run out takes it down even when the fee record still says paid.
    [$second_board, $second] = $make_spot('window', 'provider', $board_ids, $spot_ids);
    $assert($second > 0, 'A second stop should be creatable for the expired-window check.');
    Repository::update_localmap_status($second, 'approve');
    do_action('million-dollar-script/payment/source/status', 'local-money-map', $second, 'paid', ['provider' => 'fixture']);
    $assert(true === Repository::is_spot_public($second), 'The second stop should be visible once approved and paid.');
    $past = gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS);
    do_action('million-dollar-script/commerce/source/activate', 'local-money-map', $second, ['subscription' => ['current_period_end' => $past]]);
    do_action('million-dollar-script/payment/source/status', 'local-money-map', $second, 'paid', ['provider' => 'fixture']);
    $run_out = $state($second);
    $assert('paid' === $run_out['payment'], 'The fee record should still say paid.');
    $assert(false === $run_out['public'], 'A paid window that has run out should take the stop off the map even when the fee says paid.');

    // 9. On a manual-payment board the site records the money itself: approving settles it.
    [$manual_board, $manual] = $make_spot('manual', 'manual', $board_ids, $spot_ids);
    $assert($manual > 0, 'A stop should be creatable on a manual-payment board.');
    $assert(true === Repository::update_localmap_status($manual, 'approve'), 'Approving a stop on a manual-payment board should be accepted.');
    $settled = $state($manual);
    $assert('paid' === $settled['payment'], 'Approving a stop on a board that takes payment itself should settle the fee.');
    $assert(true === $settled['public'], 'An approved stop whose fee the site settled should be on the map.');
    $assert('paid' === $status_meta($manual, '_mds_lmm_payment_status'), 'The settled fee should be readable on the record.');

    // 10. The extension still declares the surface the rest of the product reads.
    $manifest = apply_filters('million-dollar-script/api/endpoint/manifest', []);
    $assert(is_array($manifest) && (bool) preg_match('/local-?money-?map/i', wp_json_encode($manifest)), 'Local Money Map should still declare its API endpoints.');
    $onboarding = apply_filters('million-dollar-script/extension/onboarding/items', []);
    $assert(is_array($onboarding['mds-local-money-map'] ?? null), 'Local Money Map should still declare its onboarding item.');
} catch (Throwable $e) {
    $failures[] = 'Unexpected error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
} finally {
    foreach ($spot_ids as $spot_id) {
        if ($spot_id > 0) {
            wp_delete_post($spot_id, true);
        }
    }
    foreach ($board_ids as $board_id) {
        if ($board_id > 0) {
            wp_delete_post($board_id, true);
        }
    }
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "MDS3 local money map order-path fixture passed: review gates the map, payment does not skip it, foreign and unknown events are ignored, an expired fee or an expired window takes the stop down, paid windows only move forward, and a board that takes payment itself settles on approval.\n";
