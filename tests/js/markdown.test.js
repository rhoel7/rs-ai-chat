/**
 * Run with: node tests/js/markdown.test.js
 * No dependencies — plain Node assertions, runs anywhere Node does.
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

// Minimal DOM stand-in so escapeHtml() (which uses document.createElement)
// works outside a browser.
global.document = {
  createElement: () => {
    let _text = '';
    return {
      set textContent(v) { _text = v; },
      get textContent() { return _text; },
      get innerHTML() {
        return _text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
      }
    };
  }
};

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'chat.js'), 'utf8');
eval('function escapeHtml' + src.split('function escapeHtml')[1]);

let passed = 0, failed = 0;
function test(name, fn) {
  try {
    fn();
    console.log(`  PASS  ${name}`);
    passed++;
  } catch (e) {
    console.log(`  FAIL  ${name} — ${e.message}`);
    failed++;
  }
}

console.log('--- Markdown formatting ---');

test('bold converts to <strong>', () => {
  assert.ok(formatMarkdown('**bold**').includes('<strong>bold</strong>'));
});

test('italic converts to <em>', () => {
  assert.ok(formatMarkdown('*italic*').includes('<em>italic</em>'));
});

test('inline code converts to <code>', () => {
  assert.ok(formatMarkdown('`code`').includes('<code>code</code>'));
});

test('headings convert to bold', () => {
  assert.ok(formatMarkdown('### Heading').includes('<strong>Heading</strong>'));
});

test('bullets convert to bullet character', () => {
  assert.ok(formatMarkdown('- item').includes('• item'));
});

test('strikethrough converts to <s>', () => {
  assert.ok(formatMarkdown('~~old~~').includes('<s>old</s>'));
});

test('combined bold+italic does not break', () => {
  assert.ok(formatMarkdown('***both***').includes('<strong><em>both</em></strong>'));
});

test('markdown link with https becomes a real anchor', () => {
  const r = formatMarkdown('[text](https://example.com)');
  assert.ok(r.includes('<a href="https://example.com" target="_blank" rel="noopener noreferrer">text</a>'));
});

test('bare URL gets auto-linked', () => {
  const r = formatMarkdown('Visit https://example.com for more');
  assert.ok(r.includes('<a href="https://example.com"'));
});

test('trailing punctuation stays outside the auto-linked URL', () => {
  const r = formatMarkdown('Visit https://example.com. Thanks!');
  assert.ok(r.includes('</a>. Thanks!'));
  assert.ok(!r.includes('href="https://example.com."'));
});

console.log('\n--- Security: these must NEVER produce a live, executable tag ---');

test('script tag mixed with real markdown stays escaped', () => {
  const r = formatMarkdown("<script>alert('xss')</script> and **bold**");
  assert.ok(!r.includes('<script>'));
  assert.ok(r.includes('<strong>bold</strong>'));
});

test('javascript: scheme link never becomes a real anchor', () => {
  const r = formatMarkdown('[Click here](javascript:alert(document.cookie))');
  assert.ok(!r.includes('<a href="javascript:'));
});

test('data: scheme link never becomes a real anchor', () => {
  const r = formatMarkdown('[Click](data:text/html,<script>alert(1)</script>)');
  assert.ok(!r.includes('<a href="data:'));
});

test('bare javascript: text never becomes a link', () => {
  const r = formatMarkdown('javascript:alert(1) is not a valid URL');
  assert.ok(!r.includes('<a href'));
});

test('explicit link is not double-wrapped in nested anchors', () => {
  const r = formatMarkdown('[policy](https://example.com/policy)');
  assert.ok(!r.includes('<a href="https://example.com/policy" target="_blank" rel="noopener noreferrer"><a'));
});

console.log(`\n=========================`);
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
console.log(`=========================`);
process.exit(failed === 0 ? 0 : 1);
