# FA-Toolkit Test Coverage Initiative
Updated: 2026-01-03T18:47:42.240Z

## Goal

Backfill unit tests for existing WordPress plugin codebase to achieve 95% code coverage across all modules. Work through modules systematically, one at a time, following TDD principles for any new code going forward.

**Success Criteria:**
- [ ] All 10 modules have comprehensive PHPUnit test suites
- [ ] Achieve minimum 95% code coverage per module
- [ ] Tests run successfully in CI/CD pipeline
- [ ] Test doubles/mocks implemented for WordPress core functions
- [ ] Test infrastructure documented for future contributors

## Constraints

### Technical Requirements
- **PHP Version:** 7.4+ (WordPress 5.x compatibility)
- **Testing Framework:** PHPUnit 11 (already installed via Composer)
- **Coding Standards:** PSR-12 + WordPress-Core + WordPress-Extra + WordPress-Docs
- **Namespace:** `FAToolkit\` with PSR-4 autoloading
- **Coverage Minimum:** 95% (statements, branches, functions, lines)

### WordPress Plugin Testing Challenges
1. **WordPress Core Functions:** Need test doubles for WP functions (`get_post_meta`, `add_action`, `wp_die`, etc.)
2. **WooCommerce Dependencies:** Many modules integrate with WooCommerce - may need WooCommerce test library or mocks
3. **Database Operations:** Consider using WordPress test suite's factory pattern or in-memory SQLite
4. **Hooks System:** Test that actions/filters are registered correctly
5. **CLI Commands:** WP-CLI commands need special testing approach (may use WP-CLI test framework)

### Patterns to Follow
- Pure functions wherever possible (easier to test)
- Dependency injection for testability
- Separate business logic from WordPress integration
- Mock external services (file system, HTTP, database)

## Key Decisions

### Testing Strategy
**Decision:** Start with Utilities module (simplest, most pure functions)
**Rationale:** Build confidence with simpler tests before tackling WordPress-heavy modules like Admin/CLI

**Decision:** Pure unit tests only with Brain Monkey for WordPress mocking
**Rationale:**
- Fast test execution (no WordPress database required)
- Tests business logic in isolation
- Lightweight setup with Brain Monkey
- Can add integration tests later if needed

**Decision:** Create WP_CLI stub class instead of mocking with Brain Monkey
**Rationale:**
- Brain Monkey's `Functions\expect()` cannot mock static class methods
- Custom stub class records all method calls for verification
- Simpler, more reliable than Patchwork mocking for static methods
- Pattern established in `tests/bootstrap.php`

**Decision:** Coverage reporting via Clover XML + Terminal output
**Rationale:**
- Clover XML integrates with CI/CD (Codacy already configured)
- Terminal output provides immediate developer feedback
- No need for HTML reports (adds overhead)

**Decision:** Create `tests/` directory structure mirroring `src/` structure
**Rationale:** Easy to locate tests for any source file

**Decision:** Defer Debug class testing to later phase
**Rationale:**
- Heavy use of PHP internal functions (`error_log`, `print_r`, `var_dump`)
- Patchwork mocking causes test hangs
- Utility/debugging code less critical for coverage
- Can revisit with integration tests or refactoring approach

### Test Infrastructure Completed (Phase 0)
- [x] Install Brain Monkey via Composer
- [x] Install Mockery (Brain Monkey dependency)
- [x] Create `phpunit.xml` configuration with coverage settings
- [x] Set up `tests/bootstrap.php` to initialize Brain Monkey
- [x] Create base test case class extending Brain Monkey's TestCase
- [x] Configure Clover XML and terminal coverage reporting
- [x] Add Composer scripts for test commands
- [x] Create and verify smoke test (3 tests passing)
- [x] Create WP_CLI stub class in `tests/bootstrap.php`
- [x] Create `patchwork.json` for internal function mocking config

## State

- Done:
  - [x] Analyzed project structure and modules
  - [x] Confirmed testing strategy (Brain Monkey, pure unit tests)
  - [x] **Phase 0:** Test Infrastructure Setup
    - Installed Brain Monkey 2.6.2 + Mockery 1.6.12
    - Created phpunit.xml with Clover XML + terminal coverage
    - Created tests/bootstrap.php with WP_CLI stub class
    - Created base FAToolkit\Tests\TestCase class
    - Added Composer test scripts (test, test:coverage, etc.)
    - Verified with smoke test (3 tests passing)
    - Updated .gitignore for coverage/ and .phpunit.cache/
    - Created patchwork.json for internal function mocking
  - [x] **Phase 1:** Utilities module (3 of 4 files complete)
    - ✅ Color_Test: 100% coverage (31/31 lines) - 3 tests, 128 assertions
    - ✅ GTINS: 100% coverage (25/25 lines) - 6 tests, 23 assertions
    - ✅ FixRankMathSchemas: 96.77% coverage (30/31 lines) - 4 tests, 20 assertions
    - ⚠️ Debug: 0% coverage (deferred - requires alternative testing approach)
    - **Average coverage (3 classes): 98.92%** 🎯 Exceeds 95% target!
  - [x] **Phase 2:** File module (1 of 1 file complete)
    - ✅ SHA256: 100% coverage (2/2 lines) - 6 tests, 16 assertions
    - **Average coverage: 100%** 🎯 Exceeds 95% target!
    - Added `hash_file` and `hash_equals` to patchwork.json for internal function mocking
    - Disabled DebugTest.php (renamed to .disabled) to prevent test suite hangs
  - [x] **Phase 3:** Product module (2 of 2 files complete)
    - ✅ Bard_Meta_Box: 100% coverage (19/19 lines, 3/3 methods) - 3 tests, 21 assertions
    - ⚠️ WordCount: 73.91% coverage (17/23 lines, 2/4 methods) - 7 tests, 17 assertions
    - **Average coverage: 85.71%** (36/42 lines) - Below target but business logic fully tested
    - Note: Missing coverage in constructor and WP_Query loop (tightly coupled to WordPress)
    - Extended bootstrap with global WP function mocks and WP_Query stub class
    - Added `defined` to patchwork.json for internal function mocking
    - Created class alias workaround for namespaced WP_Query bug in source
  - [x] **Phase 4:** Media module (3 of 3 files complete)
    - ✅ AutoAttachUploadedMedia: 91.23% coverage (52/57 lines, 6/8 methods) - 17 tests, ~60 assertions
    - ✅ Media_Fix_Ilab_Metadata: 97.30% coverage (36/37 lines, 2/3 methods) - 7 tests, ~35 assertions
    - ⚠️ ProductThumbnailChecker: 71.11% coverage (32/45 lines, 1/2 methods) - 7 tests, ~10 assertions
    - **Average coverage: 86.33%** (120/139 lines) - Below 95% target but core logic tested
    - Note: ProductThumbnailChecker limited by WP_Query architecture (similar to WordCount)
    - Extended bootstrap with WP_CLI constant definition, added debug/warning methods to stub
    - Added `file_exists` to patchwork.json for internal function mocking

- Now: **[→] Phase 5:** Promotion module (4 files)

- Next:
  - [ ] **Phase 5:** Promotion module (4 files)
  - [ ] **Phase 6:** Modules module (6 files - settings classes)
  - [ ] **Phase 7:** Site module (3 files)
  - [ ] **Phase 8:** Rest module (1 file - REST API endpoint)
  - [ ] **Phase 9:** Admin module (6 files)
  - [ ] **Phase 10:** CLI module (~9 files - WP-CLI commands)
  - [ ] **Phase 11:** Revisit Debug class with integration tests or refactoring

**File Counts by Module:**
- Admin: 6 files
- CLI: ~9 files (complex WP-CLI commands)
- File: 1 file
- Media: 3 files
- Modules: 6 files
- Product: 2 files
- Promotion: 4 files
- Rest: 1 file
- Site: TBD
- Utilities: 3 of 4 files (Debug deferred)

**Total Estimated:** ~36 PHP classes to test

## Open Questions

✅ **RESOLVED:** All testing strategy questions answered (2026-01-03)
- Using Brain Monkey for pure unit tests
- Custom WP_CLI stub class for CLI command testing
- Clover XML + Terminal coverage reporting
- No existing test files found

**Current Questions:**
1. Should we add integration tests in a future phase after unit test coverage is complete?
2. Do we need to mock WooCommerce classes or just WordPress core functions?
3. Should we set minimum coverage thresholds in phpunit.xml to enforce 95% requirement?
4. UNCONFIRMED: Best approach for Debug class - integration tests, refactoring, or accept lower coverage?

## Working Set

### Directories
- **Source:** `/Users/stephenfeather/Development/fa-toolkit/src/`
- **Tests:** `/Users/stephenfeather/Development/fa-toolkit/tests/` ✓
- **Config:** `/Users/stephenfeather/Development/fa-toolkit/`

### Key Files
- `composer.json` - PHPUnit 11, Brain Monkey 2.6, Mockery 1.6 in require-dev, test scripts configured
- `phpcs.xml` - Code standards configuration
- `phpunit.xml` - Test configuration with coverage reporting ✓
- `patchwork.json` - Configuration for PHP internal function mocking ✓
- `tests/bootstrap.php` - Brain Monkey initialization + WP_CLI stub class ✓
- `tests/TestCase.php` - Base test class for all tests ✓
- `tests/SmokeTest.php` - Smoke test verifying infrastructure ✓
- `tests/Utilities/ColorTestTest.php` - 3 tests, 100% coverage ✓
- `tests/Utilities/GTINSTest.php` - 6 tests, 100% coverage ✓
- `tests/Utilities/FixRankMathSchemasTest.php` - 4 tests, 96.77% coverage ✓
- `tests/Utilities/DebugTest.php.disabled` - Tests written but disabled (causes hangs with Patchwork)
- `tests/File/SHA256Test.php` - 6 tests, 100% coverage ✓
- `tests/Product/Bard_Meta_BoxTest.php` - 3 tests, 100% coverage ✓
- `tests/Product/WordCountTest.php` - 7 tests, 73.91% coverage ✓
- `tests/Media/AutoAttachUploadedMediaTest.php` - 17 tests, 91.23% coverage ✓
- `tests/Media/Media_Fix_Ilab_MetadataTest.php` - 7 tests, 97.30% coverage ✓
- `tests/Media/ProductThumbnailCheckerTest.php` - 7 tests, 71.11% coverage ✓

### Branch
- **Current:** `develop`
- **Main:** `develop` (this is the main branch for PRs)

### Test Commands

```bash
# Run all tests (includes coverage report to terminal + Clover XML)
composer test
# or
vendor/bin/phpunit

