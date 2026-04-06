<?php
/**
 * Software Licensor Integration.
 *
 * @package  WC_Software_Licensor_Integration
 * @category Integration
 * @author   Noah Stiltner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Software_Licensor_Integration' ) ) :

class WC_Software_Licensor_Integration {

    /**
     * Option key for the plugin settings.
     */
    private const SETTINGS_OPTION = 'software_licensor_settings';

    /**
     * Legacy WooCommerce integration settings option key.
     */
    private const LEGACY_SETTINGS_OPTION = 'woocommerce_software-licensor_settings';

    /**
     * WooCommerce product meta key for linked Easy Digital Downloads IDs.
     */
    private const WOO_EDD_DOWNLOAD_IDS_META_KEY = '_software_licensor_edd_download_ids';

    /**
     * Cached settings.
     *
     * @var array<string, mixed>
     */
    private $settings = array();

    /**
     * Field definitions for the settings page.
     *
     * @var array<string, array<string, mixed>>
     */
    private $form_fields = array();

    /**
     * Public debug flag.
     *
     * @var string|bool
     */
    public $debug = 'no';

    public function __construct() {
        $this->init_form_fields();
        $this->settings = $this->load_settings();
        $this->debug    = $this->get_option( 'debug', 'no' );

        add_action( 'woocommerce_check_cart_items', array( $this, 'software_licensor_validate_cart' ) );
        add_action( 'woocommerce_payment_complete', array( $this, 'handle_woocommerce_payment_complete' ), 5 );
        add_action( 'woocommerce_payment_complete', 'software_licensor_create_license_request', 10 );
        add_action( 'woocommerce_thankyou', 'software_licensor_prepend_license_code', 1, 1 );
        add_action( 'woocommerce_thankyou', 'software_licensor_show_license_code_after_purchase', 999, 1 );

        add_action( 'wp_ajax_software_licensor_regenerate_license', 'software_licensor_regenerate_license_request' );
        add_action( 'wp_ajax_nopriv_software_licensor_regenerate_license', 'software_licensor_regenerate_license_request' );

        add_shortcode( 'software_licensor_licenses_page', array( $this, 'software_licensor_display_license' ) );

        add_action( 'admin_menu', array( $this, 'software_licensor_admin_menus' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_woo_product_meta_box' ) );
        add_action( 'save_post_product', array( $this, 'save_woo_product_meta_box' ), 10, 2 );
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_woo_product_data_panel_fields' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_woo_product_data_panel_fields' ) );

        add_action( 'init', array( $this, 'my_licenses_account_endpoint' ) );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'my_licenses_account_menu_items' ) );
        add_action( 'woocommerce_account_user-licenses_endpoint', array( $this, 'account_page_display_license' ) );
        add_filter( 'woocommerce_get_customer_available_downloads', array( $this, 'append_linked_edd_downloads_to_account_downloads' ), 10, 1 );
    }

    /**
     * Load settings from the new option, with a one-way fallback from the legacy option.
     *
     * @return array<string, mixed>
     */
    private function load_settings() {
        $defaults = $this->get_default_settings();
        $settings = get_option( self::SETTINGS_OPTION, null );

        if ( ! is_array( $settings ) ) {
            $legacy_settings = get_option( self::LEGACY_SETTINGS_OPTION, null );

            if ( is_array( $legacy_settings ) ) {
                $settings = wp_parse_args( $legacy_settings, $defaults );
                update_option( self::SETTINGS_OPTION, $settings );
            } else {
                $settings = $defaults;
            }
        } else {
            $settings = wp_parse_args( $settings, $defaults );
        }

        return $settings;
    }

    /**
     * Persist settings to the plugin option.
     *
     * @param array<string, mixed> $settings Settings to save.
     * @return bool
     */
    private function save_settings( array $settings ) {
        $settings       = wp_parse_args( $settings, $this->get_default_settings() );
        $this->settings = $settings;
        $this->debug    = $this->get_option( 'debug', 'no' );

        return update_option( self::SETTINGS_OPTION, $settings );
    }

    /**
     * Default settings.
     *
     * @return array<string, mixed>
     */
    private function get_default_settings() {
        return array(
            'store_id'               => '',
            'share_customer_info'    => 'no',
            'email_message'          => 'Here is your license code for our software:',
            'include_software_names' => 'no',
            'debug'                  => 'no',
        );
    }

    /**
     * Equivalent to the old WooCommerce settings get_option() behavior.
     *
     * @param string $key Setting key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get_option( $key, $default = '' ) {
        if ( array_key_exists( $key, $this->settings ) ) {
            return $this->settings[ $key ];
        }

        return $default;
    }

    /**
     * Initialize settings field definitions.
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'store_id' => array(
                'title'       => __( 'Store ID', 'software-licensor' ),
                'type'        => 'textarea',
                'description' => __( 'Enter your Store ID/API Key.', 'software-licensor' ),
                'default'     => '',
                'required'    => true,
            ),
            'share_customer_info' => array(
                'title'       => __( 'Share Customer Info', 'software-licensor' ),
                'type'        => 'checkbox',
                'label'       => __( 'Share customer info', 'software-licensor' ),
                'description' => __(
                    implode(
                        ' ',
                        array(
                            'Optionally share customer info with Software Licensor.',
                            "Sharing this will enable you (the developer) to access the customer's name and email in your licensed software.",
                            'We do not sell or share personal customer information, and the information will be encrypted in transit and at rest.',
                            'If you check this box, you will need to include a statement in your privacy policy that Software Licensor is one of the 3rd parties that you are sharing customer data with.',
                            'The collected data primarily includes names and emails, and can also include computer names, OS names, MAC addresses, and some hardware information.',
                            'Computer names are collected regardless of your choice, so that the user can see which computers are on their licenses.'
                        )
                    ),
                    'software-licensor'
                ),
                'default'     => 'no',
            ),
            'email_message' => array(
                'title'       => __( 'Preface of the license in the user emails and order history', 'software-licensor' ),
                'type'        => 'textarea',
                'description' => __( 'This will show right before their license codes. There will only be one license code for any software your users buy using Software Licensor. If you are using another licensing service, you might want to put " for" at the end, or an equivalent word in the language your site is in, and include software names with the following setting.', 'software-licensor' ),
                'default'     => 'Here is your license code for our software:',
            ),
            'include_software_names' => array(
                'title'       => __( 'Include software names in email?', 'software-licensor' ),
                'type'        => 'checkbox',
                'description' => __( "If you are using or planning on using other licensing services, then the user's license codes might not all be the same, and the email will now include the names of your software along with their license code if you check this box.", 'software-licensor' ),
                'default'     => 'no',
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'software-licensor' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging', 'software-licensor' ),
                'description' => __( 'Log events such as API requests', 'software-licensor' ),
                'default'     => 'no',
            ),
        );
    }

    /**
     * Return field definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_form_fields() {
        return $this->form_fields;
    }

    /**
     * Save settings from the plugin settings page.
     *
     * @return bool
     */
    public function process_admin_options() {
        $all_settings_valid = true;
        $new_settings       = $this->settings;
        $fields             = $this->get_form_fields();

        foreach ( $fields as $key => $field ) {
            $type      = isset( $field['type'] ) ? $field['type'] : 'text';
            $is_posted = isset( $_POST[ $key ] );

            if ( 'checkbox' === $type ) {
                $value = $is_posted ? 'yes' : 'no';
            } else {
                $raw_value = $is_posted ? wp_unslash( $_POST[ $key ] ) : '';
                $value     = is_string( $raw_value ) ? trim( sanitize_textarea_field( $raw_value ) ) : '';
            }

            if ( ! empty( $field['required'] ) && '' === $value ) {
                $all_settings_valid = false;
                add_settings_error(
                    'software_licensor_messages',
                    'software_licensor_required_' . $key,
                    sprintf(
                        /* translators: %s: field title */
                        __( 'Error: %s is a required field.', 'software-licensor' ),
                        $field['title']
                    ),
                    'error'
                );
            }

            if ( ! empty( $field['numeric'] ) && '' !== $value && ! ctype_digit( (string) $value ) ) {
                $all_settings_valid = false;
                add_settings_error(
                    'software_licensor_messages',
                    'software_licensor_numeric_' . $key,
                    sprintf(
                        /* translators: %s: field title */
                        __( 'Error: %s must be a numeric value.', 'software-licensor' ),
                        $field['title']
                    ),
                    'error'
                );
            }

            $new_settings[ $key ] = $value;
        }

        if ( ! $all_settings_valid ) {
            return false;
        }

        $saved = $this->save_settings( $new_settings );

        $current_store_id = software_licensor_load_store_id();

        if ( false === $current_store_id || false === software_licensor_load_private_key() ) {
            software_licensor_register_store_request( $this->get_option( 'store_id' ) );
        }

        if ( $saved ) {
            $share_customer_info = $this->get_option( 'share_customer_info' );
            $share_customer_info = ( 'yes' === $share_customer_info || true === $share_customer_info );
            software_licensor_set_sharing_customer_info( $share_customer_info );

            add_settings_error(
                'software_licensor_messages',
                'software_licensor_saved',
                __( 'Settings saved.', 'software-licensor' ),
                'updated'
            );
        }

        return $saved;
    }

    /**
     * Render settings fields for the plugin settings page.
     *
     * @return void
     */
    private function render_settings_fields() {
        foreach ( $this->form_fields as $key => $field ) {
            $title       = isset( $field['title'] ) ? $field['title'] : '';
            $type        = isset( $field['type'] ) ? $field['type'] : 'text';
            $description = isset( $field['description'] ) ? $field['description'] : '';
            $label       = isset( $field['label'] ) ? $field['label'] : '';
            $required    = ! empty( $field['required'] );
            $value       = $this->get_option( $key, isset( $field['default'] ) ? $field['default'] : '' );

            echo '<tr>';
            echo '<th scope="row">';
            echo '<label for="' . esc_attr( $key ) . '">' . esc_html( $title ) . '</label>';
            if ( $required ) {
                echo ' <span style="color:#b32d2e;">*</span>';
            }
            echo '</th>';
            echo '<td>';

            if ( 'textarea' === $type ) {
                echo '<textarea class="large-text" rows="5" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
            } elseif ( 'checkbox' === $type ) {
                echo '<label for="' . esc_attr( $key ) . '">';
                echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="yes" ' . checked( $value, 'yes', false ) . ' />';
                if ( '' !== $label ) {
                    echo ' ' . esc_html( $label );
                }
                echo '</label>';
            } else {
                echo '<input class="regular-text" type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
            }

            if ( '' !== $description ) {
                echo '<p class="description">' . esc_html( $description ) . '</p>';
            }

            echo '</td>';
            echo '</tr>';
        }
    }

    /**
     * Plugin admin menus.
     *
     * @return void
     */
    public function software_licensor_admin_menus() {
        add_menu_page(
            'Software Licensor',
            'Software Licensor',
            'manage_options',
            'software-licensor',
            array( $this, 'software_licensor_list_products_page' ),
            'dashicons-admin-network'
        );

        add_submenu_page(
            'software-licensor',
            'Store Registration',
            'Store Registration',
            'manage_options',
            'software-licensor-store-registration',
            array( $this, 'software_licensor_store_registration_page' )
        );

        add_submenu_page(
            'software-licensor',
            'Create/Update Licensed Product',
            'Create/Update Licensed Product',
            'manage_options',
            'software-licensor-create-update-licensed-product',
            array( $this, 'software_licensor_create_update_licensed_product_page' )
        );

        $import_export_page = add_submenu_page(
            'software-licensor',
            'Import/Export Private Key',
            'Import/Export Private Key',
            'manage_options',
            'software-licensor-import-export-private-key',
            array( $this, 'software_licensor_import_export_page' )
        );

        add_action( 'load-' . $import_export_page, array( $this, 'user_export_handler' ) );
    }

    /**
     * Store registration/settings page.
     *
     * @return void
     */
    public function software_licensor_store_registration_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'software-licensor' ) );
        }

        if (
            isset( $_POST['software_licensor_store_registration_nonce'] ) &&
            wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['software_licensor_store_registration_nonce'] ) ),
                'software_licensor_store_registration_save'
            )
        ) {
            $this->process_admin_options();
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Store Registration', 'software-licensor' ) . '</h1>';

        settings_errors( 'software_licensor_messages' );

        echo '<form method="post" action="">';
        wp_nonce_field( 'software_licensor_store_registration_save', 'software_licensor_store_registration_nonce' );

        echo '<table class="form-table" role="presentation">';
        $this->render_settings_fields();
        echo '</table>';

        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function my_licenses_account_menu_items( $items ) {
        $items['user-licenses'] = __( 'Licenses', 'woocommerce' );

        $downloads    = isset( $items['downloads'] ) ? $items['downloads'] : null;
        $edit_address = isset( $items['edit-address'] ) ? $items['edit-address'] : null;
        $edit_account = isset( $items['edit-account'] ) ? $items['edit-account'] : null;
        $logout       = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;

        unset( $items['downloads'], $items['edit-address'], $items['edit-account'], $items['customer-logout'] );

        if ( null !== $downloads ) {
            $items['downloads'] = $downloads;
        }
        if ( null !== $edit_address ) {
            $items['edit-address'] = $edit_address;
        }
        if ( null !== $edit_account ) {
            $items['edit-account'] = $edit_account;
        }
        if ( null !== $logout ) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    public function my_licenses_account_endpoint() {
        add_rewrite_endpoint( 'user-licenses', EP_ROOT | EP_PAGES );
    }

    public function account_page_display_license() {
        echo $this->get_license_data_html();
    }

    public function software_licensor_display_license() {
        software_licensor_error_log( 'Inside software_licensor_display_license' );

        if ( ! wp_get_current_user() ) {
            wp_die( 'You must be logged in to view your licenses.' );
        }

        ob_start();
        echo $this->get_license_data_html();
        return ob_get_clean();
    }

    public function get_license_data_html() {
        $license_data = software_licensor_get_license_info( wp_get_current_user() );
        if ( false === $license_data ) {
            return '<p>No license data to display.</p>';
        }

        $data            = array();
        $license_code    = $license_data->getLicenseCode();
        $licensed_products = $license_data->getLicensedProducts();
        $iterator        = $licensed_products->getIterator();
        $store_products  = software_licensor_get_products_array();
        $counter         = 1;

        foreach ( $iterator as $product_id => $product_data ) {
            $machines      = array();
            $license_type  = $product_data->getLicenseType();
            $expiration    = $product_data->getExpirationOrRenewal();
            $offline_machines = $product_data->getOfflineMachines();
            $online_machines  = $product_data->getOnlineMachines();
            $machine_limit = $product_data->getMachineLimit();
            $machine_count = count( $offline_machines ) + count( $online_machines );

            foreach ( $offline_machines as $m ) {
                $machines[] = array(
                    'id'              => substr( (string) $m->getId(), 0, 10 ),
                    'os'              => $m->getOs(),
                    'computer_name'   => $m->getComputerName(),
                    'activation_type' => 'offline',
                );
            }

            foreach ( $online_machines as $m ) {
                $machines[] = array(
                    'id'              => substr( (string) $m->getId(), 0, 10 ),
                    'os'              => $m->getOs(),
                    'computer_name'   => $m->getComputerName(),
                    'activation_type' => 'online',
                );
            }

            $data[] = array(
                'index'         => $counter,
                'product_name'  => isset( $store_products[ $product_id ]['product_name'] ) ? $store_products[ $product_id ]['product_name'] : '',
                'license_type'  => $license_type,
                'expiration'    => $expiration,
                'machine_count' => $machine_count,
                'machine_limit' => $machine_limit,
                'machines'      => $machines,
            );

            $counter += 1;
        }

        $output_html  = '<div id="clipboard-notification-container" style="opacity: 0.0;">';
        $output_html .= '<div id="clipboard-notification">';
        $output_html .= 'Your license code has been copied to your clipboard.</div></div>';

        $output_html .= '<div class="licenses">';
        $output_html .= '<div class="SL-license-code-header">License Code:</div>';
        $output_html .= '<div class="SL-license-code-container"><span class="SL-license-code">' . esc_html( $license_code ) . '</span></div>';

        $output_html .= "
<script>
document.addEventListener('DOMContentLoaded', function () {
    const codeEl = document.getElementsByClassName('SL-license-code')[0];
    if (codeEl) {
        codeEl.addEventListener('click', function() {
            navigator.clipboard.writeText(this.innerText)
                .then(() => {
                    const notification = document.getElementById('clipboard-notification-container');
                    if (notification) {
                        notification.style.opacity = '1.0';
                        setTimeout(() => {
                            notification.style.opacity = '0.0';
                        }, 8000);
                    }
                })
                .catch((err) => {
                    console.error('Error copying text: ', err);
                });
        });
    }
});
</script>
";

        $output_html .= '<table class="SL-licenses-table shop_table">';
        $output_html .= '<thead><tr>';
        $output_html .= '<th>Product</th>';
        $output_html .= '<th>License Type</th>';
        $output_html .= '<th>Expiration</th>';
        $output_html .= '<th>Machine Count</th>';
        $output_html .= '</tr></thead>';
        $output_html .= '<tbody>';

        foreach ( $data as $item ) {
            $output_html .= '<tr class="SL-product-row" data-index="' . esc_attr( (string) $item['index'] ) . '">';
            $output_html .= '<td>' . esc_html( stripslashes( (string) $item['product_name'] ) ) . '</td>';
            $output_html .= '<td>' . esc_html( (string) $item['license_type'] ) . '</td>';

            if ( '0' !== (string) $item['expiration'] && is_numeric( $item['expiration'] ) ) {
                $output_html .= '<td class="sl-expiration" data-ts="' . esc_attr( (string) $item['expiration'] ) . '"></td>';
            } else {
                $output_html .= '<td>' . esc_html( (string) $item['expiration'] ) . '</td>';
            }

            $output_html .= '<td>' . esc_html( (string) $item['machine_count'] ) . '/' . esc_html( (string) $item['machine_limit'] ) . '</td>';
            $output_html .= '</tr>';

            $output_html .= '<tr class="machine-details" style="display:none;"><td colspan="4">';
            $output_html .= '<table class="machine-table">';
            $output_html .= '<thead><tr><th>Machine ID</th><th>Computer Name</th><th>OS</th><th>Activation Type</th></tr></thead>';
            $output_html .= '<tbody>';

            foreach ( $item['machines'] as $machine ) {
                $output_html .= '<tr>';
                $output_html .= '<td>' . esc_html( (string) $machine['id'] ) . '</td>';
                $output_html .= '<td>' . esc_html( (string) $machine['computer_name'] ) . '</td>';
                $output_html .= '<td>' . esc_html( (string) $machine['os'] ) . '</td>';
                $output_html .= '<td>' . esc_html( (string) $machine['activation_type'] ) . '</td>';
                $output_html .= '</tr>';
            }

            $output_html .= '</tbody>';
            $output_html .= '</table>';
            $output_html .= '</td></tr>';
        }

        $output_html .= '</tbody>';
        $output_html .= '</table>';

        $output_html .= '<div class="SL-buttons-container">';
        $output_html .= '<button class="SL-regenerate-license-button" onclick="regenerateLicense()">Regenerate License</button>';
        $output_html .= '</div>';
        $output_html .= '<p>The data on this page refreshes every hour.</p>';
        $output_html .= '<p>You can regenerate your license code if you need to disable some online-activated machines. You can do this once every two weeks.</p>';

        $output_html .= '<script>
            function regenerateLicense() {
                fetch("' . esc_url( admin_url( 'admin-ajax.php' ) ) . '?action=software_licensor_regenerate_license")
                .then(response => response.text())
                .then(data => alert(data));
            }

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

                document.querySelectorAll(".sl-expiration").forEach(function(el) {
                    const ts = parseInt(el.dataset.ts, 10);

                    if (!isNaN(ts) && ts > 0) {
                        const date = new Date(ts * 1000);
                        el.textContent = date.toLocaleString(undefined, {
                            day: "2-digit",
                            month: "short",
                            year: "numeric",
                            hour: "2-digit",
                            minute: "2-digit"
                        });
                    } else {
                        el.textContent = "—";
                    }
                });
            });
        </script></div>';

        return $output_html;
    }


    /**
     * Render WooCommerce product data panel fields for the EDD bridge.
     *
     * @return void
     */
    public function render_woo_product_data_panel_fields() {
        if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
            return;
        }

        echo '<div class="options_group software-licensor-edd-bridge">';

        woocommerce_wp_text_input(
            array(
                'id'                => 'software_licensor_edd_download_ids',
                'label'             => __( 'Linked EDD Download IDs', 'software-licensor' ),
                'description'       => __( 'Enter one or more Easy Digital Downloads IDs separated by commas. Customers who buy this WooCommerce product will see the current files from those linked downloads in My Account → Downloads.', 'software-licensor' ),
                'desc_tip'          => true,
                'type'              => 'text',
                'placeholder'       => '123,456',
                'value'             => (string) get_post_meta( get_the_ID(), self::WOO_EDD_DOWNLOAD_IDS_META_KEY, true ),
            )
        );

        echo '</div>';
    }

    /**
     * Save WooCommerce product data panel fields for the EDD bridge.
     *
     * @param int $post_id Product ID.
     * @return void
     */
    public function save_woo_product_data_panel_fields( $post_id ) {
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $raw_ids      = isset( $_POST['software_licensor_edd_download_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['software_licensor_edd_download_ids'] ) ) : '';
        $download_ids = $this->parse_download_ids_string( $raw_ids );

        if ( empty( $download_ids ) ) {
            delete_post_meta( $post_id, self::WOO_EDD_DOWNLOAD_IDS_META_KEY );
            return;
        }

        update_post_meta( $post_id, self::WOO_EDD_DOWNLOAD_IDS_META_KEY, implode( ',', $download_ids ) );
    }

    /**
     * Add a WooCommerce product meta box for linked Easy Digital Downloads downloads.
     *
     * @return void
     */
    public function add_woo_product_meta_box() {
        add_meta_box(
            'software-licensor-edd-download-links',
            __( 'Software Licensor Downloads', 'software-licensor' ),
            array( $this, 'render_woo_product_meta_box' ),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render the WooCommerce product meta box for linked EDD downloads.
     *
     * @param \WP_Post $post Product post.
     * @return void
     */
    public function render_woo_product_meta_box( $post ) {
        wp_nonce_field( 'software_licensor_woo_product_meta', 'software_licensor_woo_product_meta_nonce' );

        $linked_download_ids = get_post_meta( $post->ID, self::WOO_EDD_DOWNLOAD_IDS_META_KEY, true );

        echo '<p>';
        echo '<label for="software_licensor_edd_download_ids"><strong>' . esc_html__( 'Linked EDD Download IDs', 'software-licensor' ) . '</strong></label>';
        echo '<input type="text" class="widefat" id="software_licensor_edd_download_ids" name="software_licensor_edd_download_ids" value="' . esc_attr( (string) $linked_download_ids ) . '" placeholder="123,456" />';
        echo '</p>';
        echo '<p class="description">' . esc_html__( 'Enter one or more Easy Digital Downloads IDs separated by commas. Customers who buy this WooCommerce product will see the current files from those linked downloads in the Downloads section of My Account.', 'software-licensor' ) . '</p>';

        if ( ! $this->is_edd_active() || ! function_exists( 'edd_get_download' ) ) {
            echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'Easy Digital Downloads is not active, so linked downloads cannot be previewed right now.', 'software-licensor' ) . '</p>';
            return;
        }

        $download_ids = $this->parse_download_ids_string( $linked_download_ids );

        if ( empty( $download_ids ) ) {
            return;
        }

        echo '<ul style="margin:8px 0 0 18px; list-style:disc;">';

        foreach ( $download_ids as $download_id ) {
            $download = edd_get_download( $download_id );

            if ( $download ) {
                echo '<li>' . esc_html( get_the_title( $download_id ) ) . ' (#' . esc_html( (string) $download_id ) . ')</li>';
            } else {
                echo '<li style="color:#b32d2e;">' . sprintf( esc_html__( 'Download #%d was not found.', 'software-licensor' ), (int) $download_id ) . '</li>';
            }
        }

        echo '</ul>';
    }

    /**
     * Save linked EDD download IDs for a WooCommerce product.
     *
     * @param int      $post_id Product post ID.
     * @param \WP_Post $post Product post.
     * @return void
     */
    public function save_woo_product_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['software_licensor_woo_product_meta_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['software_licensor_woo_product_meta_nonce'] ) ), 'software_licensor_woo_product_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $raw_ids      = isset( $_POST['software_licensor_edd_download_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['software_licensor_edd_download_ids'] ) ) : '';
        $download_ids = $this->parse_download_ids_string( $raw_ids );

        if ( empty( $download_ids ) ) {
            delete_post_meta( $post_id, self::WOO_EDD_DOWNLOAD_IDS_META_KEY );
            return;
        }

        update_post_meta( $post_id, self::WOO_EDD_DOWNLOAD_IDS_META_KEY, implode( ',', $download_ids ) );
    }

    /**
     * Trigger a bridge hook after WooCommerce marks a payment complete.
     *
     * @param int $order_id WooCommerce order ID.
     * @return void
     */
    public function handle_woocommerce_payment_complete( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        do_action( 'software_licensor_woo_linked_edd_downloads_granted', $order_id, $this->get_order_linked_edd_download_ids( $order ), $order );
    }

    /**
     * Append EDD-linked files to the WooCommerce My Account downloads list.
     *
     * @param array<int, array<string, mixed>> $downloads Existing Woo downloads.
     * @return array<int, array<string, mixed>>
     */
    public function append_linked_edd_downloads_to_account_downloads( $downloads ) {
        if ( ! is_user_logged_in() || ! $this->is_edd_active() || ! function_exists( 'edd_get_download_files' ) ) {
            return $downloads;
        }

        $linked_downloads = $this->get_customer_linked_edd_downloads( get_current_user_id() );

        if ( empty( $linked_downloads ) ) {
            return $downloads;
        }

        $existing_keys = array();

        foreach ( $downloads as $download ) {
            if ( isset( $download['download_id'], $download['file']['id'] ) ) {
                $existing_keys[] = (string) $download['download_id'] . ':' . (string) $download['file']['id'];
            }
        }

        foreach ( $linked_downloads as $download_id => $context ) {
            $files = edd_get_download_files( $download_id );

            if ( ! is_array( $files ) || empty( $files ) ) {
                continue;
            }

            foreach ( $files as $file_id => $file_data ) {
                $file_name = '';
                $file_url  = '';

                if ( is_array( $file_data ) ) {
                    $file_name = isset( $file_data['name'] ) ? (string) $file_data['name'] : '';
                    $file_url  = isset( $file_data['file'] ) ? (string) $file_data['file'] : '';
                } elseif ( is_string( $file_data ) ) {
                    $file_url = $file_data;
                }

                if ( '' === $file_url ) {
                    continue;
                }

                $unique_key = (string) $download_id . ':' . (string) $file_id;

                if ( in_array( $unique_key, $existing_keys, true ) ) {
                    continue;
                }

                $downloads[] = array(
                    'download_url'        => $file_url,
                    'download_id'         => $download_id,
                    'product_id'          => $context['product_id'],
                    'product_name'        => get_the_title( $context['product_id'] ),
                    'download_name'       => '' !== $file_name ? $file_name : get_the_title( $download_id ),
                    'downloads_remaining' => '',
                    'access_expires'      => '',
                    'file'                => array(
                        'id'   => (string) $file_id,
                        'name' => '' !== $file_name ? $file_name : basename( (string) wp_parse_url( $file_url, PHP_URL_PATH ) ),
                    ),
                );

                $existing_keys[] = $unique_key;
            }
        }

        return $downloads;
    }

    /**
     * Determine whether Easy Digital Downloads is active.
     *
     * @return bool
     */
    private function is_edd_active() {
        return class_exists( 'Easy_Digital_Downloads' ) || function_exists( 'edd_get_download_files' );
    }

    /**
     * Parse a comma-separated list of EDD download IDs.
     *
     * @param string $raw_ids Raw text.
     * @return array<int>
     */
    private function parse_download_ids_string( $raw_ids ) {
        if ( ! is_string( $raw_ids ) || '' === trim( $raw_ids ) ) {
            return array();
        }

        $download_ids = preg_split( '/\s*,\s*/', trim( $raw_ids ) );

        if ( ! is_array( $download_ids ) ) {
            return array();
        }

        $download_ids = array_filter( array_map( 'absint', $download_ids ) );

        return array_values( array_unique( $download_ids ) );
    }

    /**
     * Return linked EDD download IDs for a WooCommerce product.
     *
     * @param int $product_id Product ID.
     * @return array<int>
     */
    private function get_linked_edd_download_ids_for_product( $product_id ) {
        return $this->parse_download_ids_string( (string) get_post_meta( $product_id, self::WOO_EDD_DOWNLOAD_IDS_META_KEY, true ) );
    }

    /**
     * Return linked EDD download IDs for a WooCommerce order.
     *
     * @param \WC_Order $order Order object.
     * @return array<int>
     */
    private function get_order_linked_edd_download_ids( $order ) {
        $linked_download_ids = array();

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();

            if ( ! $product_id ) {
                continue;
            }

            $linked_download_ids = array_merge( $linked_download_ids, $this->get_linked_edd_download_ids_for_product( $product_id ) );
        }

        return array_values( array_unique( array_map( 'absint', $linked_download_ids ) ) );
    }

    /**
     * Return current EDD downloads linked to completed or processing Woo orders.
     *
     * @param int $customer_id Customer user ID.
     * @return array<int, array<string, int>>
     */
    private function get_customer_linked_edd_downloads( $customer_id ) {
        $results = array();
        $orders  = wc_get_orders(
            array(
                'customer_id' => $customer_id,
                'status'      => array( 'wc-processing', 'wc-completed' ),
                'limit'       => -1,
                'return'      => 'objects',
            )
        );

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();

                if ( ! $product_id ) {
                    continue;
                }

                foreach ( $this->get_linked_edd_download_ids_for_product( $product_id ) as $download_id ) {
                    if ( ! isset( $results[ $download_id ] ) ) {
                        $results[ $download_id ] = array(
                            'product_id' => $product_id,
                            'order_id'   => $order->get_id(),
                        );
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Ensure that there aren't duplicate items in the cart that could mess up the licensing backend.
     *
     * @return void
     */
    public function software_licensor_validate_cart() {
        $products_info = array();
        $user          = wp_get_current_user();
        $license_data  = software_licensor_get_license_info( $user );
        $owned_licenses = null;

        if ( $license_data ) {
            $owned_licenses = $license_data->getLicensedProducts();
        }

        $has_license = false;

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product  = $cart_item['data'];
            $subtotal = WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] );

            $software_id = $product->get_attribute( 'software_licensor_id' );
            if ( $product->is_type( 'variation' ) ) {
                $parent_product = wc_get_product( $cart_item['product_id'] );
                if ( $parent_product ) {
                    $software_id = $parent_product->get_attribute( 'software_licensor_id' );
                }
            }

            if ( $software_id ) {
                $has_license = true;

                if ( $product->is_type( 'variation' ) ) {
                    $license_type = $product->get_attribute( 'pa_license_type' );
                } else {
                    $license_type = $product->get_attribute( 'license_type' );
                }

                $license_type = strtolower( (string) $license_type );

                if ( array_key_exists( $software_id, $products_info ) ) {
                    if (
                        $subtotal > 0 ||
                        $products_info[ $software_id ]['subtotal'] > 0 ||
                        $license_type !== $products_info[ $software_id ]['license_type']
                    ) {
                        wc_add_notice( '<strong>You must not purchase different license types for the same product.</strong>', 'error' );
                    }
                } else {
                    if ( isset( $owned_licenses ) && null !== $owned_licenses ) {
                        $owned = $owned_licenses->offsetGet( $software_id );
                        if ( $owned ) {
                            $owned_license_type = $owned->getLicenseType();

                            if ( 'trial' === $license_type ) {
                                wc_add_notice( '<strong>You cannot get a trial license for a product that you already have a license for.</strong>', 'error' );
                            } elseif ( 'subscription' === $license_type && 'perpetual' === strtolower( (string) $owned_license_type ) ) {
                                wc_add_notice( '<strong>You cannot own a subscription license if you already own a perpetual license for the same product.</strong>', 'error' );
                            }
                        }
                    }

                    $products_info[ $software_id ] = array(
                        'subtotal'     => $subtotal,
                        'license_type' => $license_type,
                    );
                }
            }
        }

        if ( $has_license && ( ! isset( $user->ID ) || empty( $user->ID ) ) ) {
            wc_add_notice( '<strong>You must be logged in to obtain a license.</strong>', 'error' );
        }
    }

    /**
     * Prevents HTML from being appended to exported data.
     *
     * @return void
     */
    public function user_export_handler() {
        if ( isset( $_POST['export_private_key'] ) ) {
            $this->software_licensor_export_private_key();
            exit;
        }
    }

    public function software_licensor_import_export_page() {
        ?>
        <div class="wrap">
            <h2>Import/Export Private Key</h2>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'software_licensor_action_key' ); ?>
                <p>
                    <label for="private_key_file">Choose your private key file (only required for import):</label>
                    <input type="file" name="private_key_file" id="private_key_file">
                </p>
                <p>
                    <label for="user_password">Enter your password:</label>
                    <input type="password" name="user_password" id="user_password" required>
                </p>
                <p>
                    <input type="submit" name="import_private_key" value="Import Private Key">
                    <input type="submit" name="export_private_key" value="Export Private Key">
                </p>
            </form>
        </div>
        <?php

        if ( isset( $_POST['import_private_key'] ) ) {
            $this->software_licensor_import_private_key();
        }

        if ( isset( $_POST['export_private_key'] ) ) {
            $this->software_licensor_export_private_key();
        }
    }

    public function software_licensor_import_private_key() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }

        if ( ! check_admin_referer( 'software_licensor_action_key' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! isset( $_FILES['private_key_file'] ) || empty( $_FILES['private_key_file']['tmp_name'] ) ) {
            wp_die( 'No file uploaded.' );
        }

        $json_data    = file_get_contents( $_FILES['private_key_file']['tmp_name'] );
        $decoded_data = json_decode( $json_data, true );

        if ( ! isset( $decoded_data['private_key'] ) ) {
            wp_die( 'Invalid key file. The private key is missing.' );
        }

        if ( ! isset( $decoded_data['store_id'] ) ) {
            wp_die( 'Invalid key file. Store ID is missing.' );
        }

        if ( ! isset( $decoded_data['products'] ) ) {
            wp_die( 'Invalid key file. Products list is missing.' );
        }

        if ( ! isset( $_POST['user_password'] ) || empty( $_POST['user_password'] ) ) {
            wp_die( 'Password is required.' );
        }

        $encrypted_key = $decoded_data['private_key'];
        $password      = wp_unslash( $_POST['user_password'] );
        $private_key   = openssl_pkey_get_private( $encrypted_key, $password );

        if ( false === $private_key ) {
            wp_die( 'Incorrect password or failed to load the private key.' );
        }

        software_licensor_save_private_key( $private_key );
        software_licensor_save_store_id( $decoded_data['store_id'] );
        software_licensor_save_products_array( $decoded_data['products'] );
        software_licensor_update_pubkeys( true );

        echo '<div class="updated"><p>Private key imported successfully.</p></div>';
    }

    public function software_licensor_export_private_key() {
        ob_start();

        if ( ! current_user_can( 'manage_options' ) ) {
            ob_end_clean();
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }

        if ( ! check_admin_referer( 'software_licensor_action_key' ) ) {
            ob_end_clean();
            wp_die( 'Security check failed.' );
        }

        if ( ! isset( $_POST['user_password'] ) || empty( $_POST['user_password'] ) ) {
            ob_end_clean();
            wp_die( 'Password is required.' );
        }

        $user = wp_get_current_user();
        $password = wp_unslash( $_POST['user_password'] );

        if ( ! wp_check_password( $password, $user->data->user_pass, $user->ID ) ) {
            ob_end_clean();
            wp_die( 'Incorrect password.' );
        }

        $private_key = software_licensor_load_private_key();

        $exported_key = '';
        if ( ! openssl_pkey_export( $private_key, $exported_key, $password ) ) {
            ob_end_clean();
            wp_die( 'Failed to export the private key.' );
        }

        $result = array(
            'store_id'    => software_licensor_load_store_id(),
            'private_key' => $exported_key,
            'products'    => software_licensor_get_products_array(),
        );

        ob_clean();

        header( 'Content-Type: application/json' );
        header( 'Content-Disposition: attachment; filename="store_details.json"' );
        header( 'Content-Length: ' . strlen( wp_json_encode( $result ) ) );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );

        wp_send_json( $result );

        ob_end_clean();
        exit();
    }

    public function software_licensor_create_update_licensed_product_page() {
        ?>
        <div class="wrap">
            <h1>Create/Update Licensed Product</h1>
            <form method="post">
                <label for="allow_offline">Allow Offline?</label>
                <input type="checkbox" id="allow_offline" name="allow_offline" value="1" <?php echo isset( $_POST['allow_offline'] ) ? 'checked' : ''; ?>><br>
                <p>
                    Allowing offline licenses can be enabled later on, but this
                    cannot be disabled once enabled. Also, it is not fully supported
                    yet.
                </p>

                <label for="machines_per_license">Machines Per License:</label>
                <input type="number" id="machines_per_license" name="machines_per_license" value="<?php echo isset( $_POST['machines_per_license'] ) ? esc_attr( wp_unslash( $_POST['machines_per_license'] ) ) : ''; ?>"><br>
                <p>
                    Each individual license purchase will have a machine limit
                    with this amount of machines. This cannot be changed later
                    for this product.
                </p>

                <label for="product_id_prefix">Product ID/Prefix:</label>
                <input type="text" id="product_id_prefix" name="product_id_prefix" value="<?php echo isset( $_POST['product_id_prefix'] ) ? esc_attr( wp_unslash( $_POST['product_id_prefix'] ) ) : ''; ?>"><br>
                <p>
                    You can either enter an existing product ID to update the
                    version or "Allow Offline" field, or you can enter a short
                    ID prefix that will be at the front of your new product ID.
                </p>

                <label for="product_name">Product Name:</label>
                <input type="text" id="product_name" name="product_name" value="<?php echo isset( $_POST['product_name'] ) ? esc_attr( wp_unslash( $_POST['product_name'] ) ) : ''; ?>"><br>
                <p>
                    Enter the product name here. This will primarily be visible
                    to customers when they view their license information.
                </p>

                <label for="product_version">Product Version:</label>
                <input type="text" id="product_version" name="product_version" value="<?php echo isset( $_POST['product_version'] ) ? esc_attr( wp_unslash( $_POST['product_version'] ) ) : ''; ?>"><br>
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

        if ( isset( $_POST['submit_form'] ) ) {
            $this->process_form_data( $_POST );
        }
    }

    public function process_form_data( $data ) {
        $allow_offline       = isset( $data['allow_offline'] );
        $machines_per_license = filter_var( $data['machines_per_license'], FILTER_VALIDATE_INT );
        $product_id_prefix   = sanitize_text_field( wp_unslash( $data['product_id_prefix'] ) );
        $product_name        = sanitize_text_field( wp_unslash( $data['product_name'] ) );
        $product_version     = sanitize_text_field( wp_unslash( $data['product_version'] ) );

        software_licensor_create_product_request(
            $allow_offline,
            $machines_per_license,
            $product_id_prefix,
            $product_name,
            $product_version
        );

        echo 'The form has been submitted';
    }

    public function software_licensor_list_products_page() {
        ?>
        <div class="wrap">
            <?php
            echo '<h1>Store ID</h1>';
            echo '<p><strong>' . esc_html( (string) software_licensor_load_store_id() ) . '</strong></p>';
            echo '<p>You will need to include this store ID in your client side code</p>';
            echo '<h3>Your PHP Version: ' . esc_html( phpversion() ) . '</h3>';
            echo '<p>If you are experiencing problems, take note of this PHP version as this could impact the functionality of the code.</p>';
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
                    foreach ( $products as $product_id => $product_info ) {
                        echo '<tr>';
                        echo '<td>' . esc_html( $product_info['product_name'] ) . '</td>';
                        echo '<td>' . esc_html( $product_id ) . '</td>';
                        echo '<td>' . esc_html( $product_info['public_key'] ) . '</td>';
                        echo '<td>' . ( ! empty( $product_info['allows_offline'] ) ? 'Yes' : 'No' ) . '</td>';
                        echo '<td>' . esc_html( $product_info['version'] ) . '</td>';
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