<?php
if ( ! defined( 'ABSPATH' ) ) exit;
function ads_manager_get_option($key, $default = ''){
    $opts = get_option('ads_manager_options', array());
    return isset($opts[$key]) ? $opts[$key] : $default;
}
