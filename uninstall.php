<?php
/** Local Money Map uninstall cleanup. */
if (!defined('WP_UNINSTALL_PLUGIN') || !class_exists('\\MillionDollarScript\\Extensions\\CleanupPolicy')) { exit; }
\MillionDollarScript\Extensions\CleanupPolicy::cleanup('mds-local-money-map', [
    'post_types' => ['mds_lmm_campaign', 'mds_lmm_spot', 'mds_lmm_checkin'],
    'option_prefixes' => ['mds_local_money_map_'],
    'transient_prefixes' => ['mds_local_money_map_'],
]);
