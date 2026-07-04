<?php
// config/TwoFactorAuth.php
// ============================================
// TWO-FACTOR AUTHENTICATION HELPER
// ============================================

class TwoFactorAuth
{
    private static $issuer = 'SATI ERP';
    private static $algorithm = 'sha1';
    private static $digits = 6;
    private static $period = 30;

    /**
     * Generate a new secret key for 2FA
     */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        return self::base32Encode($bytes);
    }

    /**
     * Generate a QR code URL for Google Authenticator
     */
    public static function getQRCodeUrl(string $email, string $secret): string
    {
        $label = rawurlencode(self::$issuer . ':' . $email);
        $issuer = rawurlencode(self::$issuer);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=" . strtoupper(self::$algorithm) . "&digits=" . self::$digits . "&period=" . self::$period;
    }

    /**
     * Verify a 2FA code
     */
    public static function verifyCode(string $secret, string $code, int $discrepancy = 1): bool
    {
        $code = trim($code);

        // Remove any spaces
        $code = str_replace(' ', '', $code);

        // Check if code is numeric and correct length
        if (!ctype_digit($code) || strlen($code) !== self::$digits) {
            return false;
        }

        $currentTimeSlice = floor(time() / self::$period);

        // Check current and surrounding time slices (for time drift)
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::generateCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a 2FA code for a given secret and time slice
     */
    private static function generateCode(string $secret, int $timeSlice): string
    {
        $secret = self::base32Decode($secret);

        // Pack time into binary string
        $time = pack('N*', 0) . pack('N*', $timeSlice);

        // Hash it with SHA1
        $hash = hash_hmac(self::$algorithm, $time, $secret, true);

        // Use last nibble as index
        $offset = ord($hash[19]) & 0x0F;

        // Extract 4 bytes from hash starting at offset
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        // Generate the code
        $code = $value % pow(10, self::$digits);

        // Pad with leading zeros if necessary
        return str_pad((string) $code, self::$digits, '0', STR_PAD_LEFT);
    }

    /**
     * Generate backup codes (for when user loses 2FA device)
     */
    public static function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = bin2hex(random_bytes(5)); // 10 characters
        }
        return $codes;
    }

    /**
     * Verify a backup code
     */
    public static function verifyBackupCode(string $backupCode, array &$storedCodes): bool
    {
        $hashedCode = hash('sha256', $backupCode);

        foreach ($storedCodes as $index => $code) {
            if (hash_equals($code, $hashedCode)) {
                // Remove used backup code
                unset($storedCodes[$index]);
                $storedCodes = array_values($storedCodes);
                return true;
            }
        }

        return false;
    }

    /**
     * Encode binary data to base32
     */
    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        // Convert to binary string
        for ($i = 0; $i < strlen($data); $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Split into 5-bit chunks
        $chunks = str_split($binary, 5);
        $base32 = '';

        foreach ($chunks as $chunk) {
            $base32 .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $base32;
    }

    /**
     * Decode base32 to binary data
     */
    private static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper($data);
        $binary = '';

        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $value = strpos($alphabet, $char);
            if ($value === false) {
                continue;
            }
            $binary .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        // Convert back to bytes
        $bytes = str_split($binary, 8);
        $result = '';

        foreach ($bytes as $byte) {
            if (strlen($byte) === 8) {
                $result .= chr(bindec($byte));
            }
        }

        return $result;
    }
}