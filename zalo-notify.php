/**
<?php
/*
Plugin Name: Woo Expiration Notify
Description: Notify customers via Zalo SMS when their product license is about to expire.
Version: 1.0
Author: Binjuhor
Author URI: https://binjuhor.com
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: woo-expiration-notify
*/

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('init', function() {
    if ( ! wp_next_scheduled( 'woo_expiration_notify_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'woo_expiration_notify_cron' );
    }
});

add_action('woo_expiration_notify_cron', 'woo_expiration_notify_check_expirations');

function woo_expiration_notify_check_expirations() {
    $args = array(
        'status' => array('completed', 'processing'),
        'limit' => 50,
        'orderby' => 'date',
        'order' => 'DESC',
    );
    $orders = wc_get_orders($args);

    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $expiration_months = $item->get_meta('expiration_months');
            $zalo = $item->get_meta('Zalo');
            if ( ! $expiration_months || ! $zalo ) continue;

            $order_date = $order->get_date_created();
            if ( ! $order_date ) continue;

            $expiration_date = clone $order_date;
            $expiration_date->modify("+{$expiration_months} months");

            // Notify 7 days before expiration
            $now = new DateTime();
            $interval = $now->diff($expiration_date);
            if ($interval->invert === 0 && $interval->days <= 7) {
                woo_expiration_notify_send_zalo($zalo, $expiration_date->format('d/m/Y'), $order, $item);
            }
        }
    }
}

function woo_expiration_notify_send_zalo($zalo, $expiration_date, $order = null, $item = null) {
    if ( ! class_exists('WP_Zalo_OA_API') ) {
        return;
    }

    $phone = WP_Zalo_OA_API::format_phone($zalo);

    // Prepare template data
    $template_data = array(
        'order_code'    => $order ? $order->get_order_number() : '',
        'date'          => $expiration_date,
        'price'         => $item ? $item->get_total() : 0,
        'name'          => $order ? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() : '',
        'product_name'  => $item ? $item->get_name() : '',
        'order_id'      => $order ? $order->get_id() : '',
    );

    $template_id = get_option('wp_zalo_expired_template_id');

    $zalo_api = new WP_Zalo_OA_API();
    $zalo_api->send_template_message($phone, $template_id, $template_data);
}