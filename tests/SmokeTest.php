<?php
/**
 * Smoke test to verify test infrastructure is working correctly.
 *
 * @package FAToolkit\Tests
 */

namespace FAToolkit\Tests;

use Brain\Monkey\Functions;

/**
 * Smoke test class.
 *
 * This test verifies that:
 * - PHPUnit can find and run tests
 * - Brain Monkey is initialized correctly
 * - WordPress function mocking works
 */
class SmokeTest extends TestCase
{
    /**
     * Test that basic PHPUnit assertions work.
     *
     * @return void
     */
    public function test_phpunit_is_working(): void
    {
        $this->assertTrue(true);
        $this->assertEquals(2, 1 + 1);
    }

    /**
     * Test that Brain Monkey can mock WordPress functions.
     *
     * @return void
     */
    public function test_brain_monkey_can_mock_wordpress_functions(): void
    {
        // Mock a WordPress function.
        Functions\when('get_option')->justReturn('mocked_value');

        // Verify the mock works.
        $result = get_option('some_option');
        $this->assertEquals('mocked_value', $result);
    }

    /**
     * Test that Brain Monkey can verify function calls.
     *
     * @return void
     */
    public function test_brain_monkey_can_verify_function_calls(): void
    {
        // Expect a WordPress function to be called.
        Functions\expect('wp_die')
            ->once()
            ->with('Error message');

        // Call the function.
        wp_die('Error message');

        // Brain Monkey will verify expectations in tearDown.
    }
}
