<?php
// middleware/SecurityHeadersMiddleware.php

class SecurityHeadersMiddleware
{
    public static function apply()
    {
        // Only apply if not in CLI mode
        if (php_sapi_name() === 'cli') {
            return;
        }

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Enable XSS protection
        header('X-XSS-Protection: 1; mode=block');

        // Control referrer information
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Frame options - prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Remove server signature
        header_remove('X-Powered-By');

        // Content Security Policy (commented out for development)
        // header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com https://cdn.datatables.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:;");
    }
}