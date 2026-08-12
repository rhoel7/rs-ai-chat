<?php
/**
 * Encrypts/decrypts message content before it touches the database, using
 * AES-256-GCM via PHP's openssl extension — authenticated encryption
 * (tampering is detected, not just content hidden), same security level
 * as the earlier sodium-based version.
 *
 * Uses openssl instead of sodium deliberately: openssl is close to
 * universally available on PHP hosting (WordPress itself depends on it
 * for HTTPS/SSL), whereas sodium — despite being bundled in PHP core
 * since 7.2 — gets selectively disabled by some hosts. This matters if
 * this code is meant to run on hosting environments you don't control.
 *
 * KEY MANAGEMENT: the key lives in config.php (CHAT_ENCRYPTION_KEY), same
 * server as the app, NOT in the database. This protects against database-only
 * exposure (leaked backups, SQL injection, exposed phpMyAdmin) — it does NOT
 * protect against full server compromise, since the key sits right next to
 * the code that uses it. That's a real, meaningful boundary, just not an
 * absolute one.
 */
class MessageCipher {
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32; // 256 bits
    private const IV_BYTES = 12;  // recommended IV length for GCM
    private const TAG_BYTES = 16; // standard GCM auth tag length

    private static function getKey(): string {
        $b64Key = getenv('CHAT_ENCRYPTION_KEY');
        if (!$b64Key || $b64Key === 'GENERATE_YOUR_OWN_KEY_SEE_README') {
            throw new RuntimeException(
                'CHAT_ENCRYPTION_KEY is not set. Generate one with: openssl rand -base64 32 ' .
                '(via SSH, or api/db/generate_key.php) and put it in config.php before storing any messages.'
            );
        }
        $key = base64_decode($b64Key, true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('CHAT_ENCRYPTION_KEY is malformed — regenerate it (openssl rand -base64 32).');
        }
        return $key;
    }

    public static function encrypt(string $plaintext): string {
        $key = self::getKey();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a stored value. If it doesn't decrypt (e.g. it's an old,
     * never-encrypted row from before this feature existed, or was
     * encrypted with a since-changed key), it's returned as-is rather
     * than throwing — this makes encryption apply going forward without
     * breaking access to existing history.
     */
    public static function decrypt(string $stored): string {
        $key = self::getKey();
        $raw = base64_decode($stored, true);
        $minLength = self::IV_BYTES + self::TAG_BYTES;
        if ($raw === false || strlen($raw) <= $minLength) {
            return $stored; // not a valid encrypted blob — treat as legacy plaintext
        }
        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? $stored : $plaintext;
    }

    /** Used by the migration script to check a row without needing the plaintext back. */
    public static function isValidCiphertext(string $stored): bool {
        $key = self::getKey();
        $raw = base64_decode($stored, true);
        $minLength = self::IV_BYTES + self::TAG_BYTES;
        if ($raw === false || strlen($raw) <= $minLength) {
            return false;
        }
        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);
        return openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag) !== false;
    }
}
