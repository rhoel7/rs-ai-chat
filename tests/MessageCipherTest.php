<?php
require_once __DIR__ . '/../api/db/MessageCipher.php';

class MessageCipherTest {
    public function __construct() {
        // isolated key per test run — doesn't touch config.php or real data
        putenv('CHAT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    }

    public function testRoundTrip(TestRunner $t): void {
        $plaintext = "My name is Rhoel, my email is rhoel@example.com";
        $encrypted = MessageCipher::encrypt($plaintext);
        $decrypted = MessageCipher::decrypt($encrypted);
        $t->assertEquals($plaintext, $decrypted, 'Encrypted content should decrypt back to the original');
    }

    public function testCiphertextDoesNotContainPlaintext(TestRunner $t): void {
        $plaintext = "SENSITIVE_MARKER_STRING";
        $encrypted = MessageCipher::encrypt($plaintext);
        $t->assertStringNotContains($plaintext, $encrypted, 'Ciphertext must not contain the original plaintext anywhere');
    }

    public function testLegacyPlaintextFallback(TestRunner $t): void {
        $legacyPlaintext = "This is old plaintext from before encryption existed";
        $result = MessageCipher::decrypt($legacyPlaintext);
        $t->assertEquals($legacyPlaintext, $result, 'Non-encrypted legacy content should pass through unchanged, not error');
    }

    public function testTamperedCiphertextIsRejected(TestRunner $t): void {
        $plaintext = "original message";
        $encrypted = MessageCipher::encrypt($plaintext);
        $tampered = substr($encrypted, 0, -4) . 'XXXX';
        $result = MessageCipher::decrypt($tampered);
        $t->assertFalse($result === $plaintext, 'Tampered ciphertext must NOT decrypt to the real plaintext (authentication should fail)');
    }

    public function testIsValidCiphertextDetectsRealCiphertext(TestRunner $t): void {
        $encrypted = MessageCipher::encrypt("some content");
        $t->assertTrue(MessageCipher::isValidCiphertext($encrypted), 'A genuinely encrypted value should be recognized as valid ciphertext');
    }

    public function testIsValidCiphertextRejectsPlaintext(TestRunner $t): void {
        $t->assertFalse(MessageCipher::isValidCiphertext("just plain text"), 'Plain text should NOT be mistaken for valid ciphertext');
    }
}
