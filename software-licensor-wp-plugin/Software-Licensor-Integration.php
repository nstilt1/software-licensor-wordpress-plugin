<?php
/**
 * Plugin Name: Software Licensor Integration
 * Plugin URI: https://www.softwarelicensor.com
 * Description: A plugin for selling software licenses through Software Licensor
 * Author: Noah Stiltner
 * Author URI: https://www.hyperformancesolutions.com
 * Version: 1.0
 * Requires PHP: 8.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * Do not attempt to maliciously abuse the Software Licensor API. Doing so
 * could result in a ban.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';

require_once 'includes/protobufs/generated/Get_license_request/GetLicenseRequest.php';
require_once 'includes/protobufs/generated/Get_license_request/GetLicenseResponse.php';
require_once 'includes/protobufs/generated/Get_license_request/LicenseInfo.php';
require_once 'includes/protobufs/generated/Get_license_request/Machine.php';

require_once 'includes/protobufs/generated/Create_license_request/CreateLicenseRequest.php';
require_once 'includes/protobufs/generated/Create_license_request/CreateLicenseResponse.php';
require_once 'includes/protobufs/generated/Create_license_request/PerpetualLicense.php';
require_once 'includes/protobufs/generated/Create_license_request/ProductInfo.php';
require_once 'includes/protobufs/generated/Create_license_request/SubscriptionLicense.php';
require_once 'includes/protobufs/generated/Create_license_request/TrialLicense.php';

require_once 'includes/protobufs/generated/Create_product_request/CreateProductRequest.php';
require_once 'includes/protobufs/generated/Create_product_request/CreateProductResponse.php';

require_once 'includes/protobufs/generated/Deactivate_machines/DeactivateMachinesRequest.php';

require_once 'includes/protobufs/generated/Pubkeys/PubkeyRepo.php';
require_once 'includes/protobufs/generated/Pubkeys/ExpiringEcdhKey.php';
require_once 'includes/protobufs/generated/Pubkeys/PubkeyStorage.php';
require_once 'includes/protobufs/generated/Pubkeys/ExpiringEcdsaKey.php';

require_once 'includes/protobufs/generated/Regenerate_license_code/RegenerateLicenseCodeRequest.php';

require_once 'includes/protobufs/generated/Request/Request.php';
require_once 'includes/protobufs/generated/Response/Response.php';

require_once 'includes/protobufs/generated/Request/DecryptInfo.php';
require_once 'includes/protobufs/generated/Response/EcdhKey.php';

require_once 'includes/protobufs/generated/Register_store_request/RegisterStoreRequest.php';
require_once 'includes/protobufs/generated/Register_store_request/RegisterStoreResponse.php';
require_once 'includes/protobufs/generated/Register_store_request/Configs.php';
require_once 'includes/protobufs/generated/Register_store_request/RegisterStoreResponse.php';

require_once 'includes/utilities/db.php';
require_once 'includes/utilities/protobuf.php';
require_once 'includes/utilities/debug.php';

require_once 'includes/api/create_license.php';
require_once 'includes/api/create_product.php';
require_once 'includes/api/get_license.php';
require_once 'includes/api/regenerate_license.php';
require_once 'includes/api/register_store.php';
require_once 'includes/api/requests_and_responses.php';

if ( ! class_exists( 'WC_Software_Licensor' ) ) :
class WC_Software_licensor {
	/**
	* Construct the plugin.
	*/
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}
	/**
	* Initialize the plugin.
	*/
	public function init() {
		// Checks if WooCommerce is installed.
		if ( class_exists( 'WC_Integration' ) ) {
			// Include our integration class.
			include_once 'includes/class-wc-software-licensor-integration.php';
			// Register the integration.
			add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );
		} else {
			// throw an admin error if you like
		}
	}
	/**
	 * Add a new integration to WooCommerce.
	 */
	public function add_integration( $integrations ) {
		$integrations[] = 'WC_Software_Licensor_Integration';
		return $integrations;
	}
}
$WC_Software_Licensor = new WC_Software_Licensor( __FILE__ );
endif;