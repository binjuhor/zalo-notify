<?php
/*
Plugin Name: Woo Zalo Expiration Notify
Description: Notify customers via Zalo SMS when their product license is about to expire for ekeyms.vn.
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
        'limit' => 9999, // Get all orders
        'orderby' => 'date',
        'order' => 'DESC',
    );
    $orders = wc_get_orders($args);

    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $expiration_months = xdevlabs_get_expiration_months($item);
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

add_action('admin_menu', function() {
    add_menu_page(
        'Zalo Expiration Orders',
        'Zalo Expirations',
        'manage_woocommerce',
        'zalo-expiration-orders',
        'woo_expiration_notify_admin_page',
        'dashicons-schedule',
        56
    );
});

function woo_expiration_notify_admin_page() {
    $per_page = 10;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    // Get all order IDs for counting
    $args_count = array(
        'status' => array('completed', 'processing'),
        'return' => 'ids',
    );
    $all_order_ids = wc_get_orders($args_count);
    $total_orders = count($all_order_ids);
    $total_pages = ceil($total_orders / $per_page);

    // Get paged orders
    $args = array(
        'status' => array('completed', 'processing'),
        'limit' => $per_page,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
    );
    $orders = wc_get_orders($args);

    echo '<div class="wrap"><h1>Zalo Expiration Orders</h1>';
    echo '<table class="widefat"><thead>
        <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Product</th>
            <th>Expiration Date</th>
            <th>Zalo</th>
            <th>Action</th>
        </tr>
    </thead><tbody>';

    foreach ($orders as $order) {
        foreach ($order->get_items() as $item_id => $item) {
            $expiration_months = xdevlabs_get_expiration_months($item);
            $zalo = $item->get_meta('Zalo');
            if (!$expiration_months || !$zalo) continue;

            $order_date = $order->get_date_created();
            if (!$order_date) continue;

            $expiration_date = clone $order_date;
            $expiration_date->modify("+{$expiration_months} months");
            $expiration_str = $expiration_date->format('d/m/Y');
            echo '<tr>
                <td>#' . esc_html($order->get_order_number()) . '</td>
                <td>' . esc_html($order->get_formatted_billing_full_name()) . '</td>
                <td>' . esc_html($item->get_name()) . '</td>
                <td>' . esc_html($expiration_str) . '</td>
                <td>' . esc_html($zalo) . '</td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="zalo_notify_order_id" value="' . esc_attr($order->get_id()) . '">
                        <input type="hidden" name="zalo_notify_item_id" value="' . esc_attr($item_id) . '">
                        <input type="hidden" name="zalo_notify_expiration" value="' . esc_attr($expiration_str) . '">
                        <button class="button button-primary" name="zalo_notify_manual_send" value="1">Send Zalo</button>
                    </form>
                </td>
            </tr>';
        }
    }
    echo '</tbody></table>';

    // Pagination links
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo '<span class="pagination-links">';
        if ($paged > 1) {
            echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1)) . '">&laquo;</a> ';
            echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $paged - 1)) . '">&lsaquo;</a> ';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled">&laquo;</span> ';
            echo '<span class="tablenav-pages-navspan button disabled">&lsaquo;</span> ';
        }
        echo '<span class="paging-input">' . $paged . ' of <span class="total-pages">' . $total_pages . '</span></span> ';
        if ($paged < $total_pages) {
            echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $paged + 1)) . '">&rsaquo;</a> ';
            echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages)) . '">&raquo;</a>';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled">&rsaquo;</span> ';
            echo '<span class="tablenav-pages-navspan button disabled">&raquo;</span>';
        }
        echo '</span>';
        echo '</div></div>';
    }
}

if (isset($_POST['zalo_notify_manual_send'])) {
    $order_id = intval($_POST['zalo_notify_order_id']);
    $item_id = intval($_POST['zalo_notify_item_id']);
    $expiration = sanitize_text_field($_POST['zalo_notify_expiration']);
    $order = wc_get_order($order_id);
    $item = $order ? $order->get_item($item_id) : null;
    $zalo = $item ? $item->get_meta('Zalo') : '';
    if ($order && $item && $zalo) {
        woo_expiration_notify_send_zalo($zalo, $expiration, $order, $item);
        echo '<div class="notice notice-success is-dismissible"><p>Zalo message sent!</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>Failed to send Zalo message.</p></div>';
    }
}

function xdevlabs_get_expiration_months($item) {
    $expiration_months = null;
    $variation_id = $item->get_variation_id();

    if (!$variation_id) {
        $variation_id = $item->get_product_id();
    }

    $product = wc_get_product($variation_id);

    if ($product) {
        $expiration_months = $product->get_meta('expiration_months', true);
    }

    return $expiration_months;
}