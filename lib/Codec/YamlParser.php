<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_yaml, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_yaml
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

declare(strict_types=1);

namespace Somethingwithproof\Yaml\Codec;

/**
 * Pure-PHP hardened YAML 1.2 safe-subset parser.
 *
 * Enforces strict document size, nesting depth, mapping key count, and alias
 * expansion limits to prevent resource exhaustion (including YAML billion-laughs
 * payloads) and rejects arbitrary executable/object tags.
 */
final class YamlParser {
	/**
	 * Maximum accepted YAML input size in bytes (4 MiB).
	 */
	public const MAX_BYTES = 4194304;

	/**
	 * Maximum permitted container nesting depth.
	 */
	public const MAX_DEPTH = 32;

	/**
	 * Maximum permitted mapping keys across a single document.
	 */
	public const MAX_KEYS = 50000;

	/**
	 * Maximum alias-expanded node multiplier relative to literal source nodes.
	 */
	public const MAX_ALIAS_EXPANSION_RATIO = 10;

	/**
	 * Absolute ceiling on total parsed/expanded nodes to cap memory usage.
	 */
	public const MAX_TOTAL_NODES = 100000;

	/**
	 * Safe YAML 1.2 core schema tags accepted by the parser.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_TAGS = [
		'!!str',
		'!!int',
		'!!float',
		'!!bool',
		'!!null',
		'!!seq',
		'!!map',
		'tag:yaml.org,2002:str',
		'tag:yaml.org,2002:int',
		'tag:yaml.org,2002:float',
		'tag:yaml.org,2002:bool',
		'tag:yaml.org,2002:null',
		'tag:yaml.org,2002:seq',
		'tag:yaml.org,2002:map',
	];

	/**
	 * Preprocessed source lines with line numbers and indentation.
	 *
	 * @var list<array{line: int, indent: int, text: string, raw: string}>
	 */
	private array $lines = [];

	/**
	 * Current cursor position in $this->lines.
	 */
	private int $pos = 0;

	/**
	 * Total mapping keys parsed so far in the current document.
	 */
	private int $keyCount = 0;

	/**
	 * Literal nodes parsed directly from source lines.
	 */
	private int $literalNodes = 0;

	/**
	 * Additional nodes created through alias dereferencing.
	 */
	private int $expandedNodes = 0;

	/**
	 * Anchored values keyed by anchor name.
	 *
	 * @var array<string, mixed>
	 */
	private array $anchors = [];

	/**
	 * Maximum document bytes configured for this parser instance.
	 */
	private readonly int $maxBytes;

	/**
	 * Maximum nesting depth configured for this parser instance.
	 */
	private readonly int $maxDepth;

	/**
	 * Maximum mapping keys configured for this parser instance.
	 */
	private readonly int $maxKeys;

	/**
	 * Maximum alias expansion ratio configured for this parser instance.
	 */
	private readonly int $maxAliasExpansionRatio;

	/**
	 * Construct a hardened YAML 1.2 parser with optional custom limits.
	 *
	 * @param int $maxBytes Maximum input bytes.
	 * @param int $maxDepth Maximum container nesting depth.
	 * @param int $maxKeys Maximum mapping keys per document.
	 * @param int $maxAliasExpansionRatio Maximum expanded-to-literal node ratio.
	 */
	public function __construct(
		int $maxBytes = self::MAX_BYTES,
		int $maxDepth = self::MAX_DEPTH,
		int $maxKeys = self::MAX_KEYS,
		int $maxAliasExpansionRatio = self::MAX_ALIAS_EXPANSION_RATIO,
	) {
		$this->maxBytes               = $maxBytes;
		$this->maxDepth               = $maxDepth;
		$this->maxKeys                = $maxKeys;
		$this->maxAliasExpansionRatio = $maxAliasExpansionRatio;
	}

	/**
	 * Parse a YAML 1.2 string into native PHP arrays and scalars.
	 *
	 * @param string $input Raw YAML 1.2 document text.
	 * @return mixed Decoded PHP representation.
	 * @throws YamlException When syntax is invalid or security limits are exceeded.
	 */
	public static function parse(string $input): mixed {
		return (new self())->parseDocument($input);
	}

	/**
	 * Parse a YAML 1.2 string and assert that the root value is a mapping.
	 *
	 * @param string $input Raw YAML 1.2 document text.
	 * @return array<string, mixed> Decoded root mapping.
	 * @throws YamlException When parsing fails or the root is not a string-keyed mapping.
	 */
	public static function parseMap(string $input): array {
		$parsed = self::parse($input);

		if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
			throw new YamlException('YAML document root must be a mapping.');
		}

		$result = [];

		foreach ($parsed as $key => $value) {
			$result[(string) $key] = $value;
		}

