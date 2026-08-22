<?php
/**
 * Base test case for FA-Toolkit test suite.
 *
 * Extends Brain Monkey's TestCase to provide WordPress function mocking
 * capabilities for all test classes.
 *
 * @package FAToolkit\Tests
 */

namespace FAToolkit\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base TestCase class for all FA-Toolkit tests.
 *
 * This class sets up Brain Monkey for WordPress function mocking
 * and includes Mockery integration for PHPUnit.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Set up test environment before each test.
     *
     * Initializes Brain Monkey to enable WordPress function mocking.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    /**
     * Tear down test environment after each test.
     *
     * Cleans up Brain Monkey mocks and verifies all expectations were met.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
