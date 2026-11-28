<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# plugin_yaml Performance Profile

This document records the algorithmic audit and PHP runtime benchmarks for `plugin_yaml` core hot paths (`YamlParser`, `YamlEmitter`, `FragmentMerger`, `CrossParameterValidator`, `HashRegistry`, `TemplateDecompiler`, `TemplateCompiler`, and `YamlService`).

## Benchmark Environment

- Runtime: PHP 8.6.0 CLI (NTS, Zend OPcache enabled) on macOS arm64
- Timing primitive: monotonic `hrtime(true)` (wall time in milliseconds)
- Memory primitive: `memory_get_peak_usage(true)` per isolated process

## Hotspots Identified and Fixed

1. **`FragmentMerger` multi-fragment `mergeByKey` ($O(F \times N) \to O(N)$)**
   - Previous behavior: each fragment merge called `array_merge($base, $incoming)` inside `detectNaturalKey()` and rebuilt the `$indexed` hash table and `$order` list over all accumulated `$base` items on every fragment.
   - Fix: introduced internal `IndexedList` accumulators keyed by `"\0" . $id` (preserving insertion order through PHP's ordered hash table while preventing numeric-string key coercion collisions like `"01"` vs `"1"`). Subsequent fragments merge only the incoming $M$ items in $O(M)$, and `materializeIndexedLists()` converts accumulators back to sequential lists once at the end of `mergeFragments()`. `detectNaturalKey()` now checks candidate keys present on the first element without copying arrays.

2. **`CrossParameterValidator` quadratic reference checks ($O(N \times M) \to O(N + M)$)**
   - Previous behavior: `in_array()` linear scans over `$declaredSites`, `$declaredPollers`, `$declaredTemplates`, `$declaredHosts`, `$seenPacks`, `$allFields`, `$inputFields`, and `$definedRefs` caused quadratic scaling on large fleets and large template bundles. Device SNMP errors were also merged with `$errors = array_merge($errors, ...)` inside the device loop.
   - Fix: converted all membership tables to `array<string, true>` hash sets with $O(1)$ `isset()` lookups and switched `validateDeviceSnmp()` to append directly to `&$errors`.

3. **`YamlParser` per-line allocations and PCRE overhead**
   - Previous behavior: stored 4-key associative arrays per line, scanned indentation with PHP byte loops, ran `findMappingColon()`, `stripInlineComment()`, `isBlockScalarHeader()`, and 4 numeric `preg_match()` calls on plain string scalars, concatenated flow tokens byte-by-byte, and recounted anchored subtrees on every alias dereference.
   - Fix: switched `$this->lines` to packed 4-element tuples (`[lineNo, indent, text, raw]`), added C-level `strspn()` / `strcspn()` fast paths for indentation and unquoted mapping colons, added short-circuit guards for comments, prefix tokens, block scalar headers, plain scalar first bytes, and escape-free double-quoted strings, switched `splitFlowItems()` to offset slicing, and cached node counts per anchor in `$this->anchorNodeCounts`.

4. **`YamlEmitter` intermediate join/split copies**
   - Previous behavior: recursively built per-container line arrays, joined them with `implode("\n", ...)`, and re-split them via `explode("\n", ...)` for mapping items inside sequences; searched `RESERVED_SCALARS` linearly via `in_array()`.
   - Fix: passed a shared `&$out` line buffer across recursive helpers (`renderValueInto`, `renderSequenceInto`, `renderMappingInto`), replaced `RESERVED_SCALARS` with an $O(1)$ hash map, and replaced `formatKey()` PCRE with `strspn()`.

5. **`TemplateDecompiler`, `TemplateCompiler`, `HashRegistry`, and `YamlService`**
   - Replaced linear `TYPE_CODES` and `SYSTEM_DATA_INPUTS` scans with $O(1)$ reverse lookup maps, added length/prefix fast guards in `HashRegistry::parseTag()` and `TemplateDecompiler::rewriteHashText()`, collapsed `TemplateDecompiler::extractDisplayName()` from 5 child-array passes to 1 direct DOM child pass, eliminated duplicate `elementChildren()` allocations in `convertChildren()`, and removed redundant `mergeSources()` in `YamlService::reload()` and duplicate `SchemaGenerator::generate()` in `YamlService::generateSchema()`.

## Measured Before / After Results

| Benchmark Workload | Before (ms) | After (ms) | Speedup | Peak Memory (Before -> After) |
| --- | ---: | ---: | ---: | --- |
| Parse 10,014-line YAML (338.8 KiB, 1,245 devices) | 33.32 | 17.21 | 1.94x | 10.00 MiB -> 10.00 MiB |
| Emit 10,014-line YAML (349.7 KiB) | 6.42 | 5.54 | 1.16x | 12.00 MiB -> 10.00 MiB |
| Merge 50 fragments with 2,000 keyed items (`mergeByKey`) | 16.28 | 7.12 | 2.29x | 10.00 MiB -> 12.00 MiB |
| Cross-parameter validate 2,000 devices + 2,000 tree items | 8.46 | 1.64 | 5.16x | 12.00 MiB -> 12.00 MiB |
| Decompile large Cacti template XML (379.6 KiB, 1,352 hash objects) | 22.35 | 16.65 | 1.34x | 8.00 MiB -> 8.00 MiB |
| Compile large Cacti template YAML (476.8 KiB, 1,352 hash objects) | 50.74 | 26.33 | 1.93x | 14.00 MiB -> 12.00 MiB |

## Complexity Summary

| Component | Target Complexity | Notes |
| --- | --- | --- |
| `YamlParser::parseDocument()` | $O(L + B)$ | Linear in lines $L$ and input bytes $B$; alias cost cached per anchor |
| `YamlEmitter::emit()` | $O(N)$ | Single shared line buffer; one final `implode("\n", $out)` |
| `FragmentMerger::mergeFragments()` | $O(\sum M_i)$ | Persistent `IndexedList` hash tables avoid per-fragment re-indexing |
| `CrossParameterValidator::validate()` | $O(N)$ | All cross-reference tables use hash-set `isset()` lookups |
| `TemplateDecompiler` / `TemplateCompiler` | $O(V + E)$ | Single-pass DOM traversal and $O(1)$ `HashRegistry` maps |