		return $result;
	}

	/**
	 * Parse a YAML document using this parser's configured limits.
	 *
	 * @param string $input Raw YAML 1.2 document text.
	 * @return mixed Decoded PHP value.
	 * @throws YamlException When syntax or security constraints fail.
	 */
	public function parseDocument(string $input): mixed {
		if (strlen($input) > $this->maxBytes) {
			throw new YamlException(sprintf('YAML input exceeds maximum size of %d bytes.', $this->maxBytes));
		}

		if (str_contains($input, "\0")) {
			throw new YamlException('YAML input must not contain null bytes.');
		}

		// Strip optional UTF-8 BOM.
		if (str_starts_with($input, "\xEF\xBB\xBF")) {
			$input = substr($input, 3);
		}

		$this->lines         = [];
		$this->pos           = 0;
		$this->keyCount      = 0;
		$this->literalNodes  = 0;
		$this->expandedNodes = 0;
		$this->anchors       = [];

		$normalized = str_replace(["\r\n", "\r"], "\n", $input);
		$rawLines   = explode("\n", $normalized);
		$inDoc      = false;

		foreach ($rawLines as $idx => $rawLine) {
			$lineNo = $idx + 1;
			$trimmed = trim($rawLine);

			if ($trimmed === '' || str_starts_with($trimmed, '#')) {
				$this->lines[] = ['line' => $lineNo, 'indent' => -1, 'text' => '', 'raw' => $rawLine];

				continue;
			}

			if ($trimmed === '---' || str_starts_with($trimmed, '--- ')) {
				if ($inDoc && $this->hasContentLines()) {
					throw new YamlException(sprintf('Line %d: multi-document YAML streams are not permitted here.', $lineNo));
				}

				$inDoc         = true;
				$this->lines[] = ['line' => $lineNo, 'indent' => -1, 'text' => '', 'raw' => $rawLine];

				continue;
			}

			if ($trimmed === '...') {
				break;
			}

			if (str_starts_with($trimmed, '%YAML') || str_starts_with($trimmed, '%TAG')) {
				if (str_starts_with($trimmed, '%TAG')) {
					throw new YamlException(sprintf('Line %d: custom %%TAG directives are disallowed in safe YAML mode.', $lineNo));
				}

				$this->lines[] = ['line' => $lineNo, 'indent' => -1, 'text' => '', 'raw' => $rawLine];

				continue;
			}

			$indent = 0;
			$len    = strlen($rawLine);

			while ($indent < $len && $rawLine[$indent] === ' ') {
				$indent++;
			}

			if ($indent < $len && $rawLine[$indent] === "\t") {
				throw new YamlException(sprintf('Line %d: tabs are not allowed for YAML indentation.', $lineNo));
			}

			$inDoc         = true;
			$this->lines[] = [
				'line'   => $lineNo,
				'indent' => $indent,
				'text'   => substr($rawLine, $indent),
				'raw'    => $rawLine,
			];
		}

		$this->skipIgnorable();

		if ($this->pos >= count($this->lines)) {
			return [];
		}

		$first = $this->lines[$this->pos];
		$value = $this->parseNode($first['indent'], 0);
		$this->skipIgnorable();

		if ($this->pos < count($this->lines)) {
			$extra = $this->lines[$this->pos];

			throw new YamlException(sprintf('Line %d: unexpected trailing content "%s".', $extra['line'], $extra['text']));
		}

		return $value;
	}

	/**
	 * Determine whether any non-empty content lines have been buffered so far.
	 *
	 * @return bool
	 */
	private function hasContentLines(): bool {
		foreach ($this->lines as $entry) {
			if ($entry['indent'] >= 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Advance the cursor past blank and comment lines.
	 *
	 * @return void
	 */
	private function skipIgnorable(): void {
		$count = count($this->lines);

		while ($this->pos < $count && $this->lines[$this->pos]['indent'] < 0) {
			$this->pos++;
		}
	}

	/**
	 * Parse a block node starting at the current cursor.
	 *
	 * @param int $expectedIndent Expected indentation level of the node.
	 * @param int $depth Current nesting depth.
	 * @return mixed Decoded value.
	 * @throws YamlException On syntax or depth violations.
	 */
	private function parseNode(int $expectedIndent, int $depth): mixed {
		$this->assertDepth($depth);
		$this->skipIgnorable();

		if ($this->pos >= count($this->lines)) {
			return null;
		}

		$current = $this->lines[$this->pos];

		if ($current['indent'] < $expectedIndent) {
			return null;
		}

		$text = $current['text'];

		if ($text === '-' || str_starts_with($text, '- ')) {
			return $this->parseSequence($current['indent'], $depth);
		}

		$cleanText = $this->stripInlineComment($text);

		if (str_starts_with($cleanText, '[') || str_starts_with($cleanText, '{')) {
			$this->pos++;

			return $this->parseInlineValue($cleanText, $current['line'], $depth);
		}

		if ($this->findMappingColon($text) !== null) {
			return $this->parseMapping($current['indent'], $depth);
		}

		$this->pos++;

		return $this->parseInlineValue($cleanText, $current['line'], $depth);
	}

	/**
	 * Parse a YAML block sequence at the given indentation.
	 *
	 * @param int $indent Indentation column of the '-' indicators.
	 * @param int $depth Current nesting depth.
	 * @return list<mixed> Decoded sequence.
	 * @throws YamlException On syntax or resource limit violations.
	 */
	private function parseSequence(int $indent, int $depth): array {
		$this->assertDepth($depth);
		$items = [];
		$count = count($this->lines);

		while (true) {
			$this->skipIgnorable();

			if ($this->pos >= $count) {
				break;
			}

			$line = $this->lines[$this->pos];

			if ($line['indent'] < $indent) {
				break;
			}

			if ($line['indent'] > $indent) {
				throw new YamlException(sprintf('Line %d: invalid indentation inside sequence.', $line['line']));
			}

			$text = $line['text'];

			if ($text !== '-' && !str_starts_with($text, '- ')) {
				break;
			}

			$this->recordLiteralNode();
			$rest   = $text === '-' ? '' : ltrim(substr($text, 2), ' ');
			$anchor = null;
			$tag    = null;
			$rest   = $this->extractPrefixTokens($rest, $line['line'], $anchor, $tag);

			if ($rest === '' || str_starts_with($rest, '#')) {
				$this->pos++;
				$this->skipIgnorable();

				if ($this->pos < $count && $this->lines[$this->pos]['indent'] > $indent) {
					$item = $this->parseNode($this->lines[$this->pos]['indent'], $depth + 1);
				} else {
					$item = null;
				}
			} elseif ($this->isBlockScalarHeader($rest)) {
				$this->pos++;
				$item = $this->parseBlockScalar($rest, $indent, $line['line']);
			} elseif ($this->findMappingColon($rest) !== null && !str_starts_with($rest, '{') && !str_starts_with($rest, '[')) {
				// Inline mapping item after "- ", e.g. "- name: foo".
				$subIndent = $indent + 2;
				$this->lines[$this->pos] = [
					'line'   => $line['line'],
					'indent' => $subIndent,
					'text'   => $rest,
					'raw'    => str_repeat(' ', $subIndent) . $rest,
				];
				$item = $this->parseMapping($subIndent, $depth + 1);
			} else {
				$this->pos++;
				$item = $this->parseInlineValue($this->stripInlineComment($rest), $line['line'], $depth + 1);
			}

			$item = $this->applyTag($item, $tag, $line['line']);

			if ($anchor !== null) {
				$this->anchors[$anchor] = $item;
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Parse a YAML block mapping at the given indentation.
	 *
	 * @param int $indent Indentation column of the mapping keys.
	 * @param int $depth Current nesting depth.
	 * @return array<string, mixed> Decoded mapping.
	 * @throws YamlException On syntax, key-limit, or merge violations.
	 */
	private function parseMapping(int $indent, int $depth): array {
		$this->assertDepth($depth);
		$map   = [];
		$count = count($this->lines);

		while (true) {
			$this->skipIgnorable();

			if ($this->pos >= $count) {
				break;
			}

			$line = $this->lines[$this->pos];

			if ($line['indent'] < $indent) {
				break;
			}

			if ($line['indent'] > $indent) {
				throw new YamlException(sprintf('Line %d: unexpected indentation inside mapping.', $line['line']));
			}

			$text = $line['text'];

			if ($text === '-' || str_starts_with($text, '- ')) {
				break;
			}

			$colonPos = $this->findMappingColon($text);

			if ($colonPos === null) {
				throw new YamlException(sprintf('Line %d: expected "key: value" mapping entry.', $line['line']));
			}

			$rawKey = trim(substr($text, 0, $colonPos));
			$rest   = ltrim(substr($text, $colonPos + 1), ' ');
			$key    = $this->parseMappingKey($rawKey, $line['line']);

			$this->keyCount++;

			if ($this->keyCount > $this->maxKeys) {
				throw new YamlException(sprintf('YAML document exceeds maximum mapping key limit of %d.', $this->maxKeys));
			}

			$this->recordLiteralNode();

			$anchor = null;
			$tag    = null;
			$rest   = $this->extractPrefixTokens($rest, $line['line'], $anchor, $tag);

			if ($rest === '' || str_starts_with($rest, '#')) {
				$this->pos++;
				$this->skipIgnorable();

				if ($this->pos < $count) {
					$next = $this->lines[$this->pos];

					if ($next['indent'] > $indent) {
						$val = $this->parseNode($next['indent'], $depth + 1);
					} elseif ($next['indent'] === $indent && ($next['text'] === '-' || str_starts_with($next['text'], '- '))) {
						// YAML allows block sequence items at the same indent as the parent mapping key.
						$val = $this->parseSequence($indent, $depth + 1);
					} else {
						$val = null;
					}
				} else {
					$val = null;
				}
			} elseif ($this->isBlockScalarHeader($rest)) {
				$this->pos++;
				$val = $this->parseBlockScalar($rest, $indent, $line['line']);
			} else {
				$this->pos++;
				$val = $this->parseInlineValue($this->stripInlineComment($rest), $line['line'], $depth + 1);
			}

			$val = $this->applyTag($val, $tag, $line['line']);

			if ($anchor !== null) {
				$this->anchors[$anchor] = $val;
			}

			if ($key === '<<') {
				$map = $this->applyMergeKey($map, $val, $line['line']);
			} else {
				$map[$key] = $val;
			}
		}

		return $map;
	}

	/**
	 * Apply a YAML merge key (<<) onto the current mapping without overriding explicit keys.
	 *
	 * @param array<string, mixed> $map Current mapping accumulator.
	 * @param mixed $val Value referenced by the merge key.
	 * @param int $lineNo Source line number for error reporting.
	 * @return array<string, mixed> Merged mapping.
	 * @throws YamlException When the merge source is not a mapping or list of mappings.
	 */
	private function applyMergeKey(array $map, mixed $val, int $lineNo): array {
		$sources = [];

		if (is_array($val) && array_is_list($val)) {
			foreach ($val as $entry) {
				if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
					throw new YamlException(sprintf('Line %d: merge key "<<" sequence must contain only mappings.', $lineNo));
				}

				$sources[] = $entry;
			}
		} elseif (is_array($val)) {
			$sources[] = $val;
		} else {
			throw new YamlException(sprintf('Line %d: merge key "<<" requires a mapping or sequence of mappings.', $lineNo));
		}

		foreach ($sources as $src) {
			foreach ($src as $k => $v) {
				$kStr = (string) $k;

				if (!array_key_exists($kStr, $map)) {
					$map[$kStr] = $v;
				}
			}
		}

		return $map;
	}

	/**
	 * Parse a YAML literal (|) or folded (>) block scalar.
	 *
	 * @param string $header Block scalar indicator header (e.g. "|", "|-", ">+").
	 * @param int $parentIndent Indentation of the owning key or sequence item.
	 * @param int $lineNo Source line number of the header.
	 * @return string Decoded multiline string.
	 * @throws YamlException On invalid header syntax.
	 */
	private function parseBlockScalar(string $header, int $parentIndent, int $lineNo): string {
		$cleanHeader = trim($this->stripInlineComment($header));

		if (preg_match('/^([|>])([+-])?([1-9])?$/D', $cleanHeader, $matches) !== 1) {
			throw new YamlException(sprintf('Line %d: invalid block scalar header "%s".', $lineNo, $cleanHeader));
		}

		$style       = $matches[1];
		$chomping    = $matches[2] ?? '';
		$explicit    = isset($matches[3]) ? (int) $matches[3] : null;
		$blockIndent = $explicit !== null ? $parentIndent + $explicit : null;

		$collected = [];
		$count     = count($this->lines);

		while ($this->pos < $count) {
			$entry = $this->lines[$this->pos];
			$raw   = $entry['raw'];

			if (trim($raw) === '') {
				$collected[] = '';
				$this->pos++;

				continue;
			}

			$lineIndent = 0;
			$len        = strlen($raw);

			while ($lineIndent < $len && $raw[$lineIndent] === ' ') {
				$lineIndent++;
			}

			if ($lineIndent <= $parentIndent) {
				break;
			}

			if ($blockIndent === null) {
				$blockIndent = $lineIndent;
			}

			if ($lineIndent < $blockIndent) {
				break;
			}

			$collected[] = substr($raw, $blockIndent);
			$this->pos++;
		}

		$trailingEmpty = 0;

		while ($collected !== [] && $collected[count($collected) - 1] === '') {
			array_pop($collected);
			$trailingEmpty++;
		}

		if ($style === '|') {
			$result = implode("\n", $collected);
		} else {
			$result = $this->foldLines($collected);
		}

		if ($chomping === '-') {
			return $result;
		}

		if ($chomping === '+') {
			return $result . ($collected !== [] ? "\n" : '') . str_repeat("\n", $trailingEmpty);
		}

		return $result === '' ? '' : $result . "\n";
	}

	/**
	 * Fold non-empty lines according to YAML folded block scalar (>) rules.
	 *
	 * @param list<string> $lines Stripped content lines.
	 * @return string Folded text.
	 */
	private function foldLines(array $lines): string {
		$out   = '';
		$count = count($lines);

		for ($i = 0; $i < $count; $i++) {
			$cur = $lines[$i];

			if ($i === 0) {
				$out .= $cur;

				continue;
			}

			$prev = $lines[$i - 1];

			if ($cur === '') {
				$out .= "\n";
			} elseif ($prev === '') {
				$out .= $cur;
			} elseif (str_starts_with($cur, ' ') || str_starts_with($prev, ' ')) {
				$out .= "\n" . $cur;
			} else {
				$out .= ' ' . $cur;
			}
		}

		return $out;
	}

	/**
	 * Parse an inline scalar, alias, flow sequence, or flow mapping.
	 *
	 * @param string $value Trimmed inline YAML expression.
	 * @param int $lineNo Source line number.
	 * @param int $depth Current nesting depth.
	 * @return mixed Decoded value.
	 * @throws YamlException On invalid syntax, forbidden tags, or alias DoS violations.
	 */
	private function parseInlineValue(string $value, int $lineNo, int $depth): mixed {
		$this->assertDepth($depth);
		$trimmed = trim($value);

		if ($trimmed === '') {
			return null;
		}

		$anchor  = null;
		$tag     = null;
		$trimmed = $this->extractPrefixTokens($trimmed, $lineNo, $anchor, $tag);

		if ($trimmed === '') {
			$result = null;
		} elseif (str_starts_with($trimmed, '*')) {
			$result = $this->resolveAlias(substr($trimmed, 1), $lineNo);
		} elseif (str_starts_with($trimmed, '[')) {
			$result = $this->parseFlowSequence($trimmed, $lineNo, $depth);
		} elseif (str_starts_with($trimmed, '{')) {
			$result = $this->parseFlowMapping($trimmed, $lineNo, $depth);
		} elseif (str_starts_with($trimmed, '"')) {
			$result = $this->parseDoubleQuoted($trimmed, $lineNo);
		} elseif (str_starts_with($trimmed, "'")) {
			$result = $this->parseSingleQuoted($trimmed, $lineNo);
		} else {
			$result = $this->parsePlainScalar($trimmed);
		}

		$result = $this->applyTag($result, $tag, $lineNo);

		if ($anchor !== null) {
			$this->anchors[$anchor] = $result;
		}

		return $result;
	}

	/**
	 * Parse a YAML flow sequence (`[a, b, c]`).
	 *
	 * @param string $text Raw flow sequence string.
	 * @param int $lineNo Source line number.
	 * @param int $depth Current nesting depth.
	 * @return list<mixed> Decoded sequence.
	 * @throws YamlException On malformed brackets or depth violations.
	 */
	private function parseFlowSequence(string $text, int $lineNo, int $depth): array {
		$this->assertDepth($depth);

		if (!str_ends_with($text, ']')) {
			throw new YamlException(sprintf('Line %d: unterminated flow sequence.', $lineNo));
		}

		$inner = trim(substr($text, 1, -1));

		if ($inner === '') {
			return [];
		}

		$parts = $this->splitFlowItems($inner, $lineNo);
		$items = [];

		foreach ($parts as $part) {
			$this->recordLiteralNode();
			$items[] = $this->parseInlineValue($part, $lineNo, $depth + 1);
		}

		return $items;
	}

	/**
	 * Parse a YAML flow mapping (`{a: 1, b: 2}`).
	 *
	 * @param string $text Raw flow mapping string.
	 * @param int $lineNo Source line number.
	 * @param int $depth Current nesting depth.
	 * @return array<string, mixed> Decoded mapping.
	 * @throws YamlException On malformed braces, key limit, or syntax errors.
	 */
	private function parseFlowMapping(string $text, int $lineNo, int $depth): array {
		$this->assertDepth($depth);

		if (!str_ends_with($text, '}')) {
			throw new YamlException(sprintf('Line %d: unterminated flow mapping.', $lineNo));
		}

		$inner = trim(substr($text, 1, -1));

		if ($inner === '') {
			return [];
		}

		$parts = $this->splitFlowItems($inner, $lineNo);
		$map   = [];

		foreach ($parts as $part) {
			$colon = $this->findMappingColon($part);

			if ($colon === null) {
				throw new YamlException(sprintf('Line %d: invalid flow mapping entry "%s".', $lineNo, $part));
			}

			$this->keyCount++;

			if ($this->keyCount > $this->maxKeys) {
				throw new YamlException(sprintf('YAML document exceeds maximum mapping key limit of %d.', $this->maxKeys));
			}

			$this->recordLiteralNode();
			$key = $this->parseMappingKey(trim(substr($part, 0, $colon)), $lineNo);
			$val = $this->parseInlineValue(trim(substr($part, $colon + 1)), $lineNo, $depth + 1);

			if ($key === '<<') {
				$map = $this->applyMergeKey($map, $val, $lineNo);
			} else {
				$map[$key] = $val;
			}
		}

		return $map;
	}

	/**
	 * Split a flow container's top-level comma-separated elements while respecting
	 * nested brackets, braces, and quoted strings.
	 *
	 * @param string $inner Inner flow text excluding outer delimiters.
	 * @param int $lineNo Source line number.
	 * @return list<string> Individual item strings.
	 * @throws YamlException On unbalanced quotes or delimiters.
	 */
	private function splitFlowItems(string $inner, int $lineNo): array {
		$items   = [];
		$buf     = '';
		$len     = strlen($inner);
		$inSingle = false;
		$inDouble = false;
		$brackets = 0;
		$braces   = 0;

		for ($i = 0; $i < $len; $i++) {
			$ch = $inner[$i];

			if ($inSingle) {
				$buf .= $ch;

				if ($ch === "'") {
					if ($i + 1 < $len && $inner[$i + 1] === "'") {
						$buf .= "'";
						$i++;
					} else {
						$inSingle = false;
					}
				}

				continue;
			}

			if ($inDouble) {
				$buf .= $ch;

				if ($ch === '\\' && $i + 1 < $len) {
					$buf .= $inner[++$i];
				} elseif ($ch === '"') {
					$inDouble = false;
				}

				continue;
			}

			if ($ch === "'") {
				$inSingle = true;
				$buf .= $ch;
			} elseif ($ch === '"') {
				$inDouble = true;
				$buf .= $ch;
			} elseif ($ch === '[') {
				$brackets++;
				$buf .= $ch;
			} elseif ($ch === ']') {
				$brackets--;
				$buf .= $ch;
			} elseif ($ch === '{') {
				$braces++;
				$buf .= $ch;
			} elseif ($ch === '}') {
				$braces--;
				$buf .= $ch;
			} elseif ($ch === ',' && $brackets === 0 && $braces === 0) {
				$trimmed = trim($buf);

				if ($trimmed !== '') {
					$items[] = $trimmed;
				}

				$buf = '';
			} else {
				$buf .= $ch;
			}
		}

		if ($inSingle || $inDouble || $brackets !== 0 || $braces !== 0) {
			throw new YamlException(sprintf('Line %d: unbalanced quotes or delimiters in flow collection.', $lineNo));
		}

		$trimmed = trim($buf);

		if ($trimmed !== '') {
			$items[] = $trimmed;
		}

		return $items;
	}

	/**
	 * Extract leading anchor (&name) and tag (!tag / !!type) prefixes from a node value.
	 *
	 * @param string $text Raw value text.
	 * @param int $lineNo Source line number.
	 * @param ?string $anchor Populated anchor identifier if present.
	 * @param ?string $tag Populated safe YAML tag if present.
	 * @return string Remaining value expression after prefixes.
	 * @throws YamlException When an executable or non-safe YAML tag is encountered.
	 */
	private function extractPrefixTokens(string $text, int $lineNo, ?string &$anchor, ?string &$tag): string {
		$remaining = ltrim($text, ' ');

		while ($remaining !== '') {
			if (str_starts_with($remaining, '!')) {
				$space = strpos($remaining, ' ');
				$tok   = $space === false ? $remaining : substr($remaining, 0, $space);

				if (!in_array($tok, self::ALLOWED_TAGS, true)) {
					throw new YamlException(sprintf('Line %d: disallowed YAML tag "%s" rejected for security.', $lineNo, $tok));
				}

				$tag       = $tok;
				$remaining = $space === false ? '' : ltrim(substr($remaining, $space + 1), ' ');

				continue;
			}

			if (str_starts_with($remaining, '&')) {
				$space = strpos($remaining, ' ');
				$tok   = $space === false ? $remaining : substr($remaining, 0, $space);
				$name  = substr($tok, 1);

				if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) !== 1) {
					throw new YamlException(sprintf('Line %d: invalid YAML anchor name "%s".', $lineNo, $name));
				}

				$anchor    = $name;
				$remaining = $space === false ? '' : ltrim(substr($remaining, $space + 1), ' ');

				continue;
			}

			break;
		}

		return $remaining;
	}

	/**
	 * Resolve a YAML alias (*name) while enforcing billion-laughs expansion ceilings.
	 *
	 * @param string $name Anchor name referenced by alias.
	 * @param int $lineNo Source line number.
	 * @return mixed Cloned value from the anchor table.
	 * @throws YamlException When the alias is unknown or exceeds expansion budgets.
	 */
	private function resolveAlias(string $name, int $lineNo): mixed {
		$name = trim($name);

		if (!array_key_exists($name, $this->anchors)) {
			throw new YamlException(sprintf('Line %d: undefined YAML alias "*%s".', $lineNo, $name));
		}

		$value = $this->anchors[$name];
		$cost  = $this->countNodes($value, 0);
		$this->expandedNodes += $cost;

		$totalNodes = $this->literalNodes + $this->expandedNodes;
		$baseline   = max(1, $this->literalNodes);

		if ($totalNodes > self::MAX_TOTAL_NODES || ($this->expandedNodes > 50 && $this->expandedNodes > $baseline * $this->maxAliasExpansionRatio)) {
			throw new YamlException(sprintf('Line %d: YAML alias expansion limit exceeded (possible billion-laughs payload).', $lineNo));
		}

		return $value;
	}

	/**
	 * Count nodes inside an anchored value to bound alias expansion cost.
	 *
	 * @param mixed $value Anchored scalar or container.
	 * @param int $depth Current recursion depth.
	 * @return int Node count.
	 * @throws YamlException When expanded structure exceeds maximum depth or node ceiling.
	 */
	private function countNodes(mixed $value, int $depth): int {
		$this->assertDepth($depth);

		if (!is_array($value)) {
			return 1;
		}

		$count = 1;

		foreach ($value as $child) {
			$count += $this->countNodes($child, $depth + 1);

			if ($count > self::MAX_TOTAL_NODES) {
				throw new YamlException('YAML alias expansion exceeded maximum node count.');
			}
		}

		return $count;
	}

	/**
	 * Apply an allowlisted YAML 1.2 core tag conversion to a decoded value.
	 *
	 * @param mixed $value Decoded value.
	 * @param ?string $tag Optional core YAML tag.
	 * @param int $lineNo Source line number.
	 * @return mixed Converted value.
	 * @throws YamlException When value cannot satisfy the explicit tag.
	 */
	private function applyTag(mixed $value, ?string $tag, int $lineNo): mixed {
		if ($tag === null) {
			return $value;
		}

		$short = str_starts_with($tag, 'tag:yaml.org,2002:') ? '!!' . substr($tag, 18) : $tag;

		return match ($short) {
			'!!str' => is_scalar($value) ? (string) $value : ($value === null ? '' : throw new YamlException(sprintf('Line %d: cannot coerce non-scalar to !!str.', $lineNo))),
			'!!int' => is_int($value) ? $value : (is_scalar($value) && is_numeric((string) $value) ? (int) $value : throw new YamlException(sprintf('Line %d: invalid !!int value.', $lineNo))),
			'!!float' => is_float($value) || is_int($value) ? (float) $value : (is_scalar($value) && is_numeric((string) $value) ? (float) $value : throw new YamlException(sprintf('Line %d: invalid !!float value.', $lineNo))),
			'!!bool' => is_bool($value) ? $value : in_array(strtolower((string) (is_scalar($value) ? $value : '')), ['true', '1'], true),
			'!!null' => null,
			'!!seq' => is_array($value) && array_is_list($value) ? $value : throw new YamlException(sprintf('Line %d: !!seq requires a sequence.', $lineNo)),
			'!!map' => is_array($value) && ($value === [] || !array_is_list($value)) ? $value : throw new YamlException(sprintf('Line %d: !!map requires a mapping.', $lineNo)),
			default => throw new YamlException(sprintf('Line %d: disallowed YAML tag "%s".', $lineNo, $tag)),
		};
	}

	/**
	 * Parse a mapping key (quoted or plain) into a string.
	 *
	 * @param string $rawKey Raw key token before ':'.
	 * @param int $lineNo Source line number.
	 * @return string Decoded key string.
	 * @throws YamlException On invalid quoted key syntax.
	 */
	private function parseMappingKey(string $rawKey, int $lineNo): string {
		if ($rawKey === '') {
			throw new YamlException(sprintf('Line %d: empty YAML mapping key.', $lineNo));
		}

		if (str_starts_with($rawKey, '"')) {
			return $this->parseDoubleQuoted($rawKey, $lineNo);
		}

		if (str_starts_with($rawKey, "'")) {
			return $this->parseSingleQuoted($rawKey, $lineNo);
		}

		return $rawKey;
	}

	/**
	 * Parse a double-quoted YAML scalar with standard YAML 1.2 escapes.
	 *
	 * @param string $text Raw double-quoted token.
	 * @param int $lineNo Source line number.
	 * @return string Unescaped string.
	 * @throws YamlException When quotes are unterminated or escape sequences are invalid.
	 */
	private function parseDoubleQuoted(string $text, int $lineNo): string {
		if (strlen($text) < 2 || !str_ends_with($text, '"')) {
			throw new YamlException(sprintf('Line %d: unterminated double-quoted string.', $lineNo));
		}

		$inner = substr($text, 1, -1);
		$len   = strlen($inner);
		$out   = '';

		for ($i = 0; $i < $len; $i++) {
			$ch = $inner[$i];

			if ($ch !== '\\') {
				$out .= $ch;

				continue;
			}

			if ($i + 1 >= $len) {
				throw new YamlException(sprintf('Line %d: dangling escape in double-quoted string.', $lineNo));
			}

			$next = $inner[++$i];
			$out .= match ($next) {
				'n'  => "\n",
				'r'  => "\r",
				't'  => "\t",
				'"'  => '"',
				'\\' => '\\',
				'/'  => '/',
				' '  => ' ',
				'0'  => throw new YamlException(sprintf('Line %d: null byte escape is disallowed.', $lineNo)),
				'x'  => $this->decodeHexEscape($inner, $i, 2, $lineNo),
				'u'  => $this->decodeHexEscape($inner, $i, 4, $lineNo),
				default => throw new YamlException(sprintf('Line %d: unsupported escape sequence "\\%s".', $lineNo, $next)),
			};
		}

		return $out;
	}

	/**
	 * Decode a fixed-width hex or Unicode escape sequence inside a double-quoted string.
	 *
	 * @param string $inner Inner string contents.
	 * @param int $index Current cursor index (advanced past hex digits).
	 * @param int $digits Number of hex digits to consume (2 or 4).
	 * @param int $lineNo Source line number.
	 * @return string UTF-8 encoded character.
	 * @throws YamlException On invalid hex digits or null code points.
	 */
	private function decodeHexEscape(string $inner, int &$index, int $digits, int $lineNo): string {
		$hex = substr($inner, $index + 1, $digits);

		if (strlen($hex) !== $digits || preg_match('/^[0-9a-fA-F]+$/D', $hex) !== 1) {
			throw new YamlException(sprintf('Line %d: invalid hex escape sequence.', $lineNo));
		}

		$code = (int) hexdec($hex);

		if ($code === 0) {
			throw new YamlException(sprintf('Line %d: null code point escape is disallowed.', $lineNo));
		}

		$index += $digits;

		if ($digits === 2) {
			return chr($code);
		}

		$encoded = mb_chr($code, 'UTF-8');

		if ($encoded === false) {
			throw new YamlException(sprintf('Line %d: invalid Unicode code point \\u%s.', $lineNo, $hex));
		}

		return $encoded;
	}

	/**
	 * Parse a single-quoted YAML scalar where doubled single quotes escape a literal quote.
	 *
	 * @param string $text Raw single-quoted token.
	 * @param int $lineNo Source line number.
	 * @return string Decoded string.
	 * @throws YamlException When quotes are unterminated.
	 */
	private function parseSingleQuoted(string $text, int $lineNo): string {
		if (strlen($text) < 2 || !str_ends_with($text, "'")) {
			throw new YamlException(sprintf('Line %d: unterminated single-quoted string.', $lineNo));
		}

		return str_replace("''", "'", substr($text, 1, -1));
	}

	/**
	 * Parse an unquoted YAML 1.2 Core Schema scalar.
	 *
	 * @param string $text Trimmed unquoted token.
	 * @return mixed Decoded scalar (null, bool, int, float, or string).
	 */
	private function parsePlainScalar(string $text): mixed {
		if ($text === '~' || $text === 'null' || $text === 'Null' || $text === 'NULL') {
			return null;
		}

		if ($text === 'true' || $text === 'True' || $text === 'TRUE') {
			return true;
		}

		if ($text === 'false' || $text === 'False' || $text === 'FALSE') {
			return false;
		}

		if (preg_match('/^[+-]?(?:0|[1-9][0-9]*)$/D', $text) === 1) {
			return (int) $text;
		}

		if (preg_match('/^0x[0-9a-fA-F]+$/D', $text) === 1) {
			return (int) hexdec(substr($text, 2));
		}

		if (preg_match('/^0o[0-7]+$/D', $text) === 1) {
			return (int) octdec(substr($text, 2));
		}

		if (preg_match('/^[+-]?(?:[0-9]+\.[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $text) === 1) {
			return (float) $text;
		}

		return $text;
	}

	/**
	 * Locate the mapping key/value delimiter colon outside quotes and flow brackets.
	 *
	 * @param string $text Trimmed YAML line text.
	 * @return ?int Byte offset of the colon, or null if not a mapping entry.
	 */
	private function findMappingColon(string $text): ?int {
		$len      = strlen($text);
		$inSingle = false;
		$inDouble = false;
		$brackets = 0;
		$braces   = 0;

		for ($i = 0; $i < $len; $i++) {
			$ch = $text[$i];

			if ($inSingle) {
				if ($ch === "'") {
					if ($i + 1 < $len && $text[$i + 1] === "'") {
						$i++;
					} else {
						$inSingle = false;
					}
				}

				continue;
			}

			if ($inDouble) {
				if ($ch === '\\') {
					$i++;
				} elseif ($ch === '"') {
					$inDouble = false;
				}

				continue;
			}

			if ($ch === "'") {
				$inSingle = true;
			} elseif ($ch === '"') {
				$inDouble = true;
			} elseif ($ch === '[') {
				$brackets++;
			} elseif ($ch === ']') {
				$brackets--;
			} elseif ($ch === '{') {
				$braces++;
			} elseif ($ch === '}') {
				$braces--;
			} elseif ($ch === '#' && ($i === 0 || $text[$i - 1] === ' ')) {
				return null;
			} elseif ($ch === ':' && $brackets === 0 && $braces === 0) {
				if ($i + 1 === $len || $text[$i + 1] === ' ') {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Remove a trailing YAML comment while preserving '#' inside quotes.
	 *
	 * @param string $text Raw value text.
	 * @return string Text without trailing comments.
	 */
	private function stripInlineComment(string $text): string {
		$len      = strlen($text);
		$inSingle = false;
		$inDouble = false;

		for ($i = 0; $i < $len; $i++) {
			$ch = $text[$i];

			if ($inSingle) {
				if ($ch === "'") {
					if ($i + 1 < $len && $text[$i + 1] === "'") {
						$i++;
					} else {
						$inSingle = false;
					}
				}

				continue;
			}

			if ($inDouble) {
				if ($ch === '\\') {
					$i++;
				} elseif ($ch === '"') {
					$inDouble = false;
				}

				continue;
			}

			if ($ch === "'") {
				$inSingle = true;
			} elseif ($ch === '"') {
				$inDouble = true;
			} elseif ($ch === '#' && ($i === 0 || $text[$i - 1] === ' ')) {
				return rtrim(substr($text, 0, $i));
			}
		}

		return rtrim($text);
	}

	/**
	 * Check whether an expression starts a YAML block scalar header.
	 *
	 * @param string $text Trimmed value expression.
	 * @return bool
	 */
	private function isBlockScalarHeader(string $text): bool {
		$clean = trim($this->stripInlineComment($text));

		return preg_match('/^[|>][+-]?[1-9]?$/D', $clean) === 1;
	}

	/**
	 * Assert that the current nesting depth does not exceed $this->maxDepth.
	 *
	 * @param int $depth Current depth.
	 * @return void
	 * @throws YamlException When depth exceeds the configured ceiling.
	 */
	private function assertDepth(int $depth): void {
		if ($depth > $this->maxDepth) {
			throw new YamlException(sprintf('YAML nesting depth exceeds maximum limit of %d.', $this->maxDepth));
		}
	}

	/**
	 * Increment literal node counter and enforce global node ceiling.
	 *
	 * @return void
	 * @throws YamlException When total nodes exceed MAX_TOTAL_NODES.
	 */
	private function recordLiteralNode(): void {
		$this->literalNodes++;

		if (($this->literalNodes + $this->expandedNodes) > self::MAX_TOTAL_NODES) {
			throw new YamlException('YAML document exceeded maximum node budget.');
		}
	}
}
