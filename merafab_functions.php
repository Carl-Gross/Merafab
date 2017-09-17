<?php

//Error reporting during development only. Also need to delete WP_DEBUG mode in wp-config.php.
ini_set('display_errors', 'On');
error_reporting(E_ALL | E_STRICT);

/*
Plugin Name: Merafab Functionality
Description: All database inputs, outputs, routing functions
Version: 1
Author: Carl Gross
*/

//GLOBAL VARIABLES FOR SITE-WIDE IMPLEMENTATION
$QUOTE_DUR_NON_3D_PRINT_DAYS = 5; //time allowed for quote responses for non-3d printed parts
$QUOTE_DUR_3D_PRINT_DAYS = 3; //time allowed for quote responses for non-3d printed parts
$QUOTE_SELECTION_DAYS = 5; //number of days allowed for customer to select the winning quote
$SHIP_AND_INSPECT_DAYS = 10; //number of days allowed between marking order as shipped and inspecting the order
$FEE_PERCENT = .01; //fraction of vendor price taken as fee
$BID_COST_3D_PRINT_TOTAL = 50; //cost to customer to receive bids for 3D print jobs
$BID_COST_3D_PRINT_EACH = 10; //payments to vendors if 3D print job not awarded to them
$BID_COST_NON_3D_PRINT_TOTAL = 200; //cost to customer to receive bids for non-3D print jobs
$BID_COST_NON_3D_PRINT_EACH = 20; //payments to vendors if non-3D print job not awarded to them
$MIN_BIDS = 1; //minimum number of bids before customer can be refunded their bid cost
$HOLIDAYS = array('*-01-01', '*-07-04', '*-12-25', '*-12-24', '2018-11-22', '2018-11-23', '2018-09-03', '2018-05-28');

//LINK FILTERS AND ACTIONS
//GRAVITY-FORMS ACTIONS
add_action( 'gform_after_submission_1', 'add_vendor_f', 10, 2 ); //populates vendor DB
add_action( 'gform_after_submission_11', 'update_vendor_f', 10, 2 ); //updates vendor DB
add_action( 'gform_after_submission_2', 'add_cust_f', 10, 2 ); //populates customer DB
add_action( 'gform_after_submission_10', 'update_cust_f', 10, 2 ); //updates customer DB
add_action( 'gform_after_submission_3', 'add_order_f', 10, 2 ); //populates order DB
add_filter( 'gform_submit_button_5', '__return_false' ); //removes submit button from Vendor Order Display form
add_filter( 'gform_submit_button_14', '__return_false' ); //removes submit button from Customer Order Display form
add_filter( 'gform_submit_button_16', '__return_false' ); //removes submit button from Vendor Bid Display form
add_filter( 'gform_submit_button_19', '__return_false' ); //removes submit button from Customer Bid Display form
add_action( 'gform_after_submission_6', 'add_quote_f', 10, 2 ); //populates quote DB with new quote
add_action( 'gform_after_submission_12', 'vendor_quote_f', 10, 2 ); //populates quote DB with vendor responses
add_action( 'gform_after_submission_15', 'cust_quote_f', 10, 2 ); //populates quote DB with customer bid selection
add_action( 'gform_after_submission_17', 'add_ship_info_f', 10, 2 ); //populates quote DB with customer bid selection
add_action( 'gform_after_submission_18', 'start_dispute_f', 10, 2 ); //populates dispute DB with new dispute information
add_filter( 'gform_pre_render_3', 'populate_earnest_money'); //populates prices for page 2 of multi-page forms
$profile_fields = array( 'name', 'street_addr' ,'street_addr2','city','state','zip','contact_name_first','contact_name_last','contact_phone','contact_email','alt_name_first' ,'alt_name_last','alt_phone','alt_email','capability_extra'); //all pre populated form fields for customer&vendor profiles. Note names from GF field advanced tab
foreach($profile_fields as $field) // loop through profile fields and add the Gravity Forms filters
  add_filter('gform_field_value_'.$field, 'populate_profile');

add_filter('gform_pre_render_11', 'populate_capability'); //allows for vendor profile capability checkbox prepopulation
add_filter('gform_admin_pre_render_11', 'populate_capability');
$order_fields = array( 'order_name', 'dsr' ,'qty','need_date','firm_need','promise_date','material','ship_name','ship_street','ship_street2','ship_city','ship_state','ship_zip','order_phase','order_qual','order_addl_rqmts','order_comments'); //all pre populated form fields for orders. Note names from GF field advanced tab
foreach($order_fields as $field) // loop through order fields and add the Gravity Forms filters
  add_filter('gform_field_value_'.$field, 'populate_order');

$quote_fields = array( 'quote_initiated_date', 'quote_due' ,'quote_response_received_date','quote_status','quote_cost_ea','quote_ship_cost','quote_vendor_price','quote_fee',
'quote_total_cost','quote_promise_date','quote_comments','bid_reimburse', 'quote_cust_response_date', 'quote_cust_name', 'quote_cust_title', 'quote_vendor_name', 'quote_vendor_title'); //all pre populated form fields for quotes. Note names from GF field advanced tab
foreach($quote_fields as $field) // loop through order fields and add the Gravity Forms filters
  add_filter('gform_field_value_'.$field, 'populate_quote');

add_filter('gform_pre_render_5', 'populate_vqual'); //allows for required vendor qualification prepopulation on order display forms
add_filter('gform_admin_pre_render_5', 'populate_vqual');
add_filter( 'gform_upload_path_3', 'change_order_upload_path', 10, 2 ); //changes upload path for order form
add_filter( 'gform_upload_path_18', 'change_order_upload_path', 10, 2 ); //changes upload path for dispute form
add_filter('gform_pre_render_15', 'populate_quote_nums'); //allows for prepopulation of available quote numbers on bid select form
add_filter("gform_pre_render_15", "monitor_bid_dropdown"); //monitors drop-down for bid-selection changes, in order to correctly populate order cost
add_filter( 'gform_field_validation_6_1', 'check_vid_for_quote', 10, 4 ); //ensures a valid vid was entered prior to submitting the form
add_filter( 'gform_field_validation_12_11', 'check_promise_date', 10, 4 ); //ensures a valid vid was entered prior to submitting the form
add_filter( 'gform_field_validation_4', 'can_assign_order', 10, 4 ); //ensures a valid oid was entered prior to assigning an order

//GRAVITY PERKS FILTERS
add_filter( 'gpro_disable_datepicker', '__return_true' ); //disables datepicker for read-only date fields

//WPDATATABLE FILTERS
add_filter( 'wpdatatables_filter_mysql_query', 'return_oid_only', 10, 2 ); //filters out all other oids by editing mysql query
add_filter( 'wpdatatables_filter_mysql_query', 'return_vendor_open_order_only', 10, 2 ); //edits mysql query to only display orders for the logged-in vendor which are current
add_filter( 'wpdatatables_filter_mysql_query', 'return_vendor_closed_order_only', 10, 2 ); //edits mysql query to only display orders for the logged-in vendor which are complete
add_filter( 'wpdatatables_filter_mysql_query', 'return_vendor_open_quotes_only', 10, 2 ); //edits mysql query to only display quotes for the logged-in vendor which need a response
add_filter( 'wpdatatables_filter_mysql_query', 'return_vendor_closed_quotes_only', 10, 2 ); //edits mysql query to only display quotes for the logged-in vendor which are complete
add_filter( 'wpdatatables_filter_mysql_query', 'return_related_files_only', 10, 2 ); //edits mysql query to only display related files for the oid in the URL
add_filter( 'wpdatatables_filter_mysql_query', 'return_related_quotes_only', 10, 2 ); //edits mysql query to only display related quotes for the oid in the URL
add_filter( 'wpdatatables_filter_mysql_query', 'return_cust_open_order_only', 10, 2 ); //edits mysql query to only display open orders for the logged-in customer
add_filter( 'wpdatatables_filter_mysql_query', 'return_cust_closed_order_only', 10, 2 ); //edits mysql query to only display closed orders for the logged-in customer
add_filter( 'wpdatatables_filter_mysql_query', 'wptestquery', 10, 2 ); //for temp debugging purposes
add_filter( 'wpdatatables_filter_mysql_query', 'return_avail_vendors_only', 10, 2 ); //edits mysql query to only display vendors who are open to new orders

//WP FILTERS
add_filter( 'query_vars', 'custom_query_vars_filter', 10, 1 ); //adds variables for the system to look for in URLs
add_action('init', 'myStartSession', 1); //starts the session upon WP initialization
add_action('wp_logout', 'myEndSession'); //ends the session if a user logs out
add_action('wp_login', 'myEndSession'); //ends the session if a user logs in
add_action('wp_enqueue_scripts', 'my_load_scripts'); //hooks into javascript file

//AJAX LINKING
add_action( 'wp_ajax_accept_order_button_ajax', 'accept_order_button_ajax' );
add_action( 'wp_ajax_complete_order_button_ajax', 'complete_order_button_ajax' );
add_action( 'wp_ajax_initiate_dispute_button_ajax', 'initiate_dispute_button_ajax' );
add_action( 'wp_ajax_uninspected_button_ajax', 'uninspected_button_ajax' );
add_action( 'wp_ajax_populate_bid_ajax', 'populate_bid_ajax' );
add_action( 'wp_ajax_all_quotes_sent_button_ajax', 'all_quotes_sent_button_ajax' );
//add_action( 'wp_ajax_restrict_promise_date_ajax', 'restrict_promise_date_ajax' );

//WORDPRESS SETUP FUNCTIONS

