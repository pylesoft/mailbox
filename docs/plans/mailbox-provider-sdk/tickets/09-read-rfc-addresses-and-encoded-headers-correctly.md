# 09: Read RFC addresses and encoded headers correctly

**What to build:** Preserve quoted/grouped addresses and international headers through the real provider mapping path.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 51, 52, 55, 92.

Replace lossy address splitting and header decoding at the real incoming-message mapping seam. Callers continue receiving package-owned values rather than parser-specific objects.

## Acceptance criteria

- [x] Parse quoted display names containing commas, comments, groups, empty groups and multiple mailbox lists without losing or inventing addresses.
- [x] Decode supported encoded names, subjects and header character sets while preserving usable sender and recipient data.
- [x] Report malformed, truncated or unsupported content explicitly instead of turning it into a valid empty value.
- [x] Use the approved inbound parser behind Mailbox's own interfaces; do not expose parser classes as the public message contract.
- [x] Recheck maintained versions, advisories, PHP requirements, lowest/latest resolution and production-only installation before adding required dependencies. The reviewed parser candidate is ^4.0.8; plan constraints are not installation evidence.
- [x] Keep existing DTO/resource consumer shapes compatible where safe and document parsing corrections and explicit limitations.

## Verification

- Feed realistic Gmail and Graph message/header payloads through actual SDK mapping with quoted/grouped/international fixtures and malformed negatives.
- Verify direct runtime dependency declarations and an isolated production-only install; development-only transitive packages are insufficient.
- Reuse existing mapping tests and assert consumer-visible values rather than parser internals.

## Integration notes

This slice does not choose recipient defaults or reply-all policy. Outbound Symfony Mime and explicit Egulias requirements are completed with the first submission slice.

## Comments

- 2026-10-29: Claimed by the ticket 09 implementation agent on `feat/mailbox-sdk-09`, after integrating `feat/mailbox-provider-sdk` at `7d673704`. Scope: inbound address/header parsing, existing DTO/resource mapping, focused HTTP-boundary regression tests, and the first approved parser dependency update. No outbound behavior or consumer changes.
- 2026-10-29: Implemented through real Gmail/Graph message resources, clients, token exchanges and mapping, with only external HTTP faked. Red-before-green reproductions cover comma-bearing names/comments/groups, encoded international headers, repeated recipient headers, malformed provider shapes, unfinished delimiters, missing addresses, invalid/truncated encoded words, unsupported charsets, invalid encoded UTF-8, unexpected newlines, singular sender overflow and bare-name recovery that would invent an address. Package DTO/resource shapes remain unchanged. Group names/comments remain in `raw`; groups flatten to recipient arrays. Missing optional fields remain optional; malformed content throws `MailboxException`. See [upgrade guidance](../../../upgrade-guide.md).
- 2026-10-29: Verified the [current parser API and PHP requirements](https://mail-mime-parser.org/) and resolved `zbateson/mail-mime-parser:^4.0.8` as a direct production requirement. The parser requires PHP >=8.1, below Mailbox's existing ^8.2 floor. Composer's security policy stayed enabled. A targeted `--with-all-dependencies --minimal-changes` update added six runtime packages and updated sixteen existing locked packages within existing constraints, including Guzzle 7.16.1/PSR7 2.13.2 and Laravel 13.39.0/Symfony security fixes. Main lock: parser 4.0.8, mb-wrapper 3.0.1, stream-decorators 3.0.2, PHP-DI 7.1.1. `composer validate --strict`, platform requirements, and locked audit pass; audit reports no advisories or abandoned packages.
- 2026-10-29: Excluded only PHP-DI versions below 6.3.0 after the first lowest resolution exposed abandoned SuperClosure and deprecated `getClass()` calls. Upstream 6.1 requires SuperClosure, 6.2.2 still uses `getClass()`, and [6.3.0 uses `getType()`](https://github.com/PHP-DI/PHP-DI/blob/6.3.0/src/Definition/Source/ReflectionBasedAutowiring.php). Compatible 6.x and 7.x remain allowed; no new direct PHP-DI, Symfony Mime or Egulias requirement was added.
- 2026-10-29: Isolated production receipts are retained under `C:/Users/hello/.codex/scratch/mailbox-sdk-implementation/`: `ticket09-production` installed the committed lock with `--no-dev` (79 runtime packages); `ticket09-latest` resolved a runtime-only manifest with no Testbench/Pest dependencies (71 packages, Illuminate 13.39.0, parser 4.0.8, PHP-DI 7.1.1, mb-wrapper 3.0.1, stream-decorators 3.0.2, Guzzle 7.16.1/PSR7 2.13.2); `ticket09-lowest` used `--prefer-lowest --prefer-stable` and Composer platform PHP 8.2.0 (70 packages, Illuminate 12.0.0, parser 4.0.8, PHP-DI 6.3.0, mb-wrapper 2.0.0, stream-decorators 2.1.0, Guzzle 7.15.2/PSR7 2.13.0). Each audit has no advisories or abandoned packages. Final-source encoded-subject/group/address mapping smoke checks pass with warnings converted to exceptions: production/latest on `C:/Users/hello/.config/herd/bin/php84/php.exe` (8.4.25), lowest on `C:/Users/hello/.config/herd/bin/php.backup-before-php84-junction-20260617-153123/php.exe` (8.3.15). PHP 8.2 is a solver-platform result, not an executed runtime result. Running the PHP 8.2 lowest graph on PHP 8.4 emits upstream implicit-nullability deprecations; this does not establish a warning-free cross-version matrix.
- 2026-10-29: Final focused mapping run: `php vendor/bin/pest tests/Feature/Infrastructure/ReceivedMessageMappingTest.php --parallel --processes=4 --compact` => 26 passed, 40 assertions. Tests use the inspected isolated Testbench SQLite memory/worker files, array cache and fake HTTP; no active application database or live provider was used. Local PHP 8.4 runs set `OPENSSL_CONF=C:/Users/hello/.config/herd/bin/php84/extras/ssl/openssl.cnf` for fake Gmail assertion key generation. Targeted PHPStan on the three changed source files, Pint dirty and `git diff --check` pass. Before the integration merge/final bare-name guard, the package suite passed 369 tests/1841 assertions; the coordinator owns the final integrated suite. Current integration `d9e2b882` is an ancestor after merge `301b9c4`; the task branch has no upstream. No push, PR, consumer modification or live-provider operation occurred.
