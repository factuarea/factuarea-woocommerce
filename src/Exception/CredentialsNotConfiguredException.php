<?php
/**
 * Thrown when the plugin is asked to work before it has been configured.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Exception;

/**
 * A setting the operation needs is empty.
 *
 * This is the normal state of a freshly activated plugin, so it is an
 * expectable condition rather than a defect: the merchant installs the plugin,
 * a sale happens, and nothing has been filled in yet. What must NOT happen is
 * that the event is dropped. The listener still records it in the outbox; it is
 * the delivery that cannot go out, and it goes out later, unchanged, once the
 * settings are there.
 *
 * The message names the SETTING and never its value - see PluginException.
 */
final class CredentialsNotConfiguredException extends \RuntimeException implements PluginException {

	/**
	 * The named setting has no value.
	 *
	 * @param string $setting_label Human-readable name of the setting, in English.
	 * @return self
	 */
	public static function missing( string $setting_label ): self {
		return new self(
			sprintf(
				'Factuarea is not configured yet: %s is empty, so the order could not be delivered. Fill it in under WooCommerce > Settings > Factuarea and run the connection test; the orders already recorded will be delivered on the next run.',
				$setting_label,
			),
		);
	}

	/**
	 * Several settings have no value.
	 *
	 * @param array<int, string> $setting_labels Human-readable names of the settings, in English.
	 * @return self
	 */
	public static function missing_several( array $setting_labels ): self {
		return new self(
			sprintf(
				'Factuarea is not configured yet: %s are empty, so the order could not be delivered. Fill them in under WooCommerce > Settings > Factuarea and run the connection test; the orders already recorded will be delivered on the next run.',
				implode( ', ', $setting_labels ),
			),
		);
	}
}
