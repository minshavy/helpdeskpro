<?php
/**
 * TOTP (Time-based One-Time Password) - RFC 6238 / RFC 4226
 * ---------------------------------------------------------
 * A minimal, self-contained implementation compatible with Google
 * Authenticator, Authy, and any other standard TOTP app. No external
 * service or Composer package required.
 */
class TOTP {
    private static $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a random base32 secret (16 chars = 80 bits, standard length). */
    public static function generateSecret($length = 16) {
        $chars = self::$base32chars;
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    private static function base32Decode($b32) {
        $b32 = strtoupper($b32);
        $chars = self::$base32chars;
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';
        for ($i = 0; $i < strlen($b32); $i++) {
            $char = $b32[$i];
            if ($char === '=') continue;
            $val = strpos($chars, $char);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $result;
    }

    /** Compute the 6-digit code for a given 30-second time slice (defaults to now). */
    public static function getCode($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = (int) floor(time() / 30);
        }
        $secretKey = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice); // 8-byte big-endian counter
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;
        $code = $value % 1000000;
        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a submitted code, allowing +/- $discrepancy time steps (30s each)
     * to tolerate minor clock drift between the server and the user's phone.
     */
    public static function verifyCode($secret, $code, $discrepancy = 1) {
        $code = trim((string) $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $currentTimeSlice = (int) floor(time() / 30);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculated = self::getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculated, $code)) {
                return true;
            }
        }
        return false;
    }

    /** Build the otpauth:// URI that QR-code apps scan to add the account. */
    public static function getProvisioningUri($secret, $accountName, $issuer) {
        $label = rawurlencode($issuer . ':' . $accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
        ]);
        return "otpauth://totp/$label?$params";
    }
}
