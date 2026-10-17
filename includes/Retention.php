<?php
/**
 * Scheduled private-data retention cleanup.
 *
 * @package MillionDollarScript\Extensions\LocalMoneyMap
 */

namespace MillionDollarScript\Extensions\LocalMoneyMap;

if (!defined('ABSPATH')) {
    exit;
}

final class Retention {

    public const HOOK = 'mds_local_money_map_privacy_cleanup';

    public static function register() {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('init', [__CLASS__, 'schedule']);
    }

    public static function activate() {
        self::schedule();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run() {
        $page = 1;
        do {
            $boards = Repository::boards_page($page, 50);
            foreach ($boards['items'] as $board) {
                $iterations = 0;
                do {
                    $changed = Repository::cleanup_board_private_data(
                        absint($board['id'] ?? 0),
                        max(1, absint($board['retention_days'] ?? 365))
                    );
                    $iterations++;
                } while ($changed > 0 && $iterations < 20);
            }
            $page++;
        } while ($page <= absint($boards['pages'] ?? 1));
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }
}
