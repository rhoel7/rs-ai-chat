<?php
/**
 * Run with: php tests/run.php
 *
 * MessageCipherTest needs no setup — it uses an isolated in-memory key.
 * RateLimiterTest, RagIngestorTest, and RagRetrieverTest all need
 * api/config.php pointing at a real test database with schema.sql,
 * rate_limit_schema.sql, and rag/schema.sql applied.
 */
require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/MessageCipherTest.php';
require_once __DIR__ . '/RateLimiterTest.php';
require_once __DIR__ . '/RagIngestorTest.php';
require_once __DIR__ . '/RagRetrieverTest.php';
require_once __DIR__ . '/SystemPromptTest.php';

$runner = new TestRunner();
$runner->run(MessageCipherTest::class);
$runner->run(RateLimiterTest::class);
$runner->run(RagIngestorTest::class);
$runner->run(RagRetrieverTest::class);
$runner->run(SystemPromptTest::class);

exit($runner->summary());