function my_load_scripts($hook) {
    global $QUOTE_DUR_NON_3D_PRINT_DAYS, $QUOTE_SELECTION_DAYS;
    wp_enqueue_script( 'accept_orders_button_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    // in JavaScript, object properties are accessed as ajax_object.ajax_url
    //Note: Do not localize using the same object name unless all properties are identical
    wp_localize_script('accept_orders_button_script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    wp_enqueue_script( 'complete_order_button_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    wp_localize_script('complete_order_button_script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    wp_enqueue_script( 'initiate_dispute_button_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    wp_localize_script('initiate_dispute_button_script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    wp_enqueue_script( 'uninspected_button_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    wp_localize_script('uninspected_button_script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    wp_enqueue_script( 'monitor_bid_dropdown_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    wp_localize_script('monitor_bid_dropdown_script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    wp_enqueue_script( 'all_quotes_sent_button_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    wp_localize_script('all_quotes_sent_button_script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    wp_enqueue_script( 'restrict_need_date_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    wp_localize_script('restrict_need_date_script', 'php_vars', array('quote_dur_non_3d' => $QUOTE_DUR_NON_3D_PRINT_DAYS, 'quote_select_dur' => $QUOTE_SELECTION_DAYS));
    wp_enqueue_script( 'post_bid_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    //wp_enqueue_script( 'restrict_promise_date_script', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    //wp_localize_script('restrict_promise_date_script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));

    /*
    if(is_page('vendor-portal')) {
        wp_enqueue_script( 'load_test', plugins_url( 'merafab_scripts.js', __FILE__ ), array('jquery'));
    }
    */
}

function monitor_bid_dropdown($form) {
    echo '<script type="text/javascript">',
    'monitor_bid_dropdown_script();',
    '</script>'
    ;
    return $form;
}

function myStartSession() { //starts the session
    if(!session_id()) {
        session_start();
    }
}

function myEndSession() { //ends the session
    session_destroy();
}

/*
To save: $_SESSION['myKey'] = "Some data I need later";
To retrieve: if(isset($_SESSION['myKey'])) {
    $value = $_SESSION['myKey'];
} else {
    $value = '';
}
*/

/*
    Function: custom_query_vars_filter
    This function adds the order id and file id as variable names for WP to look for in URLs
    Parameters: The current vars array
    Returns: The updated vars array
*/
function custom_query_vars_filter($vars) {
  $vars[] = 'oid';
  $vars[] = 'fid';
  $vars[] = 'qid';
  return $vars;
}

//AJAX-LINKED FUNCTIONS

/*
    Function: accept_order_button_ajax
    This function updates the vendor's accept_orders boolean in the WPDB. It should be called by a JS function using Ajax.
    Parameters: None (But accept_orders must be POSTed)
    Returns: Nothing
*/
function accept_order_button_ajax() {
    global $wpdb;
    $curr_vid = return_vid();
    $accepting_orders = $_POST['accept_orders'];
    $wpdb->update( //update the vendor's info with their accepting orders status
        'vendor_info',
        array('accept_orders' => $accepting_orders),
        array('vid' => "$curr_vid")
        );

    wp_die();
}

/*
    Function: complete_order_button_ajax
    This function completes the order in the URL query, alerts the user, and redirects them. It should be called by a JS function using Ajax.
    Parameters: None
    Returns: Nothing
*/
function complete_order_button_ajax() {
    global $wpdb;
    $oid_filter = $_POST['oid_filter'];
    complete_order($oid_filter, false);
    wp_die();
}

/*
    Function: initiate_dispute_button_ajax
    This function currently does nothing and is a placeholder for future work. It should be called by a JS function using Ajax.
    Parameters: None
    Returns: Nothing
*/
function initiate_dispute_button_ajax() {

    wp_die();
}

/*
    Function: uninspected_button_ajax
    This function records that the order was received and then redirects the user to their customer portal.
    Parameters: None
    Returns: Nothing
*/
function uninspected_button_ajax() {
    global $wpdb;
    $oid_filter = $_POST['oid_filter'];
    $wpdb->update( //update order in DB
        'orders',
        array('order_phase' => 'Order Received But Not Inspected'),
        array('oid' => "$oid_filter")
        );
    $wpdb->insert('logs', array('user_type' => 'customer', 'log_type' => 'Order Phase Advanced', 'log_entry' => "Order $oid_filter advanced to Received But Not Inspected")); //log the event

    wp_die();
}

/*
    Function: all_quotes_sent_button_ajax
    This function records that all quotes were sent for the order, emails all vendors, logs the change, and then redirects to the admin's order portal
    Parameters: None
    Returns: Nothing
*/
function all_quotes_sent_button_ajax() {

    global $wpdb;
    $oid_filter = $_POST['oid_filter'];
    $curtime_mysql = current_time('mysql');

    $wpdb->update( //update order in DB
        'orders',
        array('order_phase' => 'Quote Requests Sent - Awaiting Vendor Responses', 'bids_started_date' => $curtime_mysql),
        array('oid' => "$oid_filter")
        );

    $related_quotes = $wpdb->get_results("SELECT * FROM quotes WHERE oid = '$oid_filter'", ARRAY_A); //get all related quotes
    foreach ($related_quotes as $quote) { //email all of the vendors who will get quote requests
        $quote_vid = $quote['vid'];
        $new_qid = $quote['qid'];
        $email1 = $wpdb->get_var("SELECT contact_email FROM vendor_info WHERE vid='$quote_vid' limit 1");
        $email2 = $wpdb->get_var("SELECT alt_email FROM vendor_info WHERE vid='$quote_vid' limit 1");
        $email_array = array($email1);
        if ($email2 != NULL) {
            $email_array[] = $email2;
        }
        $headers[] = 'From: Merafab Notify <notify@merafab.com>';
        wp_mail($email_array, "New Quote Request", "You have received a new quote request. Quote Number $new_qid.", $headers);
    }

    $wpdb->insert('logs', array('user_type' => 'admin', 'log_type' => 'Order Phase Advanced', 'log_entry' => "Order $oid_filter advanced to Quote Requests Sent")); //log the event

    wp_die();
}

function restrict_promise_date_ajax() {
    global $wpdb;
    $oid_filter = $_POST['oid_filter'];
    //$atp_date_query =  "SELECT DATE_FORMAT(atp_due, '%Y-%m-%d') FROM orders WHERE oid = '$oid_filter'";
    $atp_date_query =  "SELECT atp_due FROM orders WHERE oid = '$oid_filter'";
    $atp_date = $wpdb->get_var($atp_date_query);
    echo json_encode($atp_date);
    wp_die();
}

//OTHER FUNCTIONS

/*
    Function: populate_order_accept_button
    This function creates a button for the vendor to start/stop accepting new orders by echoing html. It should be called from the front-end.
    Parameters: None
    Returns: Nothing
*/
function populate_order_accept_button() {
    global $wpdb;
    $curr_vid = return_vid();
    if(!is_null($curr_vid)) {
        $accepting_orders = $wpdb->get_var("SELECT accept_orders FROM vendor_info WHERE vid = $curr_vid");
        $button_text = "Accept Orders";
        if($accepting_orders){
            $button_text = "Stop Accepting Orders";
        }
        echo "<input id=\"accept_orders_button\" type=\"button\" value=\"$button_text\" onclick=\"accept_orders_button_script();\" />";
    }
}

/*
    Function: populate_all_quotes_sent_button
    This function creates a button for the admin to advance the order stage once all quote requests have been sent
    Parameters: None
    Returns: Nothing
*/
function populate_all_quotes_sent_button() {
    global $wpdb;
    $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    $display_quotes_sent_button = $wpdb->get_var("SELECT COUNT(oid) FROM orders WHERE (oid = $oid_filter AND order_phase = 'Order Created - Quote Requests In Process' AND num_quotes_requested > 0)");
    if($display_quotes_sent_button) { //if the admin has assigned at least one quote, display the button
        echo "<input id=\"populate_all_quotes_sent_button\" type=\"button\" value=\"All Quotes Requested\" onclick=\"all_quotes_sent_button_script();\" />";
    }
}

/*
    Function: populate_order_track_info
    This function echoes the order's shipping info and creates a 'Mark as Received' button for customers if the order has been marked as shipped by the vendor.
    Must be called from the front-end.
    Parameters: None
    Returns: Nothing
*/
function populate_order_track_info() {
    global $wpdb;
    $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    $order_row = $wpdb->get_row("SELECT * FROM orders WHERE oid = $oid_filter", ARRAY_A);
    if ($order_row['order_phase'] == "Order Shipped") {
        $carrier = $order_row['order_ship_carrier'];
        $tracking = $order_row['order_ship_tracking'];
        echo "<p>Carrier: $carrier </p>";
        echo "<p>Tracking Number: $tracking </p>";
        echo "<input id=\"order_received_button\" type=\"button\" value=\"Mark as Received\" onclick=\"location.href = 'https://merafab.com/order-received?oid=$oid_filter'\" />";
    }
}

/*
    Function: populate_bid_ajax
    This function returns the pertinent information about a bid to the JS caller function
    Parameters: None
    Returns: The bid information to populate
*/
function populate_bid_ajax(){
    global $wpdb;
    if(isset($_POST['quote_selected'])){
        $quote_selected = $_POST['quote_selected'];
        $quote_row = $wpdb->get_row("SELECT * FROM quotes WHERE qid = $quote_selected", ARRAY_A);
        echo json_encode($quote_row);
    } else echo json_encode(0);
    wp_die();
}

/*
    Function: populate_earnest_money
    This function populates the earnest money field when the user switches to the 2nd page of the form. Actual amount
    is based on the type of order and global variables which store standardized costs.
    Parameters: The original form
    Returns: The updated form
*/
function populate_earnest_money( $form ) {
    global $wpdb;
    global $BID_COST_NON_3D_PRINT_TOTAL;
    global $BID_COST_3D_PRINT_TOTAL;
    $current_page = GFFormDisplay::get_current_page( $form['id'] );
    if ( $current_page == 2 ) { //the user is attempting to load the second page
        if ($_POST["input_18"] == '3D-Printed Part') { //note must use POST variable because $fields have not been populated until form submission
            $bid_cost = $BID_COST_3D_PRINT_TOTAL;
        }
        else $bid_cost = $BID_COST_NON_3D_PRINT_TOTAL;
        //var_dump($_POST["input_18"]);
        //var_dump($bid_cost);

        foreach ($form['fields'] as &$field) { //then find the field representing the earnest money cost
            if ($field['id'] == 19) {
                //$price_str = "$" . $bid_cost . ".00";
                //var_dump($price_str);
                $field['defaultValue'] = $bid_cost;
                //var_dump($field);
            }
        }
    }
    return $form;
}

/*
    Function: change_order_upload_path
    This function changes the upload path for order-related files provided in the New Order Gravity Form
    Parameters: Default path_info (which stores the path and the url), and the form_id
    Returns: The updated path_info variable
*/
function change_order_upload_path ($path_info, $form_id) {
    $usercid = return_cid();
    $path_info['path'] = "/nas/content/live/tiger231/user-uploads/orders/cid_$usercid/";
    $path_info['url'] = "https://merafab.com/user-uploads/orders/cid_$usercid/"; //note this will get overwritten in the add_order_f function prior to saving to DB
    return $path_info;
}

function order_cols_minus_oid() { //returns all order columns except for the OID
    return "order_name, order_type, cid, vid, accepted_qid, qty, dsr, order_qual, need_date, firm_need, num_quotes_requested, num_quotes_received, quotes_due, atp_due, promise_date, material, order_vqual, ship_name, ship_street, ship_street2,
    ship_city, ship_state, ship_zip, order_phase, order_addl_rqmts, order_comments, order_create_date, bids_started_date, bids_received_date, order_placed_date,
    order_cancelled_date, order_shipped_date, order_ship_carrier, order_ship_tracking, inspect_due_date, order_complete_date, order_paid_date, order_dispute_start_date, cost_ea, cost_expanded, ship_cost, vendor_price, fee, cost_total, bid_cost";
}

function quote_cols_minus_qid() { //returns all quote columns except for the QID
    return "oid, vid, quote_initiated_date, quote_due, quote_response_received_date, quote_status, quote_cost_ea, quote_ship_cost, quote_vendor_price, quote_fee,
    quote_total_cost, quote_promise_date, quote_comments, bid_reimburse, quote_vendor_name, quote_vendor_title, quote_cust_name, quote_cust_title, quote_cust_response_date";
}

/*
    Function: return_vendor_open_order_only
    This function edits the wpdatatable query to only return open orders assigned to the logged-in vendor, if the table being displayed is meant for that purpose.
    It also creates a link to the order details page for each relevant order.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the open orders vendor table, and the original query otherwise
*/
function return_vendor_open_order_only($query, $table_id) {
    $order_cols = order_cols_minus_oid();
    if (($table_id == 8)) {
        $uservid = return_vid();
        $query = "SELECT
        CONCAT('https://merafab.com/order-details-vendor/?oid=', oid, '||' , oid) AS oid, $order_cols
        FROM orders WHERE vid = $uservid AND order_phase != 'Order Complete' AND order_phase != 'Order Cancelled By Customer' AND order_phase != 'Order Cancelled - No Bids Received'";
        //note wpdatatables url links are in the form link||text
    }
    return $query;
}

/*
    Function: return_cust_open_order_only
    This function edits the wpdatatable query to only return open orders assigned to the logged-in customer, if the table being displayed is meant for that purpose.
    It also creates a link to the order details page for each relevant order.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the open orders vendor table, and the original query otherwise
*/
function return_cust_open_order_only($query, $table_id) {
    $order_cols = order_cols_minus_oid();
    if (($table_id == 13)) {
        $usercid = return_cid();
        $query = "SELECT
        CONCAT('https://merafab.com/order-details-customer/?oid=', oid, '||' , oid) AS oid, $order_cols
        FROM orders WHERE cid = $usercid AND order_phase != 'Order Complete' AND order_phase != 'Order Cancelled By Customer' AND order_phase != 'Order Cancelled - No Bids Received'";
         //note wpdatatables url links are in the form link||text
    }
    return $query;
}

/*
    Function: return_cust_closed_order_only
    This function edits the wpdatatable query to only return closed orders assigned to the logged-in customer, if the table being displayed is meant for that purpose.
    It also creates a link to the order details page for each relevant order.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the open orders vendor table, and the original query otherwise
*/
function return_cust_closed_order_only($query, $table_id) {
    $order_cols = order_cols_minus_oid();
    if (($table_id == 14)) {
        $usercid = return_cid();
        $query = "SELECT
        CONCAT('https://merafab.com/order-details-customer/?oid=', oid, '||' , oid) AS oid, $order_cols
        FROM orders WHERE cid = $usercid AND (order_phase = 'Order Complete' OR order_phase = 'Order Cancelled By Customer' OR order_phase = 'Order Cancelled - No Bids Received')";
        //note wpdatatables url links are in the form link||text
    }
    return $query;
}

/*
    Function: return_vendor_closed_order_only
    This function edits the wpdatatable query to only return closed orders assigned to the logged-in vendor, if the table being displayed is meant for that purpose.
    It also creates a link to the order details page for each relevant order.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the closed orders vendor table, and the original query otherwise
*/
function return_vendor_closed_order_only($query, $table_id) {
    $order_cols = order_cols_minus_oid();
    if (($table_id == 9)) {
        $uservid = return_vid();
        $query = "SELECT
        CONCAT('https://merafab.com/order-details-vendor/?oid=', oid, '||' , oid) AS oid, $order_cols
        FROM orders WHERE vid = $uservid AND (order_phase = 'Order Complete' OR order_phase = 'Order Cancelled By Customer' OR order_phase = 'Order Cancelled - No Bids Received')";
         //note wpdatatables url links are in the form link||text
    }
    return $query;
}

/*
    Function: return_vendor_open_quotes_only
    This function edits the wpdatatable query to only return open quotes assigned to the logged-in vendor, if the table being displayed is meant for that purpose.
    It also creates a link to the RFQ page for each relevant quote.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the open quotes vendor table, and the original query otherwise
*/
function return_vendor_open_quotes_only($query, $table_id) {
    $quote_cols = quote_cols_minus_qid();
    if (($table_id == 10)) {
        $uservid = return_vid();
        $query = "SELECT CONCAT('https://merafab.com/request-for-quote/?oid=', oid, '&qid=', qid, '||' , qid) AS qid, $quote_cols FROM quotes WHERE vid = $uservid
        AND quote_status = 'No Response'";
    }
    return $query;
}

/*
    Function: return_vendor_closed_quotes_only
    This function edits the wpdatatable query to only return closed quotes assigned to the logged-in vendor, if the table being displayed is meant for that purpose.
    It also creates a link to the RFQ page for each relevant quote.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the closed quotes vendor table, and the original query otherwise
*/
function return_vendor_closed_quotes_only($query, $table_id) {
    $quote_cols = quote_cols_minus_qid();
    if (($table_id == 12)) {
        $uservid = return_vid();
        $query = "SELECT CONCAT('https://merafab.com/quote-tracking-vendor/?oid=', oid, '&qid=', qid, '||' , qid) AS qid, $quote_cols FROM quotes WHERE vid = $uservid
        AND quote_status != 'No Response'";
    }
    return $query;
}

/*
    Function: return_related_files_only
    This function edits the wpdatatable query to only return files related to the requested order (as passed thru the URL), if the table being displayed is meant for that purpose.
    It also creates a link to the downloads page for each relevant file. Prior to returning the query, it ensures the user is authorized to download the file.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the files table, and the original query otherwise
*/
function return_related_files_only($query, $table_id) {
    if (($table_id == 4)) {
        $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
        $query = "SELECT CONCAT('https://merafab.com/download/?fid=', fid, '||' , fname) AS fname, fid, oid, cid, url FROM files WHERE oid = $oid_filter";

        if (is_user_auth_view_oid($oid_filter)) return $query;
        //if function has not returned by this point, the user is not authorized to see the file
        send_not_auth();
    }
    return $query; //for all other tables, return the query
}

/*
    Function: return_related_quotes_only
    This function edits the wpdatatable query to only return quotes related to the requested order (as passed thru the URL), if the table being displayed is meant for that purpose.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the files table, and the original query otherwise
*/
function return_related_quotes_only($query, $table_id) {
    if (($table_id == 15 || $table_id == 7)) {
        $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
        //$quote_cols = quote_cols_minus_qid();
        //$query = "SELECT CONCAT('https://merafab.com/quote-tracking-customer/?oid=', oid, '&qid=', qid, '||' , qid) AS qid, $quote_cols FROM quotes WHERE oid = $oid_filter";
        $query = "SELECT * FROM quotes WHERE oid = $oid_filter";

        if (is_user_auth_view_oid_quotes($oid_filter)) return $query;
        //if function has not returned by this point, the user is not authorized to see the quotes
        send_not_auth();
    }
    return $query; //for all other tables, return the query
}

function wptestquery($query, $table_id) {
    if (($table_id == 21)) {
        $query = "SELECT * FROM orders WHERE oid = 76 OR oid = 75";

    }
    return $query; //for all other tables, return the query
}

function return_avail_vendors_only($query, $table_id) {
    if (($table_id == 23)) {
        $query = "SELECT * FROM vendor_info WHERE accept_orders = 1";
    }
    return $query; //return the query
}

/*
    Function: send_not_auth
    This is a helper function which redirects the user to the Unauthorized page
    Parameters: None
    Returns: Nothing
*/
function send_not_auth() {
    if(is_user_logged_in()) { //if the user is not logged in, their page may have timed out
        echo '<script type="text/javascript">
       window.location = "https://merafab.com/timeout/"
        </script>';
    } else {
    echo '<script type="text/javascript">
       window.location = "https://merafab.com/not-authorized/"
        </script>';
    }
    die();
}

/*
    Function: is_user_auth_view_oid
    This function checks if the current user is authorized to see the current order ID. Authorized users are admins, the customer who created the order, all vendors who quoted the
    order, and the vendor assigned the order.
    Parameters: The order ID
    Returns: True if the user is authorized, false otherwise
*/
function is_user_auth_view_oid ($oid_filter) {

    if (current_user_can( 'manage_options' )) return true; //if the user is an admin, they are authorized

    global $wpdb;
    $user = wp_get_current_user();
    $user_role = $user->roles[0]; //get the current user's role
    $username = $user->user_login;
    if ($user_role == 'customer') { //if the user is a customer, make sure their cid matches the cid of the order
        $order_cid_query =  "SELECT cid FROM orders WHERE oid = '$oid_filter'";
        $order_cid = $wpdb->get_var($order_cid_query);
        if ($order_cid == return_cid()) return true;
    } else if ($user_role == 'vendor') { //if the user is a vendor, make sure their vid is on the order or on a quote request for the order
        $curr_vid = return_vid();
        $quotes_vid_query = "SELECT qid FROM quotes WHERE oid = '$oid_filter' AND vid = '$curr_vid' LIMIT 1";
        $quotes_found = $wpdb->get_var($quotes_vid_query);
        if ($quotes_found != NULL) return true;
        $order_vid_query =  "SELECT vid FROM orders WHERE oid = '$oid_filter'";
        $order_vid = $wpdb->get_var($order_vid_query);
        if ($order_vid == $curr_vid) return true;
    }
    $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Not Authorized', 'log_entry' => "Order $oid_filter not authorized for user $username")); //log the event
    return false; //if no earlier conditions were met, the user is not authorized
}

/*
    Function: is_user_auth_view_qid
    This function checks if the current user is authorized to see the current quote ID. Authorized users are admins, the customer who created the order, the vendor who quoted the
    order.
    Parameters: The order ID
    Returns: True if the user is authorized, false otherwise
*/
function is_user_auth_view_qid ($qid_filter) {

    if (current_user_can( 'manage_options' )) return true; //if the user is an admin, they are authorized

    global $wpdb;
    $user = wp_get_current_user();
    $user_role = $user->roles[0]; //get the current user's role
    $username = $user->user_login;
    if ($user_role == 'customer') { //if the user is a customer, make sure their cid matches the cid of the order
        $quote_oid_query =  "SELECT oid FROM quotes WHERE qid = '$qid_filter' LIMIT 1";
        $quote_oid = $wpdb->get_var($quote_oid_query);
        $order_cid_query =  "SELECT cid FROM orders WHERE oid = '$quote_oid' LIMIT 1";
        $order_cid = $wpdb->get_var($order_cid_query);
        if ($order_cid == return_cid()) return true;
    } else if ($user_role == 'vendor') { //if the user is a vendor, make sure their vid is on the quote
        $curr_vid = return_vid();
        $quote_vid_query =  "SELECT vid FROM quotes WHERE qid = '$qid_filter' LIMIT 1";
        $quote_vid = $wpdb->get_var($quote_vid_query);
        if ($curr_vid == $quote_vid) return true;
    }
    $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Not Authorized', 'log_entry' => "Order $oid_filter not authorized for user $username")); //log the event
    return false; //if no earlier conditions were met, the user is not authorized
}

/*
    Function: is_user_auth_view_oid_quotes
    This function checks if the current user is authorized to see all quotes related to the current order. Authorized users are admins and the customer who created the order.
    Parameters: The order ID
    Returns: True if the user is authorized, false otherwise
*/
function is_user_auth_view_oid_quotes ($oid_filter) {

    if (current_user_can( 'manage_options' )) return true; //if the user is an admin, they are authorized

    global $wpdb;
    $user = wp_get_current_user();
    $user_role = $user->roles[0]; //get the current user's role
    if ($user_role == 'customer') { //if the user is a customer, make sure their cid matches the cid of the order
        $order_cid_query =  "SELECT cid FROM orders WHERE oid = '$oid_filter'";
        $order_cid = $wpdb->get_var($order_cid_query);
        if ($order_cid == return_cid()) return true;
    }
    return false; //if no earlier conditions were met, the user is not authorized
}

/*
    Function: return_vid
    Returns the vendor ID for the logged-in vendor
    Parameters: None
    Returns: The vendor ID if a vendor is logged in, and NULL otherwise
*/
function return_vid(){ //returns vendor ID of the currently logged-in vendor
    global $wpdb;
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    $userquery =  "SELECT vid FROM vendor_info WHERE contact_email = '$username'";
    $uservid = $wpdb->get_var($userquery);

    return $uservid;
}

/*
    Function: return_cid
    Returns the customer ID for the logged-in customer
    Parameters: None
    Returns: The customer ID if a customer is logged in, and NULL otherwise
*/
function return_cid(){ //returns customer ID of the currently logged-in customer
    global $wpdb;
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    $userquery =  "SELECT cid FROM cust_info WHERE contact_email = '$username'";
    $usercid = $wpdb->get_var($userquery);

    return $usercid;
}

/*
    Function: populate_capability
    Checkbox prepopulation for vendor capability on gravity forms
    Parameters: The form object
    Returns: The form object modified to check any relevant boxes
*/
function populate_capability($form){
    global $wpdb;
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    $userquery =  "SELECT capability+0 FROM vendor_info WHERE contact_email = '$username'"; //check vendor's capabilities
    $usercap = $wpdb->get_var($userquery);

    foreach ($form['fields'] as &$field) {
         if ($field['id'] == 10) {
            $temp_choice = $field['choices'];
            for ($cap_num = 1; $cap_num <= 9; $cap_num++) {
                    $temp_choice_cap = $temp_choice[$cap_num - 1];
                    $temp_mask = 1 << ($cap_num - 1);
                    if (($temp_mask & $usercap) != 0) {
                        $temp_choice_cap['isSelected'] = 1;
                        $temp_choice[$cap_num - 1] = $temp_choice_cap;
                    }
            }
            $field['choices'] = $temp_choice;
            break;
         }
    }
    return ($form);
}

/*
    Function: populate_profile
    Prepopulates vendor/customer profiles from DB (ie for profile updates)
    Parameters: The value property for the field
    Returns: The populated value property for the field
*/
function populate_profile($value){
    global $wpdb;
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $userquery =  "SELECT * FROM cust_info WHERE contact_email = '$username'"; //check customer list
    $userprofile = $wpdb->get_row( $userquery, ARRAY_A );
    if ($userprofile == NULL) { //if not in customer list, check vendor list
        $userquery =  "SELECT * FROM vendor_info WHERE contact_email = '$username'";
        $userprofile = $wpdb->get_row( $userquery, ARRAY_A );
    }

    //get field name:
    $filter = current_filter();
    if(!$filter) return '';
    $field = str_replace('gform_field_value_', '', $filter);
    if(!$field) return '';

    return $userprofile[$field];
}

/*
    Function: populate_vqual
    Checkbox prepopulation for required vendor quality certifications on gravity forms
    Parameters: The form object
    Returns: The form object modified to check any relevant boxes
*/
function populate_vqual($form){ //checkbox pre-population for gravity forms
    global $wpdb;
    $curr_oid = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );

    $vqual_query =  "SELECT order_vqual+0 FROM orders WHERE oid = '$curr_oid'"; //check required qualifications
    $curr_vqual = $wpdb->get_var($vqual_query);

    foreach ($form['fields'] as &$field) {
         if ($field['id'] == 7) {
            $temp_choice = $field['choices'];
            for ($cap_num = 1; $cap_num <= 4; $cap_num++) {
                    $temp_choice_cap = $temp_choice[$cap_num - 1];
                    $temp_mask = 1 << ($cap_num - 1);
                    if (($temp_mask & $curr_vqual) != 0) {
                        $temp_choice_cap['isSelected'] = 1;
                        $temp_choice[$cap_num - 1] = $temp_choice_cap;
                    }
            }
            $field['choices'] = $temp_choice;
            break;
         }
    }
    return ($form);
}

/*
    Function: populate_quote_nums
    Drop-down prepopulation for available quote numbers on gravity forms
    Parameters: The form object
    Returns: The form object modified to check any relevant boxes
*/
function populate_quote_nums($form){ //checkbox pre-population for gravity forms
    global $wpdb;
    $curr_oid = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );

    $avail_quotes=$wpdb->get_col("SELECT qid FROM quotes WHERE oid = '$curr_oid' AND quote_status = 'Under Consideration'");

    foreach ($form['fields'] as &$field) {
         if ($field['id'] == 7) {
            $choices = array();
            foreach ($avail_quotes as $quote_num) {
                $choices[] = array( 'text' => $quote_num, 'value' => $quote_num );
            }
            $field['choices'] = $choices;
            break;
         }
    }
    return ($form);
}

/*
    Function: populate_order
    Prepopulates order information from DB (ie for viewing of an order)
    Parameters: The value property for the field
    Returns: The populated value property for the field
*/
function populate_order($value){
    global $wpdb;
    $curr_oid = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    if($curr_oid != NULL) {
        if(!is_user_auth_view_oid ($curr_oid)) send_not_auth(); //if the current user is not authorized to view the order, send to a not authorized page
        $oidquery =  "SELECT * FROM orders WHERE oid = '$curr_oid'";
        $oid_data = $wpdb->get_row( $oidquery, ARRAY_A );

        //get field name:
        $filter = current_filter();
        if(!$filter) return '';
        $field = str_replace('gform_field_value_', '', $filter);
        if(!$field) return '';

        return $oid_data[$field];
    }
    else return "OID Not Found";
}

/*
    Function: populate_quote
    Prepopulates order information from DB (ie for viewing of an order)
    Parameters: The value property for the field
    Returns: The populated value property for the field
*/
function populate_quote($value){
    global $wpdb;
    $curr_oid = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    $curr_qid = filter_input( INPUT_GET, "qid", FILTER_SANITIZE_STRING );
    if ($curr_qid == NULL) { //if the qid was not passed in the URL query, check if it is in a session variable
        if(isset($_SESSION['accepted_qid'])) {
        $curr_qid = $_SESSION['accepted_qid'];
        }
    }
    if($curr_qid != NULL) {
        if(!is_user_auth_view_qid ($curr_qid)) send_not_auth(); //if the current user is not authorized to view the order, send to a not authorized page
        $qidquery =  "SELECT * FROM quotes WHERE qid = '$curr_qid'";
        $qid_data = $wpdb->get_row( $qidquery, ARRAY_A );

        //get field name:
        $filter = current_filter();
        if(!$filter) return '';
        $field = str_replace('gform_field_value_', '', $filter);
        if(!$field) return '';
        $return_field = $qid_data[$field];
        if(strpos($field, "date")) { //if this is a date, reformat to mm/dd/yyyy
            $return_field = date("m/d/Y", strtotime($return_field));
        }

        return $return_field;
    }
    else return "OID Not Found";
}

/*
    Function: return_oid_only
    This function edits the wpdatatable query to only return the order requested through the URL, if the table being displayed is meant for that purpose.
    Parameters: Original query, and the table_id
    Returns: The modified query if this is the order table, and the original query otherwise
*/
function return_oid_only( $query, $table_id ) {
    if (($table_id == 7 OR $table_id == 6)) {
        $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
        if(!is_user_auth_view_oid ($oid_filter)) send_not_auth(); //if the current user is not authorized to download the file, send to a not authorized page

        if($oid_filter != NULL) {
            $query = str_replace(" LIMIT 10", "", $query);
            $query .= " WHERE oid = $oid_filter";
            //echo '<pre>' . print_r($query,true) . '</pre>';
        }
    }
    return $query;
}

/*
    Function: add_quote_f
    This function populates the quote DB when an admin adds a new quote and assigns it to a vendor.
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function add_quote_f( $entry, $form) {
    global $wpdb;
    global $BID_COST_NON_3D_PRINT_EACH;
    global $BID_COST_3D_PRINT_EACH;
    $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    $vid_to_add = $entry['1'];

    $order_type = $wpdb->get_var("SELECT order_type FROM orders WHERE oid = $oid_filter");
    $bid_reimbursement = 0;
    if($order_type == '3D-Printed Part') $bid_reimbursement = $BID_COST_NON_3D_PRINT_EACH;
    else $bid_reimbursement = $BID_COST_3D_PRINT_EACH;

    $quote_due = $wpdb->get_var("SELECT quotes_due FROM orders WHERE oid = $oid_filter");
    $quote_fee = $wpdb->get_var("SELECT fee FROM orders WHERE oid = $oid_filter");

    $curtime_mysql = current_time('mysql'); //current time in the mysql format in the server's time zone (EST)

    $wpdb->query($wpdb->prepare( //add quote to DB
        "INSERT INTO quotes (vid, oid, quote_initiated_date, quote_due, bid_reimburse, quote_fee)
        VALUES (%d,%s,%s,%s,%d,%s)",
        $vid_to_add, $oid_filter, $curtime_mysql, $quote_due, $bid_reimbursement, $quote_fee));

    $wpdb->query($wpdb->prepare( //add one to number of quotes requested for the order
        "UPDATE orders SET num_quotes_requested = num_quotes_requested + 1 WHERE oid = %d",
        $oid_filter));

    $new_qid = $wpdb->insert_id; //get the qid

    if ($new_qid != FALSE) {
        $wpdb->insert('logs', array('user_type' => 'admin', 'log_type' => 'Quote Requested', 'log_entry' => "New Quote Request $new_qid created")); //log the event
    } else {
        $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Error', 'log_entry' => "New Quote Request for order $oid_filter failed")); //log the event
    }
}

function check_vid_for_quote($result, $value, $form, $field) {
    global $wpdb;
    $vid_exists = $wpdb->get_var("SELECT COUNT(vid) FROM vendor_info WHERE vid = $value");
    if (!$vid_exists) {
        $result['is_valid'] = false;
        $result['message']  = 'This vid does not exist. Please try again.';
    } else {
        $vid_avail = $wpdb->get_var("SELECT COUNT(vid) FROM vendor_info WHERE (vid = $value AND accept_orders = 1)");
        if (!$vid_avail) {
            $result['is_valid'] = false;
            $result['message']  = 'This vendor is not accepting new quotes. Please try again.';
        }
        else $result['is_valid'] = true;
    }
    return $result;
}

/*
    Function: check_promise_date
    This function checks whether the promise date entered is valid
    Parameters: The entry submitted, the order id, the form object, and the field
    Returns: A boolean indicating whether promise date is valid
*/
function check_promise_date($result, $value, $form, $field) {
    global $wpdb;
    $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    //$atp_date_query =  "SELECT DATE_FORMAT(atp_due, '%Y-%m-%d') FROM orders WHERE oid = '$oid_filter'";
    $atp_date_query =  "SELECT atp_due FROM orders WHERE oid = '$oid_filter'";
    $atp_date = $wpdb->get_var($atp_date_query);
    $promise_date = date("Y-m-d", strtotime($value));
    if ($promise_date <= $atp_date) {
        $result['is_valid'] = false;
        $result['message']  = "Promise date cannot be earlier than assumed ATP (see below).";
    } else $result['is_valid'] = true;

    return $result;
}

/*
    Function: can_assign_order
    This function checks whether the order is available for assignment to new vendors
    Parameters: The entry submitted, the order id, the form object, and the field
    Returns: A boolean indicating assignment permissibility
*/
function can_assign_order($result, $value, $form, $field) {
    global $wpdb;
    $order_phase_query =  "SELECT order_phase FROM orders WHERE oid = '$value'";
    $order_phase = $wpdb->get_var($order_phase_query);
    var_dump($order_phase);
    if ($order_phase != "Order Created - Quote Requests In Process") {
        $result['is_valid'] = false;
        $result['message']  = "Selected Order ID is not available for assignment.";
    } else $result['is_valid'] = true;

    return $result;
}

/*
    Function: cust_quote_f
    This function populates the quote DB when a customer selects a bid.
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function cust_quote_f( $entry, $form) {
    global $wpdb;
    $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    $curtime_mysql = current_time('mysql'); //curent time in the mysql format in the server's time zone (EST)
    if ($entry['6'] == 'Accept A Bid') {
        $quote_selected = $entry['7']; //find the desired quote

        $wpdb->update( //update the selected quote with the accepted status
        'quotes',
        array('quote_status' => 'Quote Accepted', 'quote_cust_response_date' => $curtime_mysql, 'quote_cust_name' => $entry['13'], 'quote_cust_title' => $entry['3']),
        array('qid' => "$quote_selected")
        );

        $wpdb->update( //update all other quotes with the not accepted status
        'quotes',
        array('quote_status' => 'Quote Not Accepted', 'quote_cust_response_date' => $curtime_mysql, 'quote_cust_name' => $entry['13'], 'quote_cust_title' => $entry['3']),
        array('oid' => "$oid_filter", 'quote_status' => 'Under Consideration')
        );

        $quote_row = $wpdb->get_row("SELECT * FROM quotes WHERE qid = $quote_selected", ARRAY_A);
        $order_row = $wpdb->get_row("SELECT * FROM orders WHERE oid = $oid_filter", ARRAY_A);

        $expand_cost = $quote_row['quote_cost_ea'] * $order_row['qty'];

        $wpdb->update( //update the order with the order placed status
            'orders',
            array(
                'order_phase' => 'Order Placed',
                'order_placed_date' => $curtime_mysql,
                'cost_ea' => $quote_row['quote_cost_ea'],
                'ship_cost' => $quote_row['quote_ship_cost'],
                'cost_expanded' => $expand_cost,
                'vendor_price' => $quote_row['quote_vendor_price'],
                'fee' => $quote_row['quote_fee'],
                'cost_total' => $quote_row['quote_total_cost'],
                'accepted_qid' => $quote_selected,
                'vid' => $quote_row['vid']),
            array('oid' => "$oid_filter")
            );
    } else { //if the customer cancels the order
        $wpdb->update( //update all quotes for the order with the cancelled status
            'quotes',
            array('quote_status' => 'Order Cancelled By Customer'),
            array('oid' => "$oid_filter")
            );

        $wpdb->update( //update the order with the cancelled status
            'orders',
            array('order_phase' => 'Order Cancelled By Customer', 'order_cancelled_date' => $curtime_mysql),
            array('oid' => "$oid_filter")
            );
    }
    send_quote_select_emails($oid_filter);
    $wpdb->insert('logs', array('user_type' => 'customer', 'log_type' => 'Quote Selected', 'log_entry' => "Quote $quote_selected selected for order $oid_filter")); //log the event
}

/*
    Function: send_quote_select_emails
    This function emails the vendor with the final status of their quotes (accepted, rejected, cancelled)
    Parameters: The relevant OID
    Returns: Nothing
*/
function send_quote_select_emails($oid_filter) {
    global $wpdb;
    $order_row = $wpdb->get_row("SELECT * FROM orders WHERE oid = '$oid_filter'", ARRAY_A); //get all of the order details
    $related_quotes = $wpdb->get_results("SELECT * FROM quotes WHERE oid = '$oid_filter'", ARRAY_A); //get all related quotes
    $order_name = $order_row['order_name'];

    foreach ($related_quotes as $quote){
        $vid = $quote['vid'];
        $vendor_row = $wpdb->get_row("SELECT * FROM vendor_info WHERE vid = '$vid' LIMIT 1", ARRAY_A);
        $subject = "";
        $msg = "";
        if($quote['quote_status'] = 'Quote Accepted') {
            $subject = 'Your Quote Was Accepted!';
            $msg = "Congratulations! Your quote for Order $order_name (Order Number $oid_filter) was accepted. Please log in to see the order details.";
        } else if ($quote['quote_status'] = 'Quote Not Accepted') {
            $subject = 'Your Quote Was Not Accepted';
            $msg = "Your quote for Order $order_name (Order Number $oid_filter) was not accepted. However your account will be credited
            for your bid per our terms.";
        } else {
            $subject = 'Order Has Been Cancelled';
            $msg = "Your quote for Order $order_name (Order Number $oid_filter) was not accepted because the order was cancelled by the customer
            prior to selecting a winning bid. However your account will be credited for your bid per our terms.";
        }
        $email1 = $vendor_row['contact_email'];
        $email2 = $vendor_row['alt_email'];
        $email_array = array($email1);
        if ($email2 != NULL) {
            $email_array[] = $email2;
        }
        $headers[] = 'From: Merafab Notify <notify@merafab.com>';
        wp_mail($email_array, $subject, $msg, $headers);
    }
}

/*
    Function: vendor_quote_f
    This function populates the quote DB when a vendor responds to a quote request.
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function vendor_quote_f( $entry, $form) {
    global $wpdb;
    global $FEE_PERCENT;
    $qid_filter = filter_input( INPUT_GET, "qid", FILTER_SANITIZE_STRING );
    $curtime_mysql = current_time('mysql'); //curent time in the mysql format in the server's time zone (EST)
    if ($entry['12'] == 'Bid on this order') {
        $vendor_price = $entry['5'];
        $fee = round($vendor_price * $FEE_PERCENT, 2);
        $cost_total = $vendor_price + $fee;
        $data = array(
            'quote_status' => 'Under Consideration',
            'quote_response_received_date' => $curtime_mysql,
            'quote_comments' => $entry['14'],
            'quote_cost_ea' => str_replace('$', '', $entry['1']),
            'quote_ship_cost' => str_replace('$', '', $entry['10']),
            'quote_vendor_price' => $entry['5'],
            'quote_fee' => $fee,
            'quote_total_cost' => $cost_total,
            'quote_promise_date' => $entry['11'],
            'quote_vendor_name' => $entry['16'],
            'quote_vendor_title' => $entry['9']
            );
    } else {
        $data = array(
            'quote_status' => 'No Bid',
            'quote_response_received_date' => $curtime_mysql,
            'quote_comments' => $entry['14']
            );
    }

    $wpdb->update( //update the quote with the vendor inputs
        'quotes',
        $data,
        array('qid' => "$qid_filter")
        );

    $wpdb->insert('logs', array('user_type' => 'vendor', 'log_type' => 'Quote Response', 'log_entry' => "Quote $qid_filter received")); //log the event
//add one to number of quotes received for the order:
    $oid_row = $wpdb->get_row("SELECT * FROM quotes WHERE qid = '$qid_filter' LIMIT 1", ARRAY_A);
    $oid_filter = $oid_row['oid'];
    $wpdb->query($wpdb->prepare(
        "UPDATE orders SET num_quotes_received = num_quotes_received + 1 WHERE oid = %d LIMIT 1",
        $oid_filter));
    //If all quotes are now received, end the bidding process:
    $parent_order = $wpdb->get_row("SELECT * FROM orders WHERE oid = $oid_filter", ARRAY_A);
    if($parent_order['num_quotes_received'] == $parent_order['num_quotes_requested']) {
            end_bidding($oid_filter, false);
    }
}

/*
    Function: add_vendor_f
    This function populates the vendor DB when a New Vendor form is submitted
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function add_vendor_f($entry, $form) {
        global $wpdb;

        $data;
        $field_type;
        add_contact_only($entry, $form, $data, $field_type);
        add_vendor_specific_info($entry, $form, $data, $field_type);

        $wpdb->insert(
         'vendor_info',
         $data,
         $field_type
        );
        $lastid = $wpdb->insert_id;
        $contact_email = $data['contact_email'];
        $wpdb->insert('logs', array('user_type' => 'vendor', 'log_type' => 'New Vendor', 'log_entry' => "Vendor $contact_email with ID $lastid registered")); //log the event
        $wpdb->insert('user_tracking', array('user_type' => 'vendor', 'user_id' => $lastid, 'referral_source' => $entry['13'])); //add the user tracking data
        /*
        echo '<pre>' . print_r($data,true) . '</pre>';
        echo '<pre>' . print_r($field_type,true) . '</pre>';
        */
}

/*
    Function: add_ship_info_f
    This function populates the order DB with shipment information when a Shipped form is submitted, and emails the customer
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function add_ship_info_f($entry, $form) {
    global $wpdb, $SHIP_AND_INSPECT_DAYS;
    $current_vid = return_vid();
    $order_ship_carrier = $entry['1'];
    if ($order_ship_carrier == "Other") $order_ship_carrier = $entry['2'];
    $ship_date_mysql = date("Y-m-d H:i:s");
    $inspect_due_mysql = no_holidays(date("Y-m-d"), date("Y-m-d",strtotime(" + ". $SHIP_AND_INSPECT_DAYS . ' weekdays'))); //current time converted to unix format, quote duration added, converted back to mysql format
    $order_tracking = $entry['3'];
    $data = array(
        'order_phase' => 'Order Shipped',
        'order_ship_carrier' => $order_ship_carrier,
        'order_ship_tracking' => $order_tracking,
        'order_shipped_date' => $ship_date_mysql,
        'inspect_due_date' => $inspect_due_mysql
    );
    $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    $wpdb->update(
        'orders',
        $data,
        array('oid' => "$oid_filter")
    );
    $wpdb->insert('logs', array('user_type' => 'vendor', 'log_type' => 'Order Shipped', 'log_entry' => "Order $oid_filter Shipped")); //log the event

    $order_row = $wpdb->get_row("SELECT * FROM orders WHERE oid = '$oid_filter'", ARRAY_A);
    $cid = $order_row['cid'];
    $cust_row = $wpdb->get_row("SELECT * FROM cust_info WHERE cid = '$cid'", ARRAY_A); //get all of the customer contact details

    $email1 = $cust_row['contact_email'];
    $email2 = $cust_row['alt_email'];
    $email_array = array($email1);
    if ($email2 != NULL) {
        $email_array[] = $email2;
    }
    $headers[] = 'From: Merafab Notify <notify@merafab.com>';

    $msg = "Your order $order_name (Order Number $oid_filter) has been shipped via $order_ship_carrier with tracking number $order_tracking";
    wp_mail($email_array, "Order Shipped!", $msg, $headers);
}

/*
    Function: no_holidays
    This function modifies the requested end_date to account for holidays
    Parameters: The desired start and end dates
    Returns: The new end date modified to add holidays
*/
function no_holidays($start_date, $end_date) {
    global $HOLIDAYS;
    $iterate_date = $start_date; //start checking at start_date
    $num_holidays = 0;
    while ($iterate_date <= $end_date) {
        if (in_array($iterate_date, $HOLIDAYS)) { //if the current iteration of date is found in the holidays column
            $num_holidays++; //add one to the number of holidays encountered
        }
        $iterate_date = date('Y-m-d', strtotime($iterate_date . ' +' . 1 . ' Weekday'));
    }
    return date('Y-m-d', strtotime($end_date . ' +' . $num_holidays . ' Weekday'));
}

/*
    Function: start_dispute_f
    This function updates the order with a dispute status and creates a new dispute entry in the DB, when a dispute form is submitted
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function start_dispute_f($entry, $form) {
    global $wpdb;
    $oid_filter = filter_input( INPUT_GET, "oid", FILTER_SANITIZE_STRING );
    $dispute_date_mysql = date("Y-m-d H:i:s");
    $usercid = return_cid();
    $data = array(
        'oid' => $oid_filter,
        'short_desc' => $entry['1'],
        'long_desc' => $entry['2'],
        'initiated_date' => $dispute_date_mysql,
        'contact_name' => $entry['7'],
        'contact_email' => $entry['4'],
        'contact_phone' => $entry['5']
    );
    $wpdb->update(
        'disputes',
        $data,
        array('oid' => "$oid_filter")
    );
    $wpdb->update(
        'orders',
        array('order_phase' => 'Order Dispute', 'order_dispute_start_date' => $dispute_date_mysql),
        array('oid' => "$oid_filter")
    );
    $wpdb->insert('logs', array('user_type' => 'vendor', 'log_type' => 'New Dispute', 'log_entry' => "Dispute Initiated for Order $oid_filter")); //log the event
    $dispute_files = $entry['6'];
    upload_files_from_gform($dispute_files, $oid_filter, $usercid);
}

/*
    Function: update_vendor_f
    This function updates the vendor DB when a vendor profile update form is submitted
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function update_vendor_f( $entry, $form) {
        global $wpdb;

        $current_user = wp_get_current_user();
        $username = $current_user->user_login;

        $data;
        $field_type;
        add_contact_only($entry, $form, $data, $field_type);
        add_vendor_specific_info($entry, $form, $data, $field_type);

        $wpdb->update(
        'vendor_info',
        $data,
        array('contact_email' => "$username"),
        $field_type
        );
        $wpdb->insert('logs', array('user_type' => 'vendor', 'log_type' => 'Update Vendor Profile', 'log_entry' => "Vendor $username updated profile")); //log the event
}

/*
    Function: update_vendor_f
    This is a helper function which is used to populate vendor-specific form info for new or updated vendor profiles
    Parameters: The entry submitted, the form object, and the data array and field_type array by reference
    Returns: Nothing
*/
function add_vendor_specific_info ( $entry, $form, &$data, &$field_type) {
        //cap_set corresponds to binary number with a 1 in each position where a checkbox is checked
        $cap_set = 0;
        for ($cap_num = 1; $cap_num <= 9; $cap_num++) {
                $entry_num = "10.{$cap_num}";
                if($entry[$entry_num] != '') {
                        $cap_set = $cap_set | (1<<($cap_num-1));
                }
        }

        $data = array_merge($data, array('capability_extra' => $entry['11'], 'capability' => $cap_set, 'professional' => $entry['15']));
        $field_type[] = '%d';
        $field_type[] = '%s';
        $field_type[] = '%d';
}

/*
    Function: add_cust_f
    This function populates the customer DB when a New Customer form is submitted
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function add_cust_f( $entry, $form) {
        global $wpdb;

        $data;
        $field_type;
        add_contact_only($entry, $form, $data, $field_type);

        $wpdb->insert(
         'cust_info',
         $data,
         $field_type
        );
        $lastid = $wpdb->insert_id;
        $contact_email = $data['contact_email'];
        $wpdb->insert('logs', array('user_type' => 'customer', 'log_type' => 'New Customer', 'log_entry' => "Customer $contact_email with ID $lastid registered")); //log the event
        $wpdb->insert('user_tracking', array('user_type' => 'customer', 'user_id' => $lastid, 'referral_source' => $entry['13'])); //add the user tracking data

}

/*
    Function: update_cust_f
    This function updates the customer DB when a profile update form is submitted
    Parameters: The entry submitted, and the form object
    Returns: Nothing
*/
function update_cust_f( $entry, $form) {
    global $wpdb;

    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    $data;
    $field_type;
    add_contact_only($entry, $form, $data, $field_type);

    $wpdb->update(
     'cust_info',
     $data,
     array('contact_email' => "$username"),
     $field_type
    );

    $wpdb->insert('logs', array('user_type' => 'vendor', 'log_type' => 'Update Customer Profile', 'log_entry' => "Customer $username updated profile")); //log the event
}

/*
    Function: add_contact_only
    This is a helper function which populates contact information common to both vendors and customers into the data variable
    Parameters: The entry submitted, the form object, and the data array and field_type array by reference
    Returns: Nothing
*/
function add_contact_only( $entry, $form, &$data, &$field_type) {
        $data = array(
                'name' => $entry['1'],
                'street_addr' => $entry['3.1'],
                'street_addr2' => $entry['3.2'],
                'city' => $entry['3.3'],
                'state' => $entry['3.4'],
                'zip' => $entry['3.5'],
                'contact_name_first' => $entry['2.3'],
                'contact_name_last' => $entry['2.6'],
                'contact_phone' => $entry['4'],
                'contact_email' => $entry['5']
        );

        $field_type = array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s');

        if($entry['6'] == 'Yes') {
                $data = array_merge(
                        $data,
                        array(
                                'alt_name_first' => $entry['7.3'],
                                'alt_name_last' => $entry['7.6'],
                                'alt_phone' => $entry['8'],
                                'alt_email' => $entry['9']
                        )
                );
                $field_type = array_merge($field_type, array('%s','%s','%s','%s'));
        }
}

/*
    Function: add_order_f
    This function populates order information into the DB when a New Order form is submitted. It then encrypts and uploads the provided files, and populates the files DB.
    Parameters: The entry submitted, the form object
    Returns: Nothing
*/
function add_order_f( $entry, $form) {
        global $wpdb;
        global $QUOTE_DUR_NON_3D_PRINT_DAYS;
        global $QUOTE_DUR_3D_PRINT_DAYS;
        global $QUOTE_SELECTION_DAYS;

        $dsr_input = substr($entry['4'],0,1); //Populate DSR per first character in string
        $usercid = return_cid();
        $order_type = $entry['18'];
        $quote_dur;
        if ($order_type == '3D-Printed Part') {
            $quote_dur = $QUOTE_DUR_3D_PRINT_DAYS;
        } else $quote_dur = $QUOTE_DUR_NON_3D_PRINT_DAYS;

        //$curtime_mysql = current_time('mysql'); //curent time in the mysql format in the server's time zone (EST)
        $quotes_due_mysql = no_holidays(date("Y-m-d"),date("Y-m-d",strtotime(" + ". $quote_dur. ' weekdays'))); //current time converted to unix format, quote duration added, converted back to mysql format
        $atp_mysql = no_holidays($quotes_due_mysql,date("Y-m-d",strtotime($quotes_due_mysql. " + ". $QUOTE_SELECTION_DAYS. ' weekdays'))); //quotes due time converted to unix format, selection duration added, converted back to mysql format

        $data = array(
                'cid' => $usercid,
                'dsr' => $dsr_input,
                'order_name' => $entry['1'],
                'need_date' => $entry['8'],
                'material' => $entry['5'],
                'ship_name' => $entry['13'],
                'ship_street' => $entry['12.1'],
                'ship_street2' => $entry['12.2'],
                'ship_city' => $entry['12.3'],
                'ship_state' => $entry['12.4'],
                'ship_zip' => $entry['12.5'],
                'qty' => $entry['2'],
                'order_addl_rqmts' => $entry['10'],
                'order_comments' => $entry['11'],
                'quotes_due' => $quotes_due_mysql,
                'atp_due' => $atp_mysql,
                'order_type' => $order_type,
                'bid_cost' => $entry['19']
                );
        //Checks if firm need by parsing string for Yes
        $f_need = false;
        if (strpos($entry['9'], 'Yes') !== false) {
            $f_need = true;
        }

        //Extracts qual level from first character of string
        $qual_reqd = $entry['6'];
        $qual_reqd_num = $qual_reqd[0];

        //qual_set corresponds to binary number with a 1 in each position where a checkbox is checked
        $qual_set = 0;
        for ($q_num = 1; $q_num <= 5; $q_num++) {
                $entry_num = "7.{$q_num}";
                if($entry[$entry_num] != '') {
                        $qual_set = $qual_set | (1<<($q_num-1));
                }
        }
        $data = array_merge($data, array('firm_need' => $f_need, 'order_vqual' => $qual_set, 'order_qual' => $qual_reqd_num));
        //Insert Order to DB
        $wpdb->insert(
         'orders',
         $data
         );
        $oid = $wpdb->insert_id; //Get auto-increment order id (oid)
        $order_files = $entry['3'];
        upload_files_from_gform($order_files, $oid, $usercid);
        $wpdb->insert('logs', array('user_type' => 'customer', 'log_type' => 'New Order', 'log_entry' => "Order $oid created")); //log the event

        //send_alert("Thank you, your order has been submitted. We will email you when quotes have been received.");
}

function send_alert($msg) {
    echo '<script type="text/javascript">alert($msg);</script>';
}

/*
    Function: upload_files_from_gform
    This helper function parses the submitted files, encrypts them, and creates entries in the files table
    Parameters: The entry from the upload field, and the related order id and customer id
    Returns: Nothing
*/
function upload_files_from_gform($remaining_files, $oid, $usercid) {
    global $wpdb;
    while (true) {
            $url_start = strpos($remaining_files, 'http');
            if ($url_start === false)
                break;
            $url_end = strpos($remaining_files, ',') - 1;
            if ($url_end == -1)
                $url_end = strpos($remaining_files, ']') - 1;
            $url_len = $url_end - $url_start;
            $url = substr($remaining_files, $url_start, $url_len);

            $fnamestart = strrpos($url, "\\");
            $fname = substr($url, $fnamestart + 2);

            $abspath = "./user-uploads/orders/cid_$usercid/$fname";
            //Encrypt file
            encrypt_file($abspath);

            //Insert file to DB
            $wpdb->insert(
                'files',
                array('url' => $url, 'fname' => $fname, 'oid' => $oid, 'cid' => $usercid),
                array('%s','%s','%d')
                );

            //Remove url from future scans of string
            $remaining_files = substr($remaining_files, $url_end + 2);
        }
}

function test_encrypt_decrypt() {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
    // Create some data to encrypt
    $data = "Encrypt me, please!";
    echo "Before encryption: $data ";
    // Encrypt $data using aes-256-cbc cipher with the given encryption key and
    // our initialization vector. The 0 gives us the default options, but can
    // be changed to OPENSSL_RAW_DATA or OPENSSL_ZERO_PADDING
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', AES_KEY, 0, $iv);
    echo "Encrypted: $encrypted  ";
    // If we lose the $iv variable, we can't decrypt this, so append it to the
    // encrypted data with a separator that we know won't exist in base64-encoded
    // data
    $separator = "separator";
    $encrypted = $encrypted . $separator . $iv;
    // To decrypt, separate the encrypted data from the initialization vector ($iv)
    $parts = explode($separator, $encrypted);
    // $parts[0] = encrypted data
    // $parts[1] = initialization vector
    $decrypted = openssl_decrypt($parts[0], 'AES-256-CBC', AES_KEY, 0, $parts[1]);
    echo "Decrypted: $decrypted ";
}

/*
    Function: encrypt_file
    This function encrypts a file by using AES-256 per the openssl library. The key is stored in a secret location. The initialization vector is added
    to the end of the encrypted contents, with a separator in between which can be easily identified when decrypting.
    Parameters: The path to the file to encrypt
    Returns: Nothing
*/
function encrypt_file($file_path) { //encrypt a file using AES
    $file_contents = file_get_contents($file_path);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
    $encrypted_file = openssl_encrypt($file_contents, 'AES-256-CBC', AES_KEY, 0, $iv);
    // If we lose the $iv variable, we can't decrypt this, so append it to the
    // encrypted data with a separator that we know won't exist in base64-encoded data
    $separator = "separator";
    $encrypted_file = $encrypted_file . $separator . $iv;
    file_put_contents($file_path, $encrypted_file, LOCK_EX); //replace the current file with its encrypted version in the same location
}

/*
    Function: decrypt_file
    This function decrypts a file by using AES-256 per the openssl library. The key is stored in a secret location. The initialization vector is read
    from the end of the encrypted contents, by identifying the separator string. The file at the path is left encrypted, only a temporary variable
    contains the decrypted data.
    Parameters: The path to the file to decrypt
    Returns: The decrypted data
*/
function decrypt_file($file_path) { //decrypt a file using AES
    $separator = "separator";
    $encrypted_file = file_get_contents($file_path);

    $parts = explode($separator, $encrypted_file);
    // $parts[0] = encrypted data
    // $parts[1] = initialization vector
    $decrypted_file = openssl_decrypt($parts[0], 'AES-256-CBC', AES_KEY, 0, $parts[1]);
    return $decrypted_file;
}

/*
    Function: download_file
    This function reads the file ID contained in the URL, and finds the related file name and customer ID. It then creates the needed path to the file,
    asks decrypt_file to decrypt the file, and then forces a download to the user.
    Parameters: The path to the file to decrypt
    Returns: The decrypted data
*/
function download_file() {
    global $wpdb;
    $file_fid = filter_input( INPUT_GET, "fid", FILTER_SANITIZE_STRING );
    $fnamequery =  "SELECT fname FROM files WHERE fid = '$file_fid' LIMIT 1";
    $file_fname = $wpdb->get_var($fnamequery);
    $cidquery =  "SELECT cid FROM files WHERE fid = '$file_fid' LIMIT 1";
    $file_cid = $wpdb->get_var($cidquery);
    $oidquery =  "SELECT oid FROM files WHERE fid = '$file_fid' LIMIT 1";
    $file_oid = $wpdb->get_var($oidquery);

    if(!is_user_auth_view_oid ($file_oid)) send_not_auth(); //if the current user is not authorized to download the file, send to a not authorized page

    $abspath = "./user-uploads/orders/cid_$file_cid/$file_fname";
    $decrypted = decrypt_file($abspath);

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'. $file_fname .'"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    echo $decrypted; //force the download
    //log the download:
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'File Download', 'log_entry' => "File $file_fid downloaded by user $username")); //log the event
    exit; //kill the script
}

register_activation_hook(__FILE__, 'event_activation');

/*
    Function: event_activation
    This function ensures that the daily_tasks function is called once per day. Note wp scheduler is only called during
    site visits, so it may be called late depending on when the site is visited.
    Parameters: None
    Returns: Nothing
*/
function event_activation() {
    //date_default_timezone_set('America/New_York'); //set EST
    //echo date_default_timezone_get();
    //$threeam_EST = mktime(8); //8am GMT = 3am EST so that it is at least midnight on the Pacific coast.
    if (! wp_next_scheduled ( 'daily_event' )) { //if the next event has not been scheduled
    wp_schedule_event(str_to_time("8:00:00"), 'daily', 'daily_event'); //schedule the daily event at 3am EST
    }
}

add_action('daily_event', 'daily_tasks'); //at the daily event, call the daily_tasks function

/*
    Function: daily_tasks
    This function calls any other functions necessary for daily routing tasks/checks
    Parameters: None
    Returns: Nothing
*/
function daily_tasks() {
    global $wpdb;

    // add code here to process time-dependent scripts (ie close late quotes, late responses, email vendors about late orders, etc)
    process_order_quotes_due();
    process_bid_selection_due();
    process_inspections_due();
    //add process_order_quotes_complete to check for early quote completion
    $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Daily Tasks Completed')); //log the event
}

/*
    Function: process_order_quotes_due
    This function searches all orders out for bid to find those where the bid period is complete or all bids have been received, and advances the order phase and expires any remaining quotes left open
    Parameters: None
    Returns: Nothing
*/
function process_order_quotes_due() {
    global $wpdb;
    //get all orders awaiting bids
    $completed_quotes_order_query = "SELECT * FROM orders WHERE order_phase = 'Quote Requests Sent - Awaiting Vendor Responses'";
    $completed_order_quotes = $wpdb->get_results($completed_quotes_order_query, ARRAY_A);
    foreach($completed_order_quotes as $openo) { //loop through and find any order where the quote period expired or all quotes were received
        if($openo['quotes_due'] < date("Y-m-d")) {
            $curr_oid = $openo['oid'];
            end_bidding($curr_oid,true); //end bid period based on time
        } else if (($openo['num_quotes_received'] == $openo['num_quotes_requested']) && ($open['num_quotes_requested'] != 0)) {
            $curr_oid = $openo['oid'];
            end_bidding($curr_oid,false); //end bid period early due to all quotes received
        }
    }
}

/*
    Function: process_bid_selection_due
    This function searches all orders to find those where the customer has not selected a bid in time, and advances the order phase
    Parameters: None
    Returns: Nothing
*/
function process_bid_selection_due() {
    global $wpdb;
    //get all orders awaiting customer selection
    $bids_await_selection_order_query = "SELECT * FROM orders WHERE order_phase = 'Bids Received - Awaiting Customer Decision'";
    $bids_await_selection = $wpdb->get_results($completed_quotes_order_query, ARRAY_A);
    foreach($bids_await_selection as $openo) { //loop through and find any order where the selection period has expired
        if($openo['atp_due'] < date("Y-m-d")) {
            $curr_oid = $openo['oid'];
            cancel_order($curr_oid); //cancel order because selection window has expired
        }
    }
}

/*
    Function: cancel_order
    This cancels an order by updating the order and quotes status, then sending emails to the related vendor and customer
    Parameters: The order id to cancel
    Returns: Nothing
*/
function cancel_order($curr_oid) {
    global $wpdb;
    $cancel_date_mysql = date("Y-m-d H:i:s");

    $data = array(
        'order_phase' => 'Order Cancelled - Bid Selection Window Expired',
        'order_cancelled_date' => $cancel_date_mysql
        );
    $wpdb->update( //update the orders table with the new status
        'orders',
        $data,
        array('oid' => "$curr_oid")
        );
    cancel_quotes($curr_oid);
    send_quote_select_emails($curr_oid);
    $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Order Cancelled', 'log_entry' => "Order $curr_oid Cancelled Because Bid Selection Window Expired"));
}

/*
    Function: cancel_quotes
    This function cancels all quotes related to a cancelled order by updating the quote status.
    Parameters: The order id being cancelled
    Returns: Nothing
*/
function cancel_quotes($curr_oid) {
    global $wpdb;
    $related_quotes = $wpdb->get_results("SELECT * FROM quotes WHERE oid = '$curr_oid'", ARRAY_A); //check the related quotes
    foreach($related_quotes as $openq) { //loop through all related quotes
        $curr_qid = $openq['qid'];
        $wpdb->update('quotes', array('quote_status' => "Order Cancelled"), array('qid' => "$curr_qid")); //update the quote status to show that the order was cancelled
        reimburse_bid_cost($curr_qid);
    }
}

/*
    Function: process_inspections_due
    This function searches all orders which have been shipped and where the inspection period has expired before the customer has accepted/rejected the order.
    These orders are then referred to the complete_order function.
    Parameters: None
    Returns: Nothing
*/
function process_inspections_due() {
    global $wpdb;
    $inspections_due_query = "SELECT * FROM orders WHERE order_phase = 'Order Shipped' AND inspect_due_date < date('Y-m-d')"; //get all orders where the inspection period has ended
    $inspections_due = $wpdb->get_results($inspections_due_query, ARRAY_A);
    foreach($inspections_due as $openo) { //loop through all found orders
        $curr_oid = $openo['oid'];
        complete_order($curr_oid, true);
    }
}

/*
    Function: complete_order
    This completes (closes) an order by updating the order entry then sending emails to the related vendor and customer
    Parameters: The order id to close, and whether the order is being closed automatically (ie by the system due to inspection period expiration), or by the customer
    Returns: Nothing
*/
function complete_order($curr_oid, $autocomplete) {
    $complete_date_mysql = date("Y-m-d H:i:s");

    $data = array(
        'order_phase' => 'Order Complete',
        'order_complete_date' => $complete_date_mysql
        );
    $wpdb->update( //update the orders table with the new status
        'orders',
        $data,
        array('oid' => "$curr_oid")
        );
    if ($autocomplete) { //if this was initiated by the system because the inspection time has passed
        $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Order Completed', 'log_entry' => "Order $curr_oid Completed because Inspection Period Expired"));
    } else {
        $wpdb->insert('logs', array('user_type' => 'customer', 'log_type' => 'Order Completed', 'log_entry' => "Order $curr_oid Completed by Customer"));
    }

    //Send email to customer letting them know the order has been completed
    $order_row = $wpdb->get_row("SELECT * FROM orders WHERE oid = '$curr_oid'", ARRAY_A); //get all of the order details
    $cid = $order_row['cid']; //find the customer id related to the order
    $vid = $order_row['vid']; //find the vendor id related to the order
    $order_name = $order_row['order_name'];
    $inspect_due = $order_row['inspect_due_date'];
    $cust_row = $wpdb->get_row("SELECT * FROM cust_info WHERE cid = '$cid'", ARRAY_A); //get all of the customer contact details
    $vendor_row = $wpdb->get_row("SELECT * FROM vendor_info WHERE vid = '$vid'", ARRAY_A); //get all of the vendor contact details

    $email1 = $cust_row['contact_email'];
    $email2 = $cust_row['alt_email'];
    $email_array = array($email1);
    if ($email2 != NULL) {
        $email_array[] = $email2;
    }
    $headers[] = 'From: Merafab Notify <notify@merafab.com>';
    $msg;
    if($autocomplete) {
        $msg = "Order $order_name (Order Number $curr_oid) is now complete due to expiration of the inspection period (expired on $inspect_due). Escrow funds have been released to the vendor. Please review the vendor by clicking on THIS LINK. Thank you for using Merafab!";
    } else {
        $msg = "Order $order_name (Order Number $curr_oid) is now complete based on your approval. Escrow funds have been released to the vendor. Please review the vendor by clicking on THIS LINK. Thank you for using Merafab!";
    }
    wp_mail($email_array, "Order $curr_oid Complete", $msg, $headers);

    //Send email to vendor letting them know the order has been completed
    $email1 = $vendor_row['contact_email'];
    $email2 = $vendor_row['alt_email'];
    $email_array = array($email1);
    if ($email2 != NULL) {
        $email_array[] = $email2;
    }
    $headers[] = 'From: Merafab Notify <notify@merafab.com>';
    $msg = "Order $order_name (Order Number $curr_oid) is now complete. Escrow funds will be released to you within XX hours. Thank you for using Merafab!";
    wp_mail($email_array, "Order $curr_oid Complete", $msg, $headers);

    release_escrow($curr_oid);
}

function release_escrow($curr_oid) {
}

function reimburse_bid_cost($curr_qid) {
}

/*
    Function: end_bidding
    This function searches ends the bid process by advancing the order phase, expiring any quotes with no response, and emailing
    the customer to let them know the bids are in.
    Parameters: The order id to end bidding for, and whether we need to check for expired quotes or not. Ie, we do not
    need to check for expired quotes if this function was called early when all quotes were received.
    Returns: Nothing
*/
function end_bidding($curr_oid, $check_quotes) {
    global $MIN_BIDS;
    global $wpdb;
    $curtime_mysql = current_time('mysql'); //current time in the mysql format in the server's time zone (EST)
    $wpdb->update('orders', array('order_phase' => "Bids Received - Awaiting Customer Decision", 'bids_received_date' => $curtime_mysql), array('oid' => "$curr_oid")); //update the order status
    $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Order Phase Advanced', 'log_entry' => "Order $curr_oid advanced to Bids Received")); //log the event
    $related_quotes = $wpdb->get_results("SELECT * FROM quotes WHERE oid = '$curr_oid'", ARRAY_A); //check the related quotes if they need to end
    if($check_quotes) { //if we need to cycle through the quotes to check for expired bids
        foreach($related_quotes as $openq) { //loop through all related quotes
            if ($openq['quote_status'] = 'No Response') { //if a related quote has not yet been responded to, it needs to expire
                $curr_qid = $openq['qid'];
                $wpdb->update('quotes', array('quote_status' => "Quote Expired"), array('qid' => "$curr_qid")); //update the quote status to show expired
                $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Quote Expired', 'log_entry' => "Quote $curr_qid Expired")); //log the event
            }
        }
    }
    //Send email to customer letting them know the quoting period is complete
    $order_row = $wpdb->get_row("SELECT * FROM orders WHERE oid = '$curr_oid'", ARRAY_A); //get all of the order details
    $cid = $order_row['cid']; //find the customer id related to the order
    $order_name = $order_row['order_name'];
    $num_bids = $order_row['num_quotes_received'];
    $atp_due = $order_row['atp_due'];
    $atp_due = date("m/d/Y", strtotime($atp_due)); //reformat date to mm/dd/yyyy format for email
    $cust_row = $wpdb->get_row("SELECT * FROM cust_info WHERE cid = '$cid'", ARRAY_A); //get all of the customer contact details

    $email1 = $cust_row['contact_email'];
    $email2 = $cust_row['alt_email'];
    $email_array = array($email1);
    if ($email2 != NULL) {
        $email_array[] = $email2;
    }
    $headers[] = 'From: Merafab Notify <notify@merafab.com>';
    $msg;
    if ($num_bids == 0) { //if no bids were received
        $wpdb->update('orders', array('order_phase' => "Order Cancelled - No Bids Received"), array('oid' => "$curr_oid")); //update the order status to show cancelled
        $wpdb->insert('logs', array('user_type' => 'system', 'log_type' => 'Order Phase Advanced', 'log_entry' => "Order $curr_oid cancelled - 0 bids received")); //log the event
        $msg = "We are sorry to inform you that your order did not receive any bids. Please review vendor comments for any improvement suggestions.";
    } else { //otherwise bids were received
        $msg = "All quote responses have been received for Order $order_name (Order Number $curr_oid).
        You have until $atp_due to make a selection before the quotes expire. Log in now!";
    }
    wp_mail($email_array, "Bidding Complete!", $msg, $headers);
}

/*
    Function: delete_file_from_server
    This function deletes a file at a given pathname from the server
    Parameters: path name
    Returns: Nothing
*/
function delete_file_from_server($path_name) {
    if (file_exists($path_name)) {
        unlink($path_name);
    }
}
