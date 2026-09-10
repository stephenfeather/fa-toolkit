<?php
/**
 * Test double for the Advanced Custom Fields plugin's marker class.
 *
 * ACF is not installed on the site (issue #77). One legacy command,
 * ExportDraftProductImageSourcesCommand, still gates on `class_exists( 'acf' )`
 * before reading `image_source` through get_field(). Requiring this file lets
 * a test walk past that gate; get_field() itself is stubbed with Brain Monkey.
 *
 * Loaded on demand by the test that needs it, never by the bootstrap, so the
 * ACF-absent path stays testable in every other test.
 *
 * @package FA-Toolkit
 */

// phpcs:ignore Generic.Classes.DuplicateClassName.Found, Squiz.Classes.ClassFileName.NoMatch
class acf {} // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital
