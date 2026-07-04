<?php
// /src/Helpers/Formatter.php
// Utility class for formatting data

class Formatter {
    
    /**
     * Format currency amount
     */
    public static function currency($amount, $currency = DEFAULT_CURRENCY) {
        return number_format((float)$amount, 0, ',', ' ') . ' ' . $currency;
    }
    
    /**
     * Format date
     */
    public static function date($timestamp, $format = 'd/m/Y') {
        return date($format, strtotime($timestamp));
    }
    
    /**
     * Format datetime
     */
    public static function datetime($timestamp, $format = 'd/m/Y H:i') {
        return date($format, strtotime($timestamp));
    }
    
    /**
     * Truncate text
     */
    public static function truncate($text, $length = 100, $suffix = '...') {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . $suffix;
    }
    
    /**
     * Format product stock status for display
     */
    public static function stockStatus($quantity, $reorderLevel = 10) {
        if ($quantity <= 0) {
            return ['text' => 'Out of Stock', 'class' => 'out-of-stock'];
        }
        if ($quantity <= $reorderLevel) {
            return ['text' => 'Low Stock', 'class' => 'low-stock'];
        }
        return ['text' => 'In Stock', 'class' => 'in-stock'];
    }
}