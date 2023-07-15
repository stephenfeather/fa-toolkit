<?php
/**
 * Sets up WP All Import.
 *
 * @package FA-Toolkit
 * @since 1.0.4
 */

namespace FAToolkit\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * WP All Import Settings.
 */
class WPAllImportSettings {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'pmxi_after_xml_import', 'wpai_send_email', 10, 1 );
	}
	/**
	 * Calculates the retail price based on the cost, sales price, markup, map, and msrp.
	 *
	 * @package FA-Toolkit
	 * @since 1.0.4
	 *
	 * @param float $cost The cost of the product.
	 * @param float $sales_price The sales price of the product.
	 * @param float $markup The markup percentage.
	 * @param float $map The map price of the product.
	 * @param float $msrp The msrp of the product.
	 *
	 * @return float The calculated retail price.
	 */
	private function calculate_retail_price( $cost, $sales_price, $markup, $map, $msrp ) {
		// lint all our numeric variables.
		$cost        = preg_replace( '/[^0-9,.]/', '', $cost );
		$sales_price = preg_replace( '/[^0-9,.]/', '', $sales_price );
		$markup      = preg_replace( '/[^0-9,.]/', '', $markup );
		$map         = preg_replace( '/[^0-9,.]/', '', $map );
		$msrp        = preg_replace( '/[^0-9,.]/', '', $msrp );

		$retail_price = ( 0 !== $sales_price ) ? $sales_price : $cost; // Use sales price if provided and not zero, otherwise use cost.

		if ( $markup ) {
			$markup_amount = $retail_price * ( $markup / 100 ); // Calculate the markup amount based on the percentage.
			$retail_price += $markup_amount; // Add the markup amount to the retail price.
		}

		if ( $map ) {
			$retail_price = max( $retail_price, $map ); // Retail cannot be less than map price if it exists.
		}

		if ( $msrp ) {
			$retail_price = min( $retail_price, $msrp ); // Retail cannot be more than msrp if it exists.
		}

		$retail_price = round( $retail_price, 2 ); // Round the retail price to two decimal places.
		$retail_price = number_format( $retail_price, 2, '.', '' ); // Pad the retail price with zeroes if necessary.

		return $retail_price;
	}

	/**
	 * Sends an email with the import report.
	 *
	 * @package FA-Toolkit
	 * @since 1.0.4
	 *
	 * @param int $import_id The ID of the import.
	 *
	 * @return void
	 */
	private function send_import_report_email( $import_id ) {
		// Retrieve the last import run stats.
		global $wpdb;
		$table   = $wpdb->prefix . 'pmxi_imports';
		$prepare = $wpdb->prepare( 'SELECT * FROM %s WHERE `id` = %d', $table, $import_id );
		$data    = $wpdb->get_row( '%s', $prepare ); // phpcs:ignore
		if ( $data ) {

			$count    = $data->count;
			$imported = $data->imported;
			$created  = $data->created;
			$updated  = $data->updated;
			$skipped  = $data->skipped;
			$deleted  = $data->deleted;

		}

		// Destination email address.
		$to = 'sendto@example.com';

		// Email subject.
		$subject = 'Import ID: ' . $import_id . ' complete';

		// Email message.
		$body  = 'Import ID: ' . $import_id . ' has completed at ' . gmdate( 'Y-m-d H:m:s' ) . "\r\n" . 'File Records:' . $count . "\r\n" . 'Records Imported:' . $imported . "\r\n" . 'Records Created:' . $created;
		$body .= "\r\n" . 'Records Updated:' . $updated . "\r\n" . 'Records Skipped:' . $skipped . "\r\n" . 'Records Deleted:' . $deleted;

		// Send the email as HTML.
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Send via WordPress email.
		wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Accepts a string of values seperated by commas and returns a serialized array.
	 *
	 * @package FA-Toolkit
	 * @since 1.0.4
	 *
	 * @param string $value The string of values seperated by commas.
	 *
	 * @return string The serialized array.
	 */
	private function list_to_serialized( $value ) {

		// Split the list at the commas.
		$value = explode( ',', $value );

		// Return the serialized list.
		return serialize( $value ); // phpcs:ignore

	}


	/**
	 * Strip the # from the UPC code.
	 *
	 * @package FA-Toolkit
	 * @since 1.0.4
	 * @param string $upccode The UPC code.
	 *
	 * @return string The UPC code without the #.
	 */
	private function davidsons_upc_clean( $upccode ) {
		return str_replace( '#', '', $upccode );
	}

	/**
	 * Combine the quantity from NC and AZ.
	 *
	 * @package FA-Toolkit
	 * @since 1.0.4
	 * @param string $quantity_nc The quantity from NC.
	 * @param string $quantity_az The quantity from AZ.
	 *
	 * @return string The combined quantity.
	 */
	private function davidsons_quantity_combine( $quantity_nc, $quantity_az ) {
		if ( 'A*' === $quantity_nc ) {
			return 0;
		}
		if ( 'A*' === $quantity_az ) {
			return 0;
		}
		$new_quantity = ( floatval( $quantity_nc ) + floatval( $quantity_az ) );
		return $new_quantity;
	}

	/**
	 * Check if the item is allocated.
	 *
	 * @package FA-Toolkit
	 * @since 1.0.4
	 *
	 * @param string $quantityinstock The quantity in stock.
	 * @param string $allocated The allocated status.
	 *
	 * @return string The allocated status.
	 */
	private function cssi_check_stock( $quantityinstock, $allocated ) {
		if ( empty( $quantityinstock ) ) {
				return 0;
		} elseif ( 'Yes' === $allocated ) {
			return 0;
		} else {
			return $quantityinstock;
		}
	}

}


new WpAllImportSettings();
