<?php
/**
* Plugin Name: Advanced Bank Transfer
* Plugin URI: https://www.sumitsharma.xyz
* Description: Clone Bank Transfer and add receipt upload section .
* Author: Sumit Sharma
* Author URI: https://www.sumitsharma.xyz
* Version: 0.1
* Text Domain: advanced-bank-transfer
*
* @package   Advanced-Bank-Transfer
* @author    Sumit Sharma
*/

defined( 'ABSPATH' ) or exit;

// WooCommerce is active or not
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

// New Gatway
function wc_advanced_bank_transfer_add_to_gateways( $gateways ) {
    $gateways[] = 'Advanced_Bank_Transfer';
    return $gateways;
}

add_filter( 'woocommerce_payment_gateways', 'wc_advanced_bank_transfer_add_to_gateways' );
/**
* Adds plugin page links
*/
function wc_advanced_bank_transfer_plugin_links( $links ) {
        $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=advanced_bank_transfer' ) . '">' . __( 'Configure', 'advanced-bank-transfer' ) . '</a>'
        );
        return array_merge( $plugin_links, $links );
}
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_advanced_bank_transfer_plugin_links' );

    add_action( 'plugins_loaded', 'wc_advanced_bank_transfer_init', 11 );

function wc_advanced_bank_transfer_init() {

    class Advanced_Bank_Transfer extends WC_Payment_Gateway {
        /**
        * Constructor for the gateway.
        */
        public function __construct() {

            $this->id                 = 'advanced_bank_transfer';
            $this->icon               = apply_filters('woocommerce_advanced_bank_transfer_icon', '');
            $this->has_fields         = false;
            $this->method_title       = __( 'Advanced Bank Transfer', 'advanced-bank-transfer' );
            $this->method_description = __( 'Allows Bank Transfer payments. Orders are marked as "on-hold" when received.', 'advanced-bank-transfer' );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option( 'title' );
            $this->description  = $this->get_option( 'description' );
            $this->instructions = $this->get_option( 'instructions', $this->description );

            // Bank BCA account fields shown on the thanks page and in emails
            $this->account_details = get_option(
                'woocommerce_abacs_accounts',
                array(
                    array(
                        'account_name'   => $this->get_option( 'account_name' ),
                        'account_number' => $this->get_option( 'account_number' ),
                        'sort_code'      => $this->get_option( 'sort_code' ),
                        'bank_name'      => $this->get_option( 'bank_name' ),
                        'iban'           => $this->get_option( 'iban' ),
                        'bic'            => $this->get_option( 'bic' ),
                    ),
                )
            );

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_account_details' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

            // Customer Emails
            add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        }


    /**
    * Initialize Gateway Settings Form Fields (from Parent class .)
    */
    public function init_form_fields() {

                $this->form_fields = apply_filters( 'advanced_bank_transfer_form_fields', array(

                'enabled' => array(
                'title'   => __( 'Enable/Disable', 'advanced-bank-transfer' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Advanced Bank Transfer', 'advanced-bank-transfer' ),
                'default' => 'yes'
                ),

                'title' => array(
                'title'       => __( 'Title', 'advanced-bank-transfer' ),
                'type'        => 'text',
                'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'advanced-bank-transfer' ),
                'default'     => __( 'Advanced Bank Transfer', 'advanced-bank-transfer' ),
                'desc_tip'    => true,
                ),

                'description' => array(
                'title'       => __( 'Description', 'advanced-bank-transfer' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'advanced-bank-transfer' ),
                'default'     => __( 'Please remit payment to Store Name upon pickup or delivery.', 'advanced-bank-transfer' ),
                'desc_tip'    => true,
                ),

                'instructions' => array(
                'title'       => __( 'Instructions', 'advanced-bank-transfer' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page and emails.', 'advanced-bank-transfer' ),
                'default'     => '',
                'desc_tip'    => true,
                ),
                'account_details' => array(
                        'type' => 'account_details',
                ),

                ) );
    }


    /**
    * Output for the order received page.
    */


        public function thankyou_page( $order_id ) {

            if ( $this->instructions ) {
                echo wp_kses_post( wpautop( wptexturize( wp_kses_post( $this->instructions ) ) ) );
            }
            $this->bank_details( $order_id );

        }


        private function bank_details( $order_id = '' ) {

            if ( empty( $this->account_details ) ) {
                return;
            }

            // Get order and store in $order.
            $order = wc_get_order( $order_id );

            // Get the order country and country $locale.
            $country = $order->get_billing_country();
            $locale  = $this->get_country_locale();

            // Get sortcode label in the $locale array and use appropriate one.
            $sortcode = isset( $locale[ $country ]['sortcode']['label'] ) ? $locale[ $country ]['sortcode']['label'] : __( 'Sort code', 'woocommerce' );

            $bacs_accounts = apply_filters( 'woocommerce_bacs_accounts', $this->account_details );

            if ( ! empty( $bacs_accounts ) ) {
                $account_html = '';
                $has_details  = false;

                foreach ( $bacs_accounts as $bacs_account ) {
                    $bacs_account = (object) $bacs_account;

                    if ( $bacs_account->account_name ) {
                        $account_html .= '<h3 class="wc-bacs-bank-details-account-name">' . wp_kses_post( wp_unslash( $bacs_account->account_name ) ) . ':</h3>' . PHP_EOL;
                    }

                    $account_html .= '<ul class="wc-bacs-bank-details order_details bacs_details">' . PHP_EOL;

                    // BACS account fields shown on the thanks page and in emails.
                    $account_fields = apply_filters(
                        'woocommerce_bacs_account_fields',
                        array(
                            'bank_name'      => array(
                                'label' => __( 'Bank', 'woocommerce' ),
                                'value' => $bacs_account->bank_name,
                            ),
                            'account_number' => array(
                                'label' => __( 'Account number', 'woocommerce' ),
                                'value' => $bacs_account->account_number,
                            ),
                            'sort_code'      => array(
                                'label' => $sortcode,
                                'value' => $bacs_account->sort_code,
                            ),
                            'iban'           => array(
                                'label' => __( 'IBAN', 'woocommerce' ),
                                'value' => $bacs_account->iban,
                            ),
                            'bic'            => array(
                                'label' => __( 'BIC', 'woocommerce' ),
                                'value' => $bacs_account->bic,
                            ),
                        ),
                        $order_id
                    );

                    foreach ( $account_fields as $field_key => $field ) {
                        if ( ! empty( $field['value'] ) ) {
                            $account_html .= '<li class="' . esc_attr( $field_key ) . '">' . wp_kses_post( $field['label'] ) . ': <strong>' . wp_kses_post( wptexturize( $field['value'] ) ) . '</strong></li>' . PHP_EOL;
                            $has_details   = true;
                        }
                    }

                    $account_html .= '</ul>';
                }

                if ( $has_details ) {
                    echo '<section class="woocommerce-bacs-bank-details"><h2 class="wc-bacs-bank-details-heading">' . esc_html__( 'Our bank details', 'woocommerce' ) . '</h2>' . wp_kses_post( PHP_EOL . $account_html ) . '</section>';
                }
            }

        }

    /**
    * Add content to the WC emails.
    *
    * @access public
    * @param WC_Order $order
    * @param bool $sent_to_admin
    * @param bool $plain_text
    */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {

        if ( $this->instructions && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'on-hold' ) ) {
             echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
        }
    }


    /**
    * Process the payment and return the result
    *
    * @param int $order_id
    * @return array
    */
    public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status( 'on-hold', __( 'Awaiting advanced bank transfer payment', 'advanced-bank-transfer' ) );

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
            'result' 	=> 'success',
            'redirect'	=> $this->get_return_url( $order )
            );
    }


        public function generate_account_details_html() {

            ob_start();

            $country = WC()->countries->get_base_country();
            $locale  = $this->get_country_locale();

            // Get sortcode label in the $locale array and use appropriate one.
            $sortcode = isset( $locale[ $country ]['sortcode']['label'] ) ? $locale[ $country ]['sortcode']['label'] : __( 'Sort code', 'woocommerce' );

            ?>
            <tr valign="top">
                <th scope="row" class="titledesc"><?php esc_html_e( 'Account details:', 'woocommerce' ); ?></th>
                <td class="forminp" id="bacs_accounts">
                    <div class="wc_input_table_wrapper">
                        <table class="widefat wc_input_table sortable" cellspacing="0">
                            <thead>
                            <tr>
                                <th class="sort">&nbsp;</th>
                                <th><?php esc_html_e( 'Account name', 'woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Account number', 'woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Bank name', 'woocommerce' ); ?></th>
                                <th><?php echo esc_html( $sortcode ); ?></th>
                                <th><?php esc_html_e( 'IBAN', 'woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'BIC / Swift', 'woocommerce' ); ?></th>
                            </tr>
                            </thead>
                            <tbody class="accounts">
                            <?php
                            $i = -1;
                            if ( $this->account_details ) {
                                foreach ( $this->account_details as $account ) {
                                    $i++;

                                    echo '<tr class="account">
										<td class="sort"></td>
										<td><input type="text" value="' . esc_attr( wp_unslash( $account['account_name'] ) ) . '" name="abacs_account_name[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $account['account_number'] ) . '" name="abacs_account_number[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( wp_unslash( $account['bank_name'] ) ) . '" name="abacs_bank_name[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $account['sort_code'] ) . '" name="abacs_sort_code[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $account['iban'] ) . '" name="abacs_iban[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $account['bic'] ) . '" name="abacs_bic[' . esc_attr( $i ) . ']" /></td>
									</tr>';
                                }
                            }
                            ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th colspan="7"><a href="#" class="add button"><?php esc_html_e( '+ Add account', 'woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php esc_html_e( 'Remove selected account(s)', 'woocommerce' ); ?></a></th>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                    <script type="text/javascript">
                        jQuery(function() {
                            jQuery('#bacs_accounts').on( 'click', 'a.add', function(){

                                var size = jQuery('#bacs_accounts').find('tbody .account').length;

                                jQuery('<tr class="account">\
									<td class="sort"></td>\
									<td><input type="text" name="abacs_account_name[' + size + ']" /></td>\
									<td><input type="text" name="abacs_account_number[' + size + ']" /></td>\
									<td><input type="text" name="abacs_bank_name[' + size + ']" /></td>\
									<td><input type="text" name="abacs_sort_code[' + size + ']" /></td>\
									<td><input type="text" name="abacs_iban[' + size + ']" /></td>\
									<td><input type="text" name="abacs_bic[' + size + ']" /></td>\
								</tr>').appendTo('#bacs_accounts table tbody');

                                return false;
                            });
                        });
                    </script>
                </td>
            </tr>
            <?php
            return ob_get_clean();

        }

        public function get_country_locale() {

            if ( empty( $this->locale ) ) {

                // Locale information to be used - only those that are not 'Sort Code'.
                $this->locale = apply_filters(
                    'woocommerce_get_bacs_locale',
                    array(
                        'AU' => array(
                            'sortcode' => array(
                                'label' => __( 'BSB', 'woocommerce' ),
                            ),
                        ),
                        'CA' => array(
                            'sortcode' => array(
                                'label' => __( 'Bank transit number', 'woocommerce' ),
                            ),
                        ),
                        'IN' => array(
                            'sortcode' => array(
                                'label' => __( 'IFSC', 'woocommerce' ),
                            ),
                        ),
                        'IT' => array(
                            'sortcode' => array(
                                'label' => __( 'Branch sort', 'woocommerce' ),
                            ),
                        ),
                        'NZ' => array(
                            'sortcode' => array(
                                'label' => __( 'Bank code', 'woocommerce' ),
                            ),
                        ),
                        'SE' => array(
                            'sortcode' => array(
                                'label' => __( 'Bank code', 'woocommerce' ),
                            ),
                        ),
                        'US' => array(
                            'sortcode' => array(
                                'label' => __( 'Routing number', 'woocommerce' ),
                            ),
                        ),
                        'ZA' => array(
                            'sortcode' => array(
                                'label' => __( 'Branch code', 'woocommerce' ),
                            ),
                        ),
                    )
                );

            }

            return $this->locale;

        }

        public function save_account_details() {

            $accounts = array();

            if ( isset( $_POST['abacs_account_name'] ) && isset( $_POST['abacs_account_number'] ) && isset( $_POST['abacs_bank_name'] )
                && isset( $_POST['abacs_sort_code'] ) && isset( $_POST['abacs_iban'] ) && isset( $_POST['abacs_bic'] ) ) {

                $account_names   = wc_clean( wp_unslash( $_POST['abacs_account_name'] ) );
                $account_numbers = wc_clean( wp_unslash( $_POST['abacs_account_number'] ) );
                $bank_names      = wc_clean( wp_unslash( $_POST['abacs_bank_name'] ) );
                $sort_codes      = wc_clean( wp_unslash( $_POST['abacs_sort_code'] ) );
                $ibans           = wc_clean( wp_unslash( $_POST['abacs_iban'] ) );
                $bics            = wc_clean( wp_unslash( $_POST['abacs_bic'] ) );

                foreach ( $account_names as $i => $name ) {
                    if ( ! isset( $account_names[ $i ] ) ) {
                        continue;
                    }

                    $accounts[] = array(
                        'account_name'   => $account_names[ $i ],
                        'account_number' => $account_numbers[ $i ],
                        'bank_name'      => $bank_names[ $i ],
                        'sort_code'      => $sort_codes[ $i ],
                        'iban'           => $ibans[ $i ],
                        'bic'            => $bics[ $i ],
                    );
                }
            }
            // phpcs:enable

            update_option( 'woocommerce_abacs_accounts', $accounts );
        }

        /**
         * If There are no payment fields show the description if set.
         * Override this in your gateway if you have some.
         */
        public function payment_fields() {
            $description = $this->get_description();
            if ( $description ) {
                echo wpautop( wptexturize( $description ) ); // @codingStandardsIgnoreLine.
                $countries = get_option( 'apm_countries' );
                if(in_array($_POST['s_country'],$countries)){
                    echo $this->woocommerce_checkout_payment_test();
                }


            }

            if ( $this->supports( 'default_credit_card_form' ) ) {
                $this->credit_card_form(); // Deprecated, will be removed in a future version.
            }
        }


/************ This function use to build upload file form ***************/

        function woocommerce_checkout_payment_test(){

            $form = "<form  class='upload-receipt-form' method='post' enctype='multipart/form-data' name='uploadRecipt' ><input type='file' name='receipt' />";
            $form .= " </form><p>Only '.jpg', '.jpeg', '.png', '.pdf' allow. </p><input type='hidden' value='".WC()->session->get( 'receipt_id')."' name='r_id'/>";
                $form .= '<script>
                jQuery("input[name=\'receipt\']").on("change",function(e) {
                    
                    e.preventDefault();
                    
                     var files = e.target.files,
                    filesLength = files.length;
                  for (var i = 0; i < filesLength; i++) {
                    var f = files[i]
                    var fileReader = new FileReader();
                    fileReader.onload = (function(e) {
                      var file = e.target;
                      if(jQuery("span.pip")){
                          jQuery("span.pip").remove();
                      }
                        var extension = files[0].name.match(/\.[0-9a-z]+$/i);
                    
                      if(extension[0] == ".pdf"){
                          jQuery("<span class=\"pip\">" +
                        "<i style=\"font-size: 60px;\" class=\"fa fa-file\" aria-hidden=\"true\"></i>" +
                        "<br/><span class=\"remove\"><i class=\"fa fa-trash\" aria-hidden=\"true\"></i></span>" +
                        "</span>").insertAfter("input[name=\'receipt\']");
                      }else{
                        jQuery("<span class=\"pip\">" +
                        "<img class=\"imageThumb\" src=\"" + e.target.result + "\" title=\"" + file.name + "\"/>" +
                        "<br/><span class=\"remove\"><i class=\"fa fa-trash\" aria-hidden=\"true\"></i></span>" +
                        "</span>").insertAfter("input[name=\'receipt\']");  
                      }
                      
                      jQuery(".remove").click(function(){
                        jQuery(this).parent(".pip").remove();
                        jQuery("input[name=\'receipt\']").val(\'\');
                      });
                    });
                    fileReader.readAsDataURL(f);
                  }
                  
                    var form = jQuery(\'form[name=uploadRecipt]\')[0];
                    var formData = new FormData(form);
                    jQuery.ajax({
                        url: "'.admin_url( "admin-ajax.php" ).'?action=ajax_request",
                        type: "POST",
                        data: formData,
                        success: function (data) {
                            if(data){
                              alert(data)  
                            }else{
                                alert("Select file to upload.")
                            }
                            
                        },
                        cache: false,
                        contentType: false,
                        processData: false
                    });
                    
                
                });

            </script>';

        return $form;
         }


    } // end \Advanced_Bank_Transfer class

/************* This is for upload and create media for order post type **************/
    add_action( 'wp_ajax_nopriv_ajax_request', 'ajax_request' );
    add_action( 'wp_ajax_ajax_request', 'ajax_request' );

    function ajax_request(){
        if ( ! empty( $_FILES['receipt']['name'] ) ) {
            $supported_types = array( 'application/pdf','image/jpg', 'image/jpeg', 'image/png' );
            $arr_file_type = wp_check_filetype( basename( $_FILES['receipt']['name'] ) );
            $uploaded_type = $arr_file_type['type'];

            if ( in_array( $uploaded_type, $supported_types ) ) {


                $upload = wp_upload_bits($_FILES['receipt']['name'], null, file_get_contents($_FILES['receipt']['tmp_name']));
                if ( isset( $upload['error'] ) && $upload['error'] != 0 ) {
                    wp_die( 'There was an error uploading your file. The error is: ' . $upload['error'] );
                } else {

                    // Prepare an array of post data for the attachment.
                    $attachment = array(
                        'guid'           => $upload['url'] . '/' . basename( $_FILES['receipt']['name'] ),
                        'post_mime_type' => $upload['type'],
                        'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $_FILES['receipt']['name'] ) ),
                        'post_content'   => '',
                        'post_status'    => 'inherit'
                    );

                    $attach_id = wp_insert_attachment( $attachment, $filename, $parent_post_id );
                    require_once( ABSPATH . 'wp-admin/includes/image.php' );
                    $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
                    wp_update_attachment_metadata( $attach_id, $attach_data );
                    WC()->session->set( 'receipt_id', $attach_id );
                    wp_die( "Receipt Uploaded Successfully." );
                }
            }
            else {
                wp_die( "The file type that you've uploaded is not a Valid." );
            }
        }

        die();
    }

/**************** This will update attachment with order *****************/
    add_action( 'woocommerce_thankyou', 'update_meta_field', 4 );

    function update_meta_field($order_id){
        $receipt_id = WC()->session->get( 'receipt_id');
        if(isset($receipt_id) && $receipt_id != 0){
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            set_post_thumbnail( $order_id, $receipt_id);
            add_post_meta( $order_id, 'receipt', $receipt_id );
            update_post_meta( $order_id, 'receipt', $receipt_id );
        }
        WC()->session->__unset('receipt_id');
    }

    /********************** This will Show attachment to edit order section with name and link**********************/
    add_action( 'woocommerce_admin_order_data_after_order_details', 'receipt_checkout_field_display_admin_order_meta', 10, 1 );

    function receipt_checkout_field_display_admin_order_meta($order){
        $args = array(
            'post_type'   => 'attachment',
            'numberposts' => 1,
            'post_status' => 'any',
            'post_ID' => get_post_meta( $order->id, 'receipt', true ),
            'exclude'     => get_post_thumbnail_id(),
        );

        $attachments = get_posts( $args );

        if ( $attachments ) {
            foreach ( $attachments as $attachment ) {
                echo '<p class="form-field form-field-wide wc-customer-user" ><strong>'.__('Receipt').':</strong> ' . apply_filters( 'the_title', $attachment->post_title ) . '</p>';
                the_attachment_link( $attachment->ID, false );
            }
        }

    }

/**************** This will validate countries for upload receipt *******************/
    add_action('woocommerce_after_checkout_validation', 'rei_after_checkout_validation');

    function rei_after_checkout_validation( $posted ) {
        $countries = get_option( 'apm_countries' );

        if (in_array($posted['billing_country'],$countries)) {
            if(empty($posted['r_id']) || $posted['r_id'] == 0){
                wc_add_notice( __( "Receipt is not selected. Please update Receipt.", 'woocommerce' ), 'error' );
            }
        }
    }

/**************** this will add new countries select in WC settings (Select to enable Advance Payment Option) *******************/
    function apm_countries_setting( $settings ) {

        $updated_settings = array();

        foreach ( $settings as $section ) {

            // at the bottom of the General Options section
            if ( isset( $section['id'] ) && 'general_options' == $section['id'] &&
                isset( $section['type'] ) && 'sectionend' == $section['type'] ) {

                $updated_settings[] = array(
                    'name'     => __( 'Select to enable Advance Payment Option', 'apm_countries' ),
                    'desc_tip' => __( 'It will enable or restrict selected country for uploading Receipt', 'apm_countries' ),
                    'id'       => 'apm_countries',
                    'type'     => 'multi_select_countries',
                    'class'    => 'wc-enhanced-select',
                    'css'      => 'min-width:300px;',
                    'std'      => '1',  // WC < 2.0
                    'default'  => '1',  // WC >= 2.0

                );
            }

            $updated_settings[] = $section;
        }

        return $updated_settings;
    }

    add_filter( 'woocommerce_general_settings', 'apm_countries_setting' );

}

?>