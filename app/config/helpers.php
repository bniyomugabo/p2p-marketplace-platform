<?php
// config/helpers.php
// ============================================
// HELPER FUNCTIONS
// ============================================

function route_url($page, $params = []): string
{
    $query = http_build_query(array_merge(['page' => $page], $params));
    return BASE_URL . 'index.php?' . $query;
}

function asset_url($path): string
{
    return ASSETS_URL . '/' . ltrim($path, '/');
}

if (!function_exists('format_currency')) {
    function format_currency($amount, $currency = 'RWF')
    {
        return number_format((float)$amount, 0) . ' ' . $currency;
    }
}

if (!function_exists('format_date')) {
    function format_date($date, $format = 'd/m/Y')
    {
        if (empty($date))
            return '';
        return date($format, strtotime($date));
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime($datetime, $format = 'd/m/Y H:i')
    {
        if (empty($datetime))
            return '';
        return date($format, strtotime($datetime));
    }
}

if (!function_exists('time_ago')) {
    function time_ago($datetime)
    {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('d/m/Y', $time);
        }
    }
}

if (!function_exists('generate_sku')) {
    function generate_sku($productId, $attributes = [])
    {
        $prefix = 'SKU';
        $productCode = str_pad($productId, 4, '0', STR_PAD_LEFT);

        if (!empty($attributes)) {
            $attrCode = '';
            foreach ($attributes as $attr) {
                $attrCode .= strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $attr), 0, 3));
            }
            return $prefix . '-' . $productCode . '-' . $attrCode;
        }

        return $prefix . '-' . $productCode;
    }
}

if (!function_exists('generate_barcode')) {
    function generate_barcode()
    {
        return '2' . date('y') . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}


function format_currency($amount, $currency = 'RWF'): string
{
    return number_format((float)$amount, 2) . ' ' . $currency;
}

if (!function_exists('sanitize_input')) {
    function sanitize_input($input)
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('is_ajax_request')) {
    function is_ajax_request()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }
}

if (!function_exists('get_status_badge')) {
    function get_status_badge($status)
    {
        $badges = [
            'paid' => 'success',
            'unpaid' => 'danger',
            'partial' => 'warning',
            'pending' => 'info',
            'completed' => 'success',
            'cancelled' => 'secondary',
            'draft' => 'light',
            'issued' => 'primary',
            'overdue' => 'danger'
        ];

        $class = $badges[strtolower($status)] ?? 'secondary';
        return "<span class='badge bg-{$class}'>{$status}</span>";
    }
}

