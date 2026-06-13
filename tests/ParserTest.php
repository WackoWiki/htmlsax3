<?php

declare(strict_types=1);

namespace HTMLSax3\Tests;

use HTMLSax3\HTMLSax3;
use HTMLSax3\NullHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the HTMLSax3 parser covering:
 *  - basic open/close/data callbacks
 *  - attributes parsing (quoted, unquoted, single quotes)
 *  - self-closing tags
 *  - comments, CDATA, doctype escapes
 *  - processing instructions
 *  - JASP/ASP markup
 *  - all parser options (decorators)
 *  - edge cases (empty input, malformed tags, unicode)
 */
#[CoversClass(HTMLSax3::class)]
#[CoversClass(NullHandler::class)]
final class ParserTest extends TestCase
{
	// -----------------------------------------------------------------------
	// Basic parsing
	// -----------------------------------------------------------------------

	public function testParsesEmptyDocument(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '');

		self::assertSame([], $handler->events);
	}

	public function testParsesPlainTextWithoutTags(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, 'hello world');

		self::assertCount(1, $handler->events);
		self::assertEvent('data', 'hello world', $handler->events[0]);
	}

	public function testParsesSimpleElement(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<p>hello</p>');

		self::assertSame([
			['type' => 'open',  'tag' => 'p', 'attrs' => []],
			['type' => 'data',  'data' => 'hello'],
			['type' => 'close', 'tag' => 'p'],
		], $handler->events);
	}

	public function testParsesNestedElements(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<div><span><em>x</em></span></div>');

		self::assertSame([
			['type' => 'open',  'tag' => 'div',  'attrs' => []],
			['type' => 'open',  'tag' => 'span', 'attrs' => []],
			['type' => 'open',  'tag' => 'em',   'attrs' => []],
			['type' => 'data',  'data' => 'x'],
			['type' => 'close', 'tag' => 'em'],
			['type' => 'close', 'tag' => 'span'],
			['type' => 'close', 'tag' => 'div'],
		], $handler->events);
	}

	public function testParsesSiblingElements(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<p>a</p><p>b</p>');

		self::assertCount(5, $handler->events);
		self::assertEvent('open',  'p', $handler->events[0]);
		self::assertEvent('data',  'a', $handler->events[1]);
		self::assertEvent('close', 'p', $handler->events[2]);
		self::assertEvent('open',  'p', $handler->events[3]);
		self::assertEvent('data',  'b', $handler->events[4]);
	}

	// -----------------------------------------------------------------------
	// Attributes
	// -----------------------------------------------------------------------

	public function testParsesDoubleQuotedAttributes(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<a href="https://example.com" title="click">link</a>');

		self::assertCount(3, $handler->events);
		self::assertSame(
			['href' => 'https://example.com', 'title' => 'click'],
			$handler->events[0]['attrs'],
		);
	}

	public function testParsesSingleQuotedAttributes(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, "<a href='https://example.com'>link</a>");

		self::assertSame(['href' => 'https://example.com'], $handler->events[0]['attrs']);
	}

	public function testParsesUnquotedAttributes(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<a href=https://example.com title=click>link</a>');

		self::assertSame(
			['href' => 'https://example.com', 'title' => 'click'],
			$handler->events[0]['attrs'],
		);
	}

	public function testParsesValuelessAttributes(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<input disabled>');

		self::assertCount(1, $handler->events);
		self::assertSame(['disabled' => null], $handler->events[0]['attrs']);
	}

	public function testParsesMixedAttributes(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<img src="/a.png" alt="image" width=100 loading="lazy">');

		self::assertSame(
			[
				'src'     => '/a.png',
				'alt'     => 'image',
				'width'   => '100',
				'loading' => 'lazy',
			],
			$handler->events[0]['attrs'],
		);
	}

	public function testParsesAttributeWithSpacesInValue(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<div class="foo bar baz">x</div>');

		self::assertSame(['class' => 'foo bar baz'], $handler->events[0]['attrs']);
	}

	// -----------------------------------------------------------------------
	// Self-closing tags
	// -----------------------------------------------------------------------

	public function testSelfClosingTagFiresOpenAndClose(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<br/>');

		self::assertSame([
			['type' => 'open',  'tag' => 'br', 'attrs' => [], 'empty' => true],
			['type' => 'close', 'tag' => 'br', 'empty' => true],
		], $handler->events);
	}

	public function testSelfClosingTagWithSpaceBeforeSlash(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<br />');

		self::assertCount(2, $handler->events);
		self::assertSame('br', $handler->events[0]['tag']);
		self::assertTrue($handler->events[0]['empty']);
	}

	public function testSelfClosingTagWithAttributes(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<img src="/x.png" alt="x"/>');

		self::assertSame(
			['src' => '/x.png', 'alt' => 'x'],
			$handler->events[0]['attrs'],
		);
		self::assertTrue($handler->events[0]['empty']);
		self::assertTrue($handler->events[1]['empty']);
	}

	// -----------------------------------------------------------------------
	// Whitespace
	// -----------------------------------------------------------------------

	public function testPreservesLeadingWhitespace(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, "\n<p>x</p>");

		self::assertCount(3, $handler->events);
		self::assertEvent('data', "\n", $handler->events[0]);
	}

	public function testPreservesWhitespaceBetweenTags(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, "<p>a</p>\n\n<p>b</p>");

		self::assertCount(7, $handler->events);
		self::assertEvent('data', "\n\n", $handler->events[3]);
	}

	// -----------------------------------------------------------------------
	// Escapes (comments, CDATA, doctype)
	// -----------------------------------------------------------------------

	public function testParsesHtmlComment(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<p>x</p><!-- a comment --><p>y</p>');

		$events = $handler->events;
		self::assertEvent('close', 'p',  $events[2]);
		self::assertEvent('escape', '-- a comment ', $events[3]);
		self::assertEvent('open',   'p',  $events[4]);
	}

	public function testParsesEmptyComment(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, 'before<!--->after');

		$escapeEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'escape',
		));
		self::assertCount(1, $escapeEvents);
	}

	public function testParsesCdataSection(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<p><![CDATA[some <raw> content]]></p>');

		$escapeEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'escape',
		));
		self::assertCount(1, $escapeEvents);
		self::assertStringContainsString('some <raw> content', $escapeEvents[0]['data']);
	}

	public function testParsesDoctype(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<!DOCTYPE html><html><body>x</body></html>');

		$escapeEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'escape',
		));
		self::assertCount(1, $escapeEvents);
		self::assertStringContainsString('DOCTYPE', $escapeEvents[0]['data']);
	}

	// -----------------------------------------------------------------------
	// Processing instructions
	// -----------------------------------------------------------------------

	public function testParsesXmlProcessingInstruction(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<?xml version="1.0"?><root/>');

		$piEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'pi',
		));
		self::assertCount(1, $piEvents);
		self::assertSame('xml', $piEvents[0]['target']);
		self::assertStringContainsString('version="1.0"', $piEvents[0]['data']);
	}

	public function testParsesPhpProcessingInstruction(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, 'before<?php echo "x"; ?>after');

		$piEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'pi',
		));
		self::assertCount(1, $piEvents);
		self::assertSame('php', $piEvents[0]['target']);
		self::assertStringContainsString('echo "x";', $piEvents[0]['data']);
	}

	// -----------------------------------------------------------------------
	// JASP / ASP markup
	// -----------------------------------------------------------------------

	public function testParsesAspTag(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, 'before<% asp code %>after');

		$jaspEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'jasp',
		));
		self::assertCount(1, $jaspEvents);
		self::assertSame(' asp code ', $jaspEvents[0]['data']);
	}

	// -----------------------------------------------------------------------
	// Unicode / multibyte
	// -----------------------------------------------------------------------

	public function testParsesUnicodeContent(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<p>café ☕ 🦀</p>');

		self::assertEvent('data', 'café ☕ 🦀', $handler->events[1]);
	}

	public function testParsesUnicodeAttributeValue(): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, '<p title="日本語">x</p>');

		self::assertSame(['title' => '日本語'], $handler->events[0]['attrs']);
	}

	public function testParsesUtf8Bom(): void
	{
		$handler = $this->createCollectingHandler();
		$bom = "\xEF\xBB\xBF";
		$this->parseWith($handler, $bom . '<p>x</p>');

		self::assertEvent('open', 'p', $handler->events[1]);
	}

	// -----------------------------------------------------------------------
	// Parser options — Trim
	// -----------------------------------------------------------------------

	public function testTrimOptionTrimsWhitespace(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_option('XML_OPTION_TRIM_DATA_NODES');
		$parser->parse("<p>   hello   </p>");

		// The whitespace-only data between <p> and "hello" should be skipped,
		// and the trailing whitespace before </p> should be trimmed too.
		$dataEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'data',
		));
		self::assertCount(1, $dataEvents);
		self::assertSame('hello', $dataEvents[0]['data']);
	}

	// -----------------------------------------------------------------------
	// Parser options — Case folding
	// -----------------------------------------------------------------------

	public function testCaseFoldingConvertsTagsToUpper(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_option('XML_OPTION_CASE_FOLDING');
		$parser->parse('<p>x</p>');

		self::assertEvent('open',  'P', $handler->events[0]);
		self::assertEvent('close', 'P', $handler->events[2]);
	}

	public function testCaseFoldingLeavesAttributesAlone(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_option('XML_OPTION_CASE_FOLDING');
		$parser->parse('<a href="x">link</a>');

		self::assertSame('A', $handler->events[0]['tag']);
		self::assertSame(['href' => 'x'], $handler->events[0]['attrs']);
	}

	// -----------------------------------------------------------------------
	// Parser options — Linefeed break
	// -----------------------------------------------------------------------

	public function testLinefeedBreakSplitsDataOnNewlines(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_option('XML_OPTION_LINEFEED_BREAK');
		$parser->parse("line1\nline2\nline3");

		$dataEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'data',
		));
		self::assertSame(['line1', 'line2', 'line3'], array_column($dataEvents, 'data'));
	}

	// -----------------------------------------------------------------------
	// Parser options — Tab break
	// -----------------------------------------------------------------------

	public function testTabBreakSplitsDataOnTabs(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_option('XML_OPTION_TAB_BREAK');
		$parser->parse("col1\tcol2\tcol3");

		$dataEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'data',
		));
		self::assertSame(['col1', 'col2', 'col3'], array_column($dataEvents, 'data'));
	}

	// -----------------------------------------------------------------------
	// Parser options — Entities unparsed
	// -----------------------------------------------------------------------

	public function testEntitiesUnparsedSplitsOnEntitySyntax(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_option('XML_OPTION_ENTITIES_UNPARSED');
		$parser->parse('hello &amp; world &lt;tag&gt;');

		$dataEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'data',
		));

		self::assertGreaterThanOrEqual(3, count($dataEvents));
		self::assertContains('&amp;', array_column($dataEvents, 'data'));
		self::assertContains('&lt;', array_column($dataEvents, 'data'));
		self::assertContains('&gt;', array_column($dataEvents, 'data'));
	}

	// -----------------------------------------------------------------------
	// Parser options — Entities parsed
	// -----------------------------------------------------------------------

	public function testEntitiesParsedDecodesEntities(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_option('XML_OPTION_ENTITIES_PARSED');
		$parser->parse('hello &amp; world &lt;tag&gt;');

		$dataEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'data',
		));
		$reconstructed = implode('', array_column($dataEvents, 'data'));

		self::assertSame('hello & world <tag>', $reconstructed);
	}

	// -----------------------------------------------------------------------
	// Parser options — Strip escapes
	// -----------------------------------------------------------------------

	public function testStripEscapesRemovesCommentMarkers(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_escape_handler('escapeHandler');
		$parser->set_option('XML_OPTION_STRIP_ESCAPES');
		$parser->parse('<!-- stripped comment -->');

		$escapeEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'escape',
		));
		self::assertCount(1, $escapeEvents);
		self::assertSame(' stripped comment ', $escapeEvents[0]['data']);
		self::assertStringNotContainsString('--', $escapeEvents[0]['data']);
	}

	public function testStripEscapesRemovesCdataMarkers(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_escape_handler('escapeHandler');
		$parser->set_option('XML_OPTION_STRIP_ESCAPES');
		$parser->parse('<![CDATA[ raw content ]]>');

		$escapeEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'escape',
		));
		self::assertCount(1, $escapeEvents);
		self::assertStringNotContainsString('CDATA', $escapeEvents[0]['data']);
		self::assertStringNotContainsString('[', $escapeEvents[0]['data']);
		self::assertStringNotContainsString(']', $escapeEvents[0]['data']);
	}

	// -----------------------------------------------------------------------
	// Combined options
	// -----------------------------------------------------------------------

	public function testLinefeedAndTrimTogether(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_option('XML_OPTION_LINEFEED_BREAK');
		$parser->set_option('XML_OPTION_TRIM_DATA_NODES');
		$parser->parse("  line1  \n  line2  \n  line3  ");

		$dataEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'data',
		));
		self::assertSame(['line1', 'line2', 'line3'], array_column($dataEvents, 'data'));
	}

	public function testAllOptionsCombined(): void
	{
		$handler = $this->createCollectingHandler();
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_escape_handler('escapeHandler');
		$parser->set_pi_handler('piHandler');
		$parser->set_jasp_handler('jaspHandler');
		$parser->set_option('XML_OPTION_TRIM_DATA_NODES');
		$parser->set_option('XML_OPTION_CASE_FOLDING');
		$parser->set_option('XML_OPTION_LINEFEED_BREAK');
		$parser->set_option('XML_OPTION_TAB_BREAK');
		$parser->set_option('XML_OPTION_ENTITIES_PARSED');
		$parser->set_option('XML_OPTION_STRIP_ESCAPES');
		$parser->parse("<P>x\t&amp;\n<!-- comment --></P>");

		// Should not throw, should produce events.
		self::assertNotEmpty($handler->events);
	}

	// -----------------------------------------------------------------------
	// Cursor position tracking
	// -----------------------------------------------------------------------

	public function testGetCurrentPositionAdvancesDuringParsing(): void
	{
		$handler = new class extends NullHandler {
			public ?int $positionAtData = null;

			public function dataHandler(HTMLSax3 $parser, string $data): bool
			{
				$this->positionAtData = $parser->get_current_position();

				return true;
			}
		};

		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_data_handler('dataHandler');
		$parser->parse('hello world');

		self::assertNotNull($handler->positionAtData);
		self::assertSame(11, $handler->positionAtData);
	}

	public function testGetLengthReturnsDocumentLength(): void
	{
		$handler = new class extends NullHandler {
			public ?int $length = null;

			public function dataHandler(HTMLSax3 $parser, string $data): bool
			{
				$this->length = $parser->get_length();

				return true;
			}
		};

		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_data_handler('dataHandler');
		$parser->parse('1234567890');

		self::assertSame(10, $handler->length);
	}

	// -----------------------------------------------------------------------
	// Invalid option
	// -----------------------------------------------------------------------

	public function testSettingUnknownOptionThrows(): void
	{
		$parser = new HTMLSax3();

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('HTMLSax3::set_option(BOGUS_OPTION) illegal');

		$parser->set_option('BOGUS_OPTION');
	}

	// -----------------------------------------------------------------------
	// set_object validation
	// -----------------------------------------------------------------------

	public function testSetObjectWithNonObjectThrows(): void
	{
		$parser = new HTMLSax3();

		$this->expectException(\TypeError::class);

		/** @phpstan-ignore-next-line — intentional type violation for testing */
		$parser->set_object('not an object');
	}

	// -----------------------------------------------------------------------
	// Data providers
	// -----------------------------------------------------------------------

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function htmlFragmentProvider(): iterable
	{
		yield 'paragraph'         => ['<p>x</p>',                'p'];
		yield 'span'              => ['<span>x</span>',          'span'];
		yield 'div with class'    => ['<div class="a">x</div>',  'div'];
		yield 'image'             => ['<img src="x.png"/>',      'img'];
		yield 'line break'        => ['<br/>',                   'br'];
		yield 'horizontal rule'   => ['<hr/>',                   'hr'];
		yield 'unordered list'    => ['<ul><li>x</li></ul>',     'ul'];
		yield 'ordered list'      => ['<ol><li>x</li></ol>',     'ol'];
		yield 'table'             => ['<table><tr><td>x</td></tr></table>', 'table'];
		yield 'heading'           => ['<h1>x</h1>',              'h1'];
		yield 'link'              => ['<a href="x">link</a>',    'a'];
		yield 'bold'              => ['<b>x</b>',                'b'];
		yield 'italic'            => ['<i>x</i>',                'i'];
		yield 'code'              => ['<code>x</code>',          'code'];
	}

	#[DataProvider('htmlFragmentProvider')]
	public function testParsesCommonHtmlFragments(string $fragment, string $expectedTag): void
	{
		$handler = $this->createCollectingHandler();
		$this->parseWith($handler, $fragment);

		$openEvents = array_values(array_filter(
			$handler->events,
			static fn(array $e): bool => $e['type'] === 'open',
		));
		self::assertNotEmpty($openEvents);
		self::assertSame($expectedTag, $openEvents[0]['tag']);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Attaches the handler to a new parser with all standard channels wired up,
	 * then parses $input.
	 */
	private function parseWith(object $handler, string $input): void
	{
		$parser = new HTMLSax3();
		$parser->set_object($handler);
		$parser->set_element_handler('openHandler', 'closeHandler');
		$parser->set_data_handler('dataHandler');
		$parser->set_escape_handler('escapeHandler');
		$parser->set_pi_handler('piHandler');
		$parser->set_jasp_handler('jaspHandler');
		$parser->parse($input);
	}

	/**
	 * Builds a fresh handler that records every callback into an $events array
	 * for later assertions.
	 */
	private function createCollectingHandler(): object
	{
		return new class extends NullHandler {
			/** @var list<array<string, mixed>> */
			public array $events = [];

			public function openHandler(HTMLSax3 $parser, string $tag, array $attrs): bool
			{
				$this->events[] = [
					'type'  => 'open',
					'tag'   => $tag,
					'attrs' => $attrs,
					'empty' => false,
				];

				return true;
			}

			public function closeHandler(HTMLSax3 $parser, string $tag): bool
			{
				$this->events[] = [
					'type'  => 'close',
					'tag'   => $tag,
					'empty' => false,
				];

				return true;
			}

			public function dataHandler(HTMLSax3 $parser, string $data): bool
			{
				$this->events[] = [
					'type' => 'data',
					'data' => $data,
				];

				return true;
			}

			public function escapeHandler(HTMLSax3 $parser, string $data): bool
			{
				$this->events[] = [
					'type' => 'escape',
					'data' => $data,
				];

				return true;
			}

			public function piHandler(HTMLSax3 $parser, string $target, string $data): bool
			{
				$this->events[] = [
					'type'   => 'pi',
					'target' => $target,
					'data'   => $data,
				];

				return true;
			}

			public function jaspHandler(HTMLSax3 $parser, string $data): bool
			{
				$this->events[] = [
					'type' => 'jasp',
					'data' => $data,
				];

				return true;
			}
		};
	}

	private static function assertEvent(string $type, string $value, array $event): void
	{
		self::assertSame($type, $event['type'] ?? null);

		$key = match ($type)
		{
			'data'  => 'data',
			'pi'    => 'target',
			default => 'tag',
		};

		self::assertSame($value, $event[$key] ?? null);
	}
}