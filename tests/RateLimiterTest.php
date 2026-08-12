<?php
require_once __DIR__ . '/../api/db/RateLimiter.php';
require_once __DIR__ . '/../api/db/Database.php';

/**
 * Requires api/config.php to point at a real (ideally disposable/test)
 * MySQL database with schema.sql and rate_limit_schema.sql already applied.
 * Unlike MessageCipherTest, rate limiting is inherently a database-backed
 * feature, there's no meaningful way to test it without a real DB.
 */
class RateLimiterTest {
    private function cleanIdentifier(string $id): void {
        $db = Database::connect();
        $stmt = $db->prepare("DELETE FROM rate_limit_log WHERE identifier = :id");
        $stmt->execute(['id' => $id]);
    }

    public function testAllowsRequestsUnderTheLimit(TestRunner $t): void {
        $id = 'test-under-' . bin2hex(random_bytes(4));
        $this->cleanIdentifier($id);
        $limiter = new RateLimiter();

        for ($i = 0; $i < 5; $i++) {
            $limiter->checkAndRecord($id, 'test', maxRequests: 10, windowSeconds: 60);
        }
        $t->assertTrue(true, 'Five requests under a limit of ten should not throw');
        $this->cleanIdentifier($id);
    }

    public function testBlocksRequestsOverTheLimit(TestRunner $t): void {
        $id = 'test-over-' . bin2hex(random_bytes(4));
        $this->cleanIdentifier($id);
        $limiter = new RateLimiter();

        $blocked = false;
        for ($i = 0; $i < 12; $i++) {
            try {
                $limiter->checkAndRecord($id, 'test', maxRequests: 10, windowSeconds: 60);
            } catch (RateLimitExceededException $e) {
                $blocked = true;
                break;
            }
        }
        $t->assertTrue($blocked, 'The 11th request against a limit of 10 should throw RateLimitExceededException');
        $this->cleanIdentifier($id);
    }

    public function testDifferentIdentifiersAreIndependent(TestRunner $t): void {
        $idA = 'test-a-' . bin2hex(random_bytes(4));
        $idB = 'test-b-' . bin2hex(random_bytes(4));
        $this->cleanIdentifier($idA);
        $this->cleanIdentifier($idB);
        $limiter = new RateLimiter();

        for ($i = 0; $i < 10; $i++) {
            $limiter->checkAndRecord($idA, 'test', maxRequests: 10, windowSeconds: 60);
        }
        // idA is now exhausted — idB should be completely unaffected
        $limiter->checkAndRecord($idB, 'test', maxRequests: 10, windowSeconds: 60);
        $t->assertTrue(true, 'A different identifier should not be blocked by another identifier exhausting its limit');

        $this->cleanIdentifier($idA);
        $this->cleanIdentifier($idB);
    }

    public function testDifferentScopesAreIndependent(TestRunner $t): void {
        $id = 'test-scope-' . bin2hex(random_bytes(4));
        $this->cleanIdentifier($id);
        $limiter = new RateLimiter();

        for ($i = 0; $i < 10; $i++) {
            $limiter->checkAndRecord($id, 'scope-a', maxRequests: 10, windowSeconds: 60);
        }
        // same identifier, different scope — should not be affected by scope-a being exhausted
        $limiter->checkAndRecord($id, 'scope-b', maxRequests: 10, windowSeconds: 60);
        $t->assertTrue(true, 'The same identifier under a different scope should have an independent limit');
        $this->cleanIdentifier($id);
    }
}
