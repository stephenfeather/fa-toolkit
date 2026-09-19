<?php
/**
 * Tests for the pure _fa_attributes unpacker.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Attributes;

use FAToolkit\Attributes\AttributeUnpacker;
use FAToolkit\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #113, design in docs/design/attributes-unpack.md.
 *
 * Real cells are string-only today, so every non-string row of the type table
 * is exercised with a synthetic cell.
 */
class AttributeUnpackerTest extends TestCase {

	/**
	 * Unpack a cell onto a product with nothing on it, under the default rules.
	 *
	 * @param string $cell The raw cell.
	 * @return array
	 */
	private function unpack( $cell ) {
		return AttributeUnpacker::unpack( $cell, AttributeUnpacker::default_rules(), array(), array() );
	}

	/**
	 * A taxonomy entry as SSI writes it.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	private function taxonomy_entry( $taxonomy ) {
		return array(
			'name'         => $taxonomy,
			'value'        => '',
			'position'     => 0,
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 1,
		);
	}

	/**
	 * A local entry somebody else wrote.
	 *
	 * @param string $name     Label.
	 * @param string $value    Value.
	 * @param int    $position Position.
	 * @return array
	 */
	private function foreign_entry( $name, $value, $position = 0 ) {
		return array(
			'name'         => $name,
			'value'        => $value,
			'position'     => $position,
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 0,
		);
	}

	/**
	 * Cells that are not a JSON object.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function invalid_cells() {
		return array(
			'not json'        => array( '{"material":' ),
			'top-level list'  => array( '["a","b"]' ),
			'top-level text'  => array( '"steel"' ),
			'top-level null'  => array( 'null' ),
			'top-level true'  => array( 'true' ),
			'top-level digit' => array( '7' ),
		);
	}

	/**
	 * An invalid cell changes nothing and says so.
	 *
	 * @param string $cell The raw cell.
	 * @return void
	 */
	#[DataProvider( 'invalid_cells' )]
	public function test_invalid_cell_keeps_the_previous_state( $cell ) {
		$existing = array(
			'pa_caliber' => $this->taxonomy_entry( 'pa_caliber' ),
			'material'   => $this->foreign_entry( 'Material', 'Steel', 1 ),
		);

		$result = AttributeUnpacker::unpack( $cell, AttributeUnpacker::default_rules(), $existing, array( 'material' ) );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( $existing, $result['attributes'] );
		$this->assertSame( array( 'material' ), $result['sidecar'] );
	}

	/**
	 * An empty object is valid and clears everything the last pass wrote.
	 *
	 * @return void
	 */
	public function test_empty_object_clears_owned_entries() {
		$existing = array(
			'pa_caliber' => $this->taxonomy_entry( 'pa_caliber' ),
			'material'   => $this->foreign_entry( 'Material', 'Steel', 1 ),
		);

		$result = AttributeUnpacker::unpack( '{}', AttributeUnpacker::default_rules(), $existing, array( 'material' ) );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( array( 'pa_caliber' ), array_keys( $result['attributes'] ) );
		$this->assertSame( array(), $result['sidecar'] );
	}

	/**
	 * A blank cell is what a blank CSV cell becomes: "the vendor sent nothing".
	 *
	 * @return void
	 */
	public function test_blank_cell_clears_owned_entries() {
		$existing = array( 'material' => $this->foreign_entry( 'Material', 'Steel' ) );

		$result = AttributeUnpacker::unpack( '  ', AttributeUnpacker::default_rules(), $existing, array( 'material' ) );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( array(), $result['attributes'] );
		$this->assertSame( array(), $result['sidecar'] );
	}

	/**
	 * One key becomes one local attribute of the documented shape.
	 *
	 * @return void
	 */
	public function test_entry_shape() {
		$result = $this->unpack( '{"barrel_finish":"Matte Blued"}' );

		$this->assertSame(
			array(
				'barrel-finish' => array(
					'name'         => 'Barrel Finish',
					'value'        => 'Matte Blued',
					'position'     => 0,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 0,
				),
			),
			$result['attributes']
		);
		$this->assertSame( array( 'barrel-finish' ), $result['sidecar'] );
		$this->assertSame( 1, $result['counts']['written'] );
	}

	/**
	 * Every row of the type table.
	 *
	 * @return array<string, array{0:string,1:string|null}>
	 */
	public static function type_table() {
		return array(
			'string verbatim'        => array( '"Matte Blued"', 'Matte Blued' ),
			'string trimmed'         => array( '"  1:9\"  "', '1:9"' ),
			'Y flag'                 => array( '"Y"', 'Yes' ),
			'n flag lower'           => array( '"n"', 'No' ),
			'yes word'               => array( '"YES"', 'Yes' ),
			'false word'             => array( '"False"', 'No' ),
			'json true'              => array( 'true', 'Yes' ),
			'json false'             => array( 'false', 'No' ),
			'integer'                => array( '20', '20' ),
			'zero'                   => array( '0', '0' ),
			'float'                  => array( '1.5', '1.5' ),
			'numeric string as sent' => array( '"25.0000"', '25.0000' ),
			'pipe in a string'       => array( '"Black|Tan"', 'Black/Tan' ),
			'list of strings'        => array( '["Black","Tan"]', 'Black | Tan' ),
			'list with flags'        => array( '[true,"N",3]', 'Yes | No | 3' ),
			'list drops empties'     => array( '["Black",null,"  ","Tan"]', 'Black | Tan' ),
			'null'                   => array( 'null', null ),
			'empty string'           => array( '""', null ),
			'blank string'           => array( '"   "', null ),
			'empty list'             => array( '[]', null ),
			'list of nothing'        => array( '[null,""]', null ),
		);
	}

	/**
	 * Each JSON value becomes the documented attribute value, or no attribute.
	 *
	 * @param string      $json     The JSON value.
	 * @param string|null $expected The attribute value, or null when skipped.
	 * @return void
	 */
	#[DataProvider( 'type_table' )]
	public function test_type_table( $json, $expected ) {
		$result = $this->unpack( '{"feature":' . $json . '}' );

		$this->assertTrue( $result['valid'] );

		if ( null === $expected ) {
			$this->assertSame( array(), $result['attributes'] );
			$this->assertSame( array(), $result['sidecar'] );
			$this->assertSame( 1, $result['counts']['skipped_empty'] );
			return;
		}

		$this->assertSame( $expected, $result['attributes']['feature']['value'] );
	}

	/**
	 * Values the contract forbids.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function unsupported_values() {
		return array(
			'object'                => array( '{"a":1}' ),
			'empty object'          => array( '{}' ),
			'list holding a list'   => array( '["a",["b"]]' ),
			'list holding a object' => array( '["a",{"b":1}]' ),
		);
	}

	/**
	 * A nested structure is skipped and counted, not flattened.
	 *
	 * @param string $json The JSON value.
	 * @return void
	 */
	#[DataProvider( 'unsupported_values' )]
	public function test_nested_values_are_unsupported( $json ) {
		$result = $this->unpack( '{"feature":' . $json . ',"finish":"Blued"}' );

		$this->assertSame( array( 'finish' ), array_keys( $result['attributes'] ) );
		$this->assertSame( 1, $result['counts']['unsupported'] );
	}

	/**
	 * Labels come from the key unless the rules name one.
	 *
	 * @return void
	 */
	public function test_labels_and_slugs() {
		$rules           = AttributeUnpacker::default_rules();
		$rules['labels'] = array( 'ar_15_accessory' => 'AR-15 Accessory' );

		$result = AttributeUnpacker::unpack( '{"ar_15_accessory":"Y","rate_of_twist":"1:9"}', $rules, array(), array() );

		$this->assertSame( 'AR-15 Accessory', $result['attributes']['ar-15-accessory']['name'] );
		$this->assertSame( 'Rate Of Twist', $result['attributes']['rate-of-twist']['name'] );
	}

	/**
	 * Two keys labelled onto one slug: the first in key order keeps it.
	 *
	 * @return void
	 */
	public function test_two_keys_labelled_onto_one_slug() {
		$rules           = AttributeUnpacker::default_rules();
		$rules['labels'] = array( 'grain_weight' => 'Bullet Weight' );

		$result = AttributeUnpacker::unpack( '{"bullet_weight":"400 GRAINS","grain_weight":"400Gr"}', $rules, array(), array() );

		$this->assertSame( array( 'bullet-weight' ), $result['sidecar'] );
		$this->assertSame( '400 GRAINS', $result['attributes']['bullet-weight']['value'] );
		$this->assertSame( 1, $result['counts']['collisions'] );
	}