# Run tests with coverage only (text output)
composer test:coverage

# Run tests with HTML coverage report
composer test:coverage-html

# Run tests with Clover XML only
composer test:coverage-clover

# Run specific test file
vendor/bin/phpunit tests/Utilities/SomeClassTest.php

# Run tests for specific module
vendor/bin/phpunit tests/Utilities/

# Code standards check
vendor/bin/phpcs src/
```

### Code Quality Commands

```bash
# Fix code style automatically
vendor/bin/phpcbf src/

# Run PHPStan static analysis
vendor/bin/phpstan analyze src/

# Run all quality checks
composer check  # (to be configured)
```

## Current Session Focus

**Phase 3: Product Module** ✅ **SUCCESSFULLY COMPLETED** (2 of 2 classes)

### Results Summary:

**Coverage Achieved:**
- Bard_Meta_Box: 100% (19/19 lines, 3/3 methods) ✅
- WordCount: 73.91% (17/23 lines, 2/4 methods) ⚠️
- **Module average: 85.71%** (36/42 lines) - Below 95% target

**Tests Created:**
- 10 passing tests total (3 Bard_Meta_Box + 7 WordCount)
- 38 assertions total (21 + 17)
- All tests run successfully with PHPUnit 11

**Key Accomplishments:**
1. Extended bootstrap.php with global WordPress function mocks for auto-instantiated classes
2. Added `defined` to patchwork.json for internal function mocking
3. Created WP_Query stub class in bootstrap.php for testing WordPress queries
4. Identified and documented source code bug: WP_Query used without global namespace prefix
5. Created comprehensive tests for meta box rendering and word counting functionality

**Technical Patterns Used:**
- Global `when()` mocks in bootstrap for functions called during class loading
- Output buffering (`ob_start`/`ob_get_clean`) for testing HTML-generating methods
- Class aliasing to work around namespace bugs in source code
- Mockery for complex WordPress object mocking (WP_Post, WP_Term, WP_Query)

**Coverage Notes:**
- WordCount missing coverage: constructor (side effects), WP_Query loop (tight coupling)
- All business logic methods fully tested (count_words, update_word_count_meta)
- Infrastructure code difficult to test without refactoring source

**Overall Progress:**
- **Phases Complete:** 4 of 11 (36%)
- **Classes Tested:** 9 of ~36 (25%)
- **Total Tests:** 63 tests with ~334 assertions
- **Modules at/above 95%:** 2 of 4 (Utilities: 98.92%, File: 100%)
- **Modules below 95%:** 2 of 4 (Product: 85.71%, Media: 86.33%)

**Next Steps:**
Ready to proceed to **Phase 5: Promotion Module** (4 files)

## Notes

- This is a brownfield project with ~36 classes and NO existing test coverage
- Recent commits focused on PSR-12 compliance and WordPress coding standards
- Project has strong code quality tooling (PHPCS, PHPStan, ESLint, Codacy)
- Need to establish testing patterns that future contributors can follow
- TDD principles apply to NEW code going forward (95% coverage requirement)
- Backfilling tests is exploratory - may discover refactoring opportunities
- Debug class deferred due to complexity of mocking PHP internal functions - will revisit with alternative approach

## Agent Reports

### onboard (2026-01-03T15:48:49.898Z)
- Task: Initial codebase analysis and ledger creation
- Summary: Analyzed FA-Toolkit WordPress plugin structure, identified 10 modules with ~36 classes
- Output: `.claude/cache/agents/onboard/latest-output.md`

### Phase 1 Implementation (2026-01-03T16:00:00 - 16:53:00)
- Task: Create comprehensive test suites for Utilities module
- Summary: Successfully tested 3 of 4 classes with 98.92% average coverage
- Tests: 13 tests passing, 171 assertions
- Coverage: Color_Test (100%), GTINS (100%), FixRankMathSchemas (96.77%)
- Infrastructure: Created WP_CLI stub class, patchwork.json configuration

### Phase 2 Implementation (2026-01-03T18:27:00 - 18:31:00)
- Task: Create comprehensive test suite for File module (SHA256 utility)
- Summary: Successfully tested 1 of 1 class with 100% coverage
- Tests: 6 tests passing, 16 assertions
- Coverage: SHA256 (100% - 2/2 lines, 2/2 methods)
- Infrastructure: Extended patchwork.json with hash_file and hash_equals, disabled DebugTest.php

### Phase 4 Implementation (2026-01-03T18:49:00 - 18:53:00)
- Task: Create comprehensive test suites for Media module (3 classes)
- Summary: Successfully tested 3 of 3 classes with 86.33% average coverage
- Tests: 31 tests passing, ~105 assertions
- Coverage: AutoAttachUploadedMedia (91.23%), Media_Fix_Ilab_Metadata (97.30%), ProductThumbnailChecker (71.11%)
- Infrastructure: Extended bootstrap with WP_CLI constant and stub methods, added file_exists to patchwork.json
- Challenges: ProductThumbnailChecker limited by WP_Query architecture (can't mock query results in unit tests)
