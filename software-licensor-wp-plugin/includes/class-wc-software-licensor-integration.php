<?php
/**
 * Software Licensor Integration.
 *
 * @package  WC_Software_Licensor_Integration
 * @category Integration
 * @author   Noah Stiltner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'WC_Software_Licensor_Integration' ) ) :
    class WC_Software_Licensor_Integration extends WC_Integration {
        private $private_key;
        private $company_id;
        private $share_customer_info;
        public $debug;
        /**
         * Init and hook in the integration.
         */
        public function __construct() {
            global $woocommerce;
            $this->id                 = 'software-licensor';
            $this->method_title       = __( 'Software Licensor', 'software-licensor' );
            $this->method_description = __( 'Integrate your store with Software Licensor', 'software-licensor' );
            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();
            // Define user set variables.
            $this->debug            = $this->get_option( 'debug' );
            // Actions.
            add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );

            add_action('woocommerce_check_cart_items', array($this, 'software_licensor_validate_cart'));
            add_action('woocommerce_payment_complete', 'software_licensor_create_license_request');
            
            // this was supposed to include the license code in an email to the user,
            // but it does not work
            //add_action('woocommerce_email_order_details', array($this, 'software_licensor_insert_license_code'), 10, 4);

            // authorized regenerate license action
            add_action('wp_ajax_software_licensor_regenerate_license', 'software_licensor_regenerate_license_request');
            // unauthorized regenerate license action
            add_action('wp_ajax_nopriv_software_licensor_regenerate_license', 'software_licensor_regenerate_license_request');
            add_shortcode('software_licensor_licenses_page', array($this, 'software_licensor_display_license'));

            // Save settings in admin if you are in the admin area.
            add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));

            add_action('admin_menu', array($this, 'software_licensor_admin_menus'));
            }

        function software_licensor_display_license() {
            software_licensor_error_log('Inside software_licensor_display_license');
            if (!wp_get_current_user()) {
                wp_die('You must be logged in to view your licenses.');
            }
            $license_data = software_licensor_get_license_info(wp_get_current_user());
            if ($license_data === false) {
                ob_start();
                echo '<p>No license data to display.</p>';
                return ob_get_clean();
            }
            $data = [];
            $license_code = $license_data->getLicenseCode();
            software_licensor_error_log('license code: ' . $license_code);
            //$licensed_products = new Get_license_request\LicenseInfo();
            $licensed_products = $license_data->getLicensedProducts();
            $iterator = $licensed_products->getIterator();

            //software_licensor_error_log('licensed_products obj: ' . print_r($licensed_products, true));
            $store_products = software_licensor_get_products_array();

            $counter = 1;
            foreach ($iterator as $product_id => $product_data) {
                $machines = [];
                $license_type = $product_data->getLicenseType();
                $expiration = $product_data->getExpirationOrRenewal();
                if (is_numeric($expiration) && $expiration > 0) {
                    $expiration = date("d M Y", $expiration);
                }
                $offline_machines = $product_data->getOfflineMachines();
                $online_machines = $product_data->getOnlineMachines();
                $machine_limit = $product_data->getMachineLimit();
                $machine_count = count($offline_machines) + count($online_machines);
                
                foreach ($offline_machines as $index => $m) {
                    array_push($machines, [
                        'id' => $m->getId(),
                        'os' => $m->getOs(),
                        'computer_name' => $m->getComputerName(),
                        'activation_type' => 'offline'
                    ]);
                }
                foreach ($online_machines as $index => $m) {
                    array_push($machines, [
                        'id' => $m->getId(),
                        'os' => $m->getOs(),
                        'computer_name' => $m->getComputerName(),
                        'activation_type' => 'online'
                    ]);
                }

                array_push($data, [
                    // the licensed product counter could be used for
                    // pagination when displaying the table
                    'index' => $counter,
                    'product_name' => $store_products[$product_id]['product_name'],
                    'license_type' => $license_type,
                    'expiration' => $expiration,
                    'machine_count' => $machine_count,
                    'machine_limit' => $machine_limit,
                    'machines' => $machines
                ]);
                $counter += 1;        
            }
            software_licensor_error_log('data: ' . json_encode($data));

            $output_html = '<div class="licenses">';
            $output_html .= '<div class="SL-license-code-header">License Code:</div>';
            $output_html .= '<div class="SL-license-code-container"><span class="SL-license-code">' . htmlspecialchars($license_code) . '</span></div>';
            $output_html .= '<table class="SL-licenses-table">';
            $output_html .= '<thead><tr>';

            $output_html .= '<th>Product</th>';
            $output_html .= '<th>License Type</th>';
            $output_html .= '<th>Expiration</th>';
            $output_html .= '<th>Machine Count</th>';

            $output_html .= '</tr></thead>';

            $output_html .= '<tbody>';

            foreach ($data as $index => $item) {
                $output_html .= '<tr class="SL-product-row" data-index="' . htmlspecialchars($item['index']) . '">';
                $output_html .= '<td>' . stripslashes(htmlspecialchars($item['product_name'])) . '</td>';
                $output_html .= '<td>' . htmlspecialchars($item['license_type']) . '</td>';
                $output_html .= '<td>' . htmlspecialchars($item['expiration']) . '</td>';
                $output_html .= '<td>' . htmlspecialchars($item['machine_count']) . '/' . htmlspecialchars($item['machine_limit']) . '</td>';
                $output_html .= '</tr>';

                $output_html .= '<tr class="machine-details" style="display:none;"><td colspan="4">';
                $output_html .= '<table class="machine-table">';
                
                $output_html .= '<thead><tr><th>Machine ID</th>';
                $output_html .= '<th>Computer Name</th>';
                $output_html .= '<th>OS</th>';
                $output_html .= '<th>Activation Type</th></tr></thead>';
                
                $output_html .= '<tbody>';
                foreach ($item['machines'] as $i => $machine) {
                    $output_html .= '<tr><td>' . htmlspecialchars($machine['id']) . '</td>';
                    $output_html .= '<td>' . htmlspecialchars($machine['computer_name']) . '</td>';
                    $output_html .= '<td>' . htmlspecialchars($machine['os']) . '</td>';
                    $output_html .= '<td>' . htmlspecialchars($machine['activation_type']) . '</td></tr>';
                }
                $output_html .= '</tr></tbody>';

                $output_html .= '</table>';
                $output_html .= '</td></tr>';
            }
            $output_html .= '</tbody>';

            $output_html .= '</table>';

            $output_html .= '<div class="SL-buttons-container">';
            $output_html .= '<button class="SL-regenerate-license-button" onclick="regenerateLicense()">Regenerate License</button>';
            $output_html .= '</div>';

            $output_html .= '<script>
                function regenerateLicense() {
                    fetch("' . admin_url('admin-ajax.php') . '?action=software_licensor_regenerate_license")
                    .then(response => response.text())
                    .then(data => alert(data));
                }
                // add event listener for expanding the machine tables
                document.addEventListener("DOMContentLoaded", function () {
                    let productRows = document.querySelectorAll(".SL-product-row");

                    productRows.forEach(function(row) {
                        row.addEventListener("click", function() {
                            let nextRow = this.nextElementSibling;
                            if (nextRow.style.display === "none") {
                                nextRow.style.display = "table-row";
                            } else {
                                nextRow.style.display = "none";
                            }
                        });
                    });
                });
            </script></div>';

            ob_start();

            echo $output_html;

            return ob_get_clean();
        }

        /**
         * Note: This code does not work at the moment. If you want it to work, 
         * you'll need to uncomment the `add_action('woocommerce_email_order_details', ...)
         * near the top of this file, or possibly change the hook/tag name.
         * 
         * Insert the license information into the email that is sent to the customer.
         * @param mixed $order the order object
         * @param mixed $admin
         * @param mixed $plain
         * @param mixed $email
         * @return void
         */
        /*
        function software_licensor_insert_license_code($order, $admin, $plain, $email) {
            $items = $order->get_items();
            $has_plugin = false;
            $names = array();
            foreach( $items as $item ) {
                if ( $item->get_meta( 'software_licensor_id', true ) ) {
                    $has_plugin = true;
                    array_push($names, $item->get_name());
                }
            }
            if ( $has_plugin ) {
                $license_obj = software_licensor_get_license_info($order->get_user());
                $license_code = $license_obj->getLicenseCode();

                $message = trim($this->get_option('email_message'));
                if (mb_substr($message, -1) != ':') {
                    $message .= ':';
                }
                ob_start();
                if ($this->get_option('include_software_names')) {
                    $name_list = $names . join(', ');

                    echo __("<strong>$message</strong><br><ul><li>$name_list<ul>$license_code</ul></li></ul>", 'software-licensor');

                }else{
                    echo __("<strong>$message</strong><br><ul><li>$license_code</li></ul>", 'software-licensor');
                }

                $output = ob_get_clean();
                echo $output;
            }
        }
        */

        /**
         * Ensure that there aren't duplicate items in the cart that could mess up the licensing
         * back end. If you want to interface with this API in another language, you must do the same.
         * @return void
         */
        function software_licensor_validate_cart() {
            $products_info = array();

            $owned_licenses = software_licensor_get_license_info(wp_get_current_user())->getLicensedProducts();

            // the following code only checks for duplicates and 
            // different license types for the same product
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                $product = $cart_item['data'];
                $quantity = $cart_item['quantity'];
                $price = WC()->cart->get_product_price( $product );
                $subtotal = WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] );

                $software_id = $product->get_attribute( 'software_licensor_id' );
                if ($software_id) {
                    // get license type
                    $variation_id = $cart_item['variation_id'];
                    if ($variation_id) {
                        $variation = new WC_Product_Variation($variation_id);
                        $license_type = $variation->get_attribute('license_type');
                        if (empty($license_type)) {
                            $license_type = $product->get_attribute('license_type');
                        }
                    } else {
                        $license_type = $product->get_attribute('license_type');
                    }

                    if (array_key_exists($software_id, $products_info)){
                        if ($subtotal > 0 || $products_info[$software_id]['subtotal'] > 0 || $license_type != $products_info[$software_id]['license_type']) {
                            wc_add_notice(sprintf('<strong>You must not purchase different license types for the same product.</strong>'), 'error');
                        }
                    } else {
                        $owned = $owned_licenses->offsetGet($software_id);
                        if ($owned) {
                            $owned_license_type = $owned->getLicenseType();
                            if ($license_type == "trial") {
                                wc_add_notice(sprintf("<strong>You cannot get a trial license for a product that you already have a license for.</strong>"), 'error');
                            } else if ($license_type == "subscription" && $owned_license_type == "perpetual") {
                                wc_add_notice(sprintf('<strong>You cannot own a subscription license if you already own a perpetual license for the same product.</strong>'), 'error');
                            }
                        }
                        $products_info[$software_id] = array(
                            "subtotal" => $subtotal,
                            "license_type" => $license_type
                        );
                    }
                }
            }
        }

        /**
         * Initialize integration settings form fields.
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'store_id_prefix' => array(
                    'title'             => __( 'Store ID Prefix', 'software-licensor' ),
                    'type'              => 'textarea',
                    'description'       => __( 'Enter your desired Store ID Prefix.', 'software-licensor' ),
                    'desc_tip'          => true,
                    'default'           => '',
                    'required'          => true,
                ),
                'email' => array(
                    'title' => __( 'Email', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your email address.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required'          => true,
                ),
                'first_name' => array(
                    'title' => __( 'First Name', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your first name.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                ),
                'last_name' => array(
                    'title' => __( 'Last Name', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your last name.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                ),
                'discord_username' => array(
                    'title' => __( 'Discord Username', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your Discord username.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                ),
                'offline_frequency_hours' => array(
                    'title' => __( 'Offline License Check-up Frequency (hours)', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your desired license check-up rate for offline licenses. Note that with offline machines, they may never check up.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                    'numeric' => true
                ),
                'perpetual_frequency_hours' => array(
                    'title' => __( 'Perpetual License Check-up Frequency (hours)', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your desired license check-up rate for perpetual licenses.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                    'numeric' => true
                ),
                'perpetual_expiration_days' => array(
                    'title' => __( 'Perpetual License Expiration (days)', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your desired perpetual license expiration period. The expiration period is renewed every time that a machine checks up with the service.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                    'numeric' => true
                ),
                'subscription_frequency_hours' => array(
                    'title' => __( 'Subscription License Check-up Frequency (hours)', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your desired license check-up rate for subscription licenses.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                    'numeric' => true
                ),
                'subscription_expiration_days' => array(
                    'title' => __( 'Subscription License Expiration (days)', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your desired subscription license expiration period. This only affects when the license expires on the client machines, not when their subscription period ends, so this value can be less than how long their subscription is for, and will require the client side code to check up with the service at least once during this period.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                    'numeric' => true
                ),
                'subscription_leniency_offset_hours' => array(
                    'title' => __( 'Subscription Period Leniency/Offset (hours)', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'This value will offset the overall expiration of a subscription period to attempt to counteract any delays in server communication. The client is not always going to be online, and servers might not always be on time. You never know when there might be an outage.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                    'numeric' => true
                ),
                'trial_frequency_hours' => array(
                    'title' => __( 'Trial License Check-up Frequency (hours)', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your desired license check-up rate for offline licenses.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                    'numeric' => true
                ),
                'trial_expiration_days' => array(
                    'title' => __( 'Trial License Expiration (days)', 'software-licensor' ),
                    'type' => 'text',
                    'description' => __( 'Enter your desired expiration period for trial licenses. This is the actual amount of days between the first activation and when the trial license will end. Yes, the timer doesn\'nt start until the user activates their license for the first time.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => '',
                    'required' => true,
                    'numeric' => true
                ),
                'share_customer_info' => array(
                    'title' => __( 'Share Customer Info', 'software-licensor' ),
                    'type' => 'checkbox',
                    'description' => __( 'Optionally share customer info with Software Licensor. Sharing this will enable the possibility of customer\'s contact information to be visible in the licensed software (you would also need to display it with your client side code). We do not sell or share personal customer information, and the information will be encrypted in transit and at rest. If you check this box, you will need to include a statement in your privacy policy that Software Licensor is one of the 3rd parties that you are sharing customer data with. The collected data primarily includes names and emails, and can also include computer names, OS names, MAC addresses, and some hardware information.', 'software-licensor' ),
                    'default' => '',
                    'label' => 'Share customer info',
                ),
                'email_message' => array(
                    'title' => __( 'Preface of the license in the user emails and order history', 'software-licensor' ),
                    'type' => 'textarea',
                    'description' => __( 'This will show right before their license codes. There will only be one license code for any software your users buy using Software Licensor. If you are using another licensing service, you might want to put " for" at the end, or an equivalent word in the language your site is in, and include software names with the following setting.', 'software-licensor' ),
                    'desc_tip' => true,
                    'default' => 'Here is your license code for our software:'
                ),
                'include_software_names' => array(
                    'title' => __( 'Include software names in email?', 'software-licensor' ),
                    'type' => 'checkbox',
                    'description' => __( "If you are using or planning on using other licensing services, then the user's license codes might not all be the same, and the email will now include the names of your software along with their license code IF you check this box..", 'software-licensor'),
                    'desc_tip' => true,
                    'default' => ''
                ),
                'debug' => array(
                    'title'             => __( 'Debug Log', 'woocommerce-integration-demo' ),
                    'type'              => 'checkbox',
                    'label'             => __( 'Enable logging', 'software-licensor' ),
                    'default'           => 'no',
                    'description'       => __( 'Log events such as API requests', 'software-licensor' ),
                ),
            );
        }        

        public function process_admin_options()
        {
            echo 'Reached process_admin_options() function';
            $all_settings_valid = true;
            $settings = $this->get_form_fields();
    
            foreach ($settings as $key => $setting) {
                if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
                    $value = sanitize_text_field(wp_unslash($_POST[$this->plugin_id . $this->id . '_' . $key]));
    
                    // Check for required fields
                    if (empty($value) && isset($setting['required']) && $setting['required']) {
                        $all_settings_valid = false;
                        WC_Admin_Settings::add_error(sprintf(__('Error: %s is a required field.', 'software-licensor'), $setting['title']));
                    }
                    
                    // Check for numeric value
                    if (!empty($value) && isset($setting['numeric']) && $setting['numeric'] && !ctype_digit($value)) {
                        $all_settings_valid = false;
                        WC_Admin_Settings::add_error(sprintf(__('Error: %s must be a numeric value.', 'software-licensor'), $setting['title']));
                    }
                }
            }
    
            if ($all_settings_valid) {
                $saved = parent::process_admin_options();
                $current_store_id = software_licensor_load_store_id();
                error_log('saved: ' . $saved);
                error_log('current store id: ' . $current_store_id);
                error_log('store id2: ' . get_option('software_licensor_store_id', 'a'));
                if ($current_store_id === false) {
                    error_log('submitting register store API request');
                    software_licensor_register_store_request(
                        $this->get_option('store_id_prefix'),
                        $this->get_option('email'),
                        $this->get_option('first_name'),
                        $this->get_option('last_name'),
                        $this->get_option('discord_username'),
                        $this->get_option('offline_frequency_hours'),
                        $this->get_option('perpetual_expiration_days'),
                        $this->get_option('perpetual_frequency_hours'),
                        $this->get_option('subscription_expiration_days'),
                        $this->get_option('subscription_leniency_offset_hours'),
                        $this->get_option('subscription_frequency_hours'),
                        $this->get_option('trial_expiration_days'),
                        $this->get_option('trial_frequency_hours')
                    );
                }
                if ($saved) {
                    $share_customer_info = $this->get_option('share_customer_info');
                    $share_customer_info = $share_customer_info == 'yes' || $share_customer_info === true;
                    software_licensor_set_sharing_customer_info($share_customer_info);
                }
                return $saved;
            }
            return false;
        }
        
        function software_licensor_admin_menus() {
            // main menu item
            add_menu_page(
                'Software Licensor',
                'Software Licensor',
                'manage_options',
                'software-licensor',
                array($this, 'software_licensor_list_products_page'),
                'dashicons-admin-network'
            );

            // product creation page
            add_submenu_page(
                'software-licensor',
                'Create/Update Licensed Product',
                'Create/Update Licensed Product',
                'manage_options',
                'software-licensor-create-update-licensed-product',
                array($this, 'software_licensor_create_update_licensed_product_page')
            );
        }

        function software_licensor_create_update_licensed_product_page() {
            ?>
            <div class="wrap">
                <h1>Create/Update Licensed Product</h1>
                <form method="post">
                    <label for="allow_offline">Allow Offline?</label>
                    <input type="checkbox" id="allow_offline" name="allow_offline" value="1" <?php echo isset($_POST['allow_offline']) ? 'checked' : ''; ?>><br>
                    <p>
                        Allowing offline licenses can be enabled later on, but this
                        cannot be disabled once enabled. Also, it isn't fully supported 
                        yet.
                    </p>

                    <label for="machines_per_license">Machines Per License:</label>
                    <input type="number" id="machines_per_license" name="machines_per_license" value="<?php echo isset($_POST['machines_per_license']) ? esc_attr($_POST['machines_per_license']) : ''; ?>"><br>
                    <p>
                        Each individual license purchase will have a machine limit 
                        with this amount of machines. This cannot be changed later 
                        for this product.
                    </p>

                    <label for="product_id_prefix">Product ID/Prefix:</label>
                    <input type="text" id="product_id_prefix" name="product_id_prefix" value="<?php echo isset($_POST['product_id_prefix']) ? esc_attr($_POST['product_id_prefix']) : ''; ?>"><br>
                    <p>
                        You can either enter an existing product ID to update the 
                        version or "Allow Offline" field, or you can enter a short 
                        ID prefix that will be at the front of your new product ID.
                    </p>

                    <label for="product_name">Product Name:</label>
                    <input type="text" id="product_name" name="product_name" value="<?php echo isset($_POST['product_name']) ? esc_attr($_POST['product_name']) : ''; ?>"><br>
                    <p>
                        Enter the product name here. This will primarily be visible 
                        to customers when they view their license information.
                    </p>

                    <label for="product_version">Product Version:</label>
                    <input type="text" id="product_version" name="product_version" value="<?php echo isset($_POST['product_version']) ? esc_attr($_POST['product_version']) : ''; ?>"><br>
                    <p>
                        Enter the product version here. This version will be received 
                        by your software, and can be used as an indicator that a 
                        new version of your software is available once you put out 
                        updates.
                    </p>

                    <input type="submit" value="Submit" name="submit_form">
                </form>
            </div>
        <?php

            if (isset($_POST['submit_form'])) {
                $this->process_form_data($_POST);
            }
        }

        function process_form_data($data) {
            $allow_offline = isset($data['allow_offline']) ? true : false;
            $machines_per_license = filter_var($data['machines_per_license'], FILTER_VALIDATE_INT);
            $product_id_prefix = sanitize_text_field($data['product_id_prefix']);
            $product_name = sanitize_text_field($data['product_name']);
            $product_version = sanitize_text_field($data['product_version']);

            software_licensor_create_product_request($allow_offline, $machines_per_license, $product_id_prefix, $product_name, $product_version);
            echo 'The form has been submitted';
        }

        function software_licensor_list_products_page() {
            ?>
            <div class="wrap">
                <?php 
                echo '<h1>Store ID</h1>';
                echo '<p><strong>' . software_licensor_load_store_id() . '</strong></p>';
                echo '<p>You will need to include this store ID in your client side code</p>';
                echo '<h3>Your PHP Version: ' . phpversion() . '</h3>';
                echo '<p>If you are experiencing problems, take note of this PHP version ' . 
                    'as this could impact the functionality of the code.</p>';
                ?>
                <h1>Product List</h1>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Product ID</th>
                            <th>Public Key</th>
                            <th>Allows Offline</th>
                            <th>Version</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $products = software_licensor_get_products_array();
                        foreach ($products as $product_id => $product_info) {
                            echo '<tr>';
                            echo '<td>' . esc_html($product_info['product_name']) . '</td>';
                            echo '<td>' . esc_html($product_id) . '</td>';
                            echo '<td>' . esc_html($product_info['public_key']) . '</td>';
                            echo '<td>' . ($product_info['allows_offline'] ? 'Yes' : 'No') . '</td>';
                            echo '<td>' . esc_html($product_info['version']) . '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php
        }
    }
endif;
?>