	/**
	 * A label override with nothing sluggable in it writes nothing.
	 *
	 * @return void
	 */
	public function test_label_with_no_slug_is_unsupported() {
		$rules           = AttributeUnpacker::default_rules();
		$rules['labels'] = array( 'finish' => '***' );

		$result = AttributeUnpacker::unpack( '{"finish":"Blued"}', $rules, array(), array() );

		$this->assertSame( array(), $result['attributes'] );
		$this->assertSame( 1, $result['counts']['unsupported'] );
	}

	/**
	 * The seeded denylist keeps internal keys off the storefront.
	 *
	 * @return void
	 */
	public function test_denylisted_keys_are_not_written() {
		$result = $this->unpack( '{"boxes_per_case":"25","finish":"Blued","rsr_subcategory":"Optics"}' );

		$this->assertSame( array( 'finish' ), array_keys( $result['attributes'] ) );
		$this->assertSame( 2, $result['counts']['denied'] );
	}

	/**
	 * The seed the design note lists, so a dropped entry is a visible change.
	 *
	 * @return void
	 */
	public function test_default_denylist_seed() {
		$this->assertSame(
			array( 'boxes_per_case', 'cans_per_case', 'packs_per_case', 'reticle_type', 'rsr_description', 'rsr_subcategory', 'units_per_case' ),
			AttributeUnpacker::default_rules()['denylist']
		);
	}

	/**
	 * A key yields to its pa_ twin only on a product that carries the twin.
	 *
	 * @return void
	 */
	public function test_pa_shadow_is_per_product() {
		$cell  = '{"bullet_type":"Flat Nose"}';
		$rules = AttributeUnpacker::default_rules();

		$with    = AttributeUnpacker::unpack( $cell, $rules, array( 'pa_bullet-type' => $this->taxonomy_entry( 'pa_bullet-type' ) ), array() );
		$without = AttributeUnpacker::unpack( $cell, $rules, array( 'pa_caliber' => $this->taxonomy_entry( 'pa_caliber' ) ), array() );

		$this->assertSame( array( 'pa_bullet-type' ), array_keys( $with['attributes'] ) );
		$this->assertSame( 1, $with['counts']['shadowed'] );
		$this->assertSame( array(), $with['sidecar'] );

		$this->assertSame( array( 'pa_caliber', 'bullet-type' ), array_keys( $without['attributes'] ) );
		$this->assertSame( 0, $without['counts']['shadowed'] );
	}

	/**
	 * A slug that becomes shadowed leaves the row and the sidecar in the same call.
	 *
	 * @return void
	 */
	public function test_newly_shadowed_slug_leaves_the_sidecar() {
		$rules = AttributeUnpacker::default_rules();
		$first = AttributeUnpacker::unpack( '{"bullet_type":"Flat Nose","finish":"Blued"}', $rules, array(), array() );

		$row    = array( 'pa_bullet-type' => $this->taxonomy_entry( 'pa_bullet-type' ) ) + $first['attributes'];
		$second = AttributeUnpacker::unpack( '{"bullet_type":"Flat Nose","finish":"Blued"}', $rules, $row, $first['sidecar'] );

		$this->assertSame( array( 'pa_bullet-type', 'finish' ), array_keys( $second['attributes'] ) );
		$this->assertSame( array( 'finish' ), $second['sidecar'] );
	}

	/**
	 * A local entry we do not own wins its slug and is left byte for byte.
	 *
	 * @return void
	 */
	public function test_collision_with_a_foreign_entry() {
		$foreign = $this->foreign_entry( 'Finish', 'Hand-written', 3 );

		$result = AttributeUnpacker::unpack( '{"finish":"Blued","material":"Steel"}', AttributeUnpacker::default_rules(), array( 'finish' => $foreign ), array() );

		$this->assertSame( $foreign, $result['attributes']['finish'] );
		$this->assertSame( 1, $result['counts']['collisions'] );
		$this->assertSame( array( 'material' ), $result['sidecar'] );
	}

	/**
	 * A key that left the JSON takes its entry with it.
	 *
	 * @return void
	 */
	public function test_vanished_key_is_removed() {
		$rules = AttributeUnpacker::default_rules();
		$first = AttributeUnpacker::unpack( '{"finish":"Blued","material":"Steel"}', $rules, array(), array() );

		$second = AttributeUnpacker::unpack( '{"material":"Steel"}', $rules, $first['attributes'], $first['sidecar'] );

		$this->assertSame( array( 'material' ), array_keys( $second['attributes'] ) );
		$this->assertSame( array( 'material' ), $second['sidecar'] );
	}

	/**
	 * Ownership is by slug: a hand edit under an owned slug is overwritten.
	 *
	 * @return void
	 */
	public function test_hand_edit_under_an_owned_slug_is_overwritten() {
		$rules = AttributeUnpacker::default_rules();
		$first = AttributeUnpacker::unpack( '{"finish":"Blued"}', $rules, array(), array() );

		$edited                    = $first['attributes'];
		$edited['finish']['value'] = 'Edited in wp-admin';

		$second = AttributeUnpacker::unpack( '{"finish":"Blued"}', $rules, $edited, $first['sidecar'] );

		$this->assertSame( 'Blued', $second['attributes']['finish']['value'] );
	}

	/**
	 * A taxonomy entry is never removed, even if a sidecar were to name it.
	 *
	 * @return void
	 */
	public function test_taxonomy_entry_survives_a_sidecar_that_names_it() {
		$existing = array( 'pa_caliber' => $this->taxonomy_entry( 'pa_caliber' ) );

		$result = AttributeUnpacker::unpack( '{}', AttributeUnpacker::default_rules(), $existing, array( 'pa_caliber' ) );

		$this->assertSame( $existing, $result['attributes'] );
	}

	/**
	 * Foreign entries keep their order and position; ours follow in key order.
	 *
	 * @return void
	 */
	public function test_positions_follow_the_last_foreign_entry() {
		$existing = array(
			'pa_caliber' => $this->taxonomy_entry( 'pa_caliber' ),
			'warranty'   => $this->foreign_entry( 'Warranty', 'Lifetime', 4 ),
		);

		$result = AttributeUnpacker::unpack( '{"material":"Steel","finish":"Blued"}', AttributeUnpacker::default_rules(), $existing, array() );

		$this->assertSame( array( 'pa_caliber', 'warranty', 'finish', 'material' ), array_keys( $result['attributes'] ) );
		$this->assertSame( 5, $result['attributes']['finish']['position'] );
		$this->assertSame( 6, $result['attributes']['material']['position'] );
		$this->assertSame( array( 'finish', 'material' ), $result['sidecar'] );
	}

	/**
	 * Feeding the output back in gives the same bytes.
	 *
	 * @return void
	 */
	public function test_idempotent() {
		$rules    = AttributeUnpacker::default_rules();
		$cell     = '{"swivel_studs":"Y","finish":"Blued","colors":["Black","Tan"],"boxes_per_case":"25"}';
		$existing = array(
			'pa_caliber' => $this->taxonomy_entry( 'pa_caliber' ),
			'warranty'   => $this->foreign_entry( 'Warranty', 'Lifetime', 1 ),
		);

		$first  = AttributeUnpacker::unpack( $cell, $rules, $existing, array() );
		$second = AttributeUnpacker::unpack( $cell, $rules, $first['attributes'], $first['sidecar'] );

		$this->assertSame( serialize( $first['attributes'] ), serialize( $second['attributes'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( $first['sidecar'], $second['sidecar'] );
	}

	/**
	 * Key order in the cell does not change the output.
	 *
	 * @return void
	 */
	public function test_output_does_not_depend_on_cell_key_order() {
		$sorted   = $this->unpack( '{"finish":"Blued","material":"Steel"}' );
		$unsorted = $this->unpack( '{"material":"Steel","finish":"Blued"}' );

		$this->assertSame( $sorted['attributes'], $unsorted['attributes'] );
	}

	/**
	 * The hash moves with every rule that changes output, and with nothing else.
	 *
	 * @return void
	 */
	public function test_ruleset_hash() {
		$rules = AttributeUnpacker::default_rules();
		$base  = AttributeUnpacker::ruleset_hash( $rules );

		$reordered             = $rules;
		$reordered['denylist'] = array_reverse( $rules['denylist'] );

		$denied               = $rules;
		$denied['denylist'][] = 'other_features';

		$labelled           = $rules;
		$labelled['labels'] = array( 'nij_level' => 'NIJ Level' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $base );
		$this->assertSame( $base, AttributeUnpacker::ruleset_hash( $reordered ) );
		$this->assertNotSame( $base, AttributeUnpacker::ruleset_hash( $denied ) );
		$this->assertNotSame( $base, AttributeUnpacker::ruleset_hash( $labelled ) );
	}
}
