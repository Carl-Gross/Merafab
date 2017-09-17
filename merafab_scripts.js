jQuery.noConflict();

//Independent Functions (called directly from page elements)
function accept_orders_button_script () {
    var button = document.getElementById("accept_orders_button");
     var accept_orders = 0;
    if(button.value == "Accept Orders") {
        button.value="Stop Accepting Orders";
        accept_orders = 1;
    } else {
        button.value="Accept Orders";
        accept_orders = 0;
    }
    var data = {
        'action': 'accept_order_button_ajax', //action passes the name of the action to run on the server-side, which then links to the php function
        'accept_orders': accept_orders
    };
    //console.log(data);
    jQuery.post(ajax_object.ajax_url, data);
}

function complete_order_button_script() {
    var oid = return_query_name('oid');
    var data = {
        'oid_filter' : oid,
        'action': 'complete_order_button_ajax' //action passes the name of the action to run on the server-side, which then links to the php function
    };
    //console.log(data);
    jQuery.post(ajax_object.ajax_url, data);
    alert("Thank you! This order is now complete.");
    window.location = "https://merafab.com/customer-portal/";
}

function initiate_dispute_button_script() {
    var oid = return_query_name('oid');
    window.location = `https://merafab.com/order-dispute/?oid=${oid}`;
}

function uninspected_button_script() {
    var oid = return_query_name('oid');
    var data = {
        'oid_filter' : oid,
        'action': 'uninspected_button_ajax' //action passes the name of the action to run on the server-side, which then links to the php function
    };
    //console.log(data);
    jQuery.post(ajax_object.ajax_url, data);
    alert("Thank you. Order marked as received. Please inspect the order as soon as possible and record the inspection in your portal.");
    window.location = "https://merafab.com/customer-portal/";
}

function all_quotes_sent_button_script() {
    var oid = return_query_name('oid');
    var data = {
        'oid_filter' : oid,
        'action': 'all_quotes_sent_button_ajax' //action passes the name of the action to run on the server-side, which then links to the php function
    };
    //console.log(data);
    jQuery.post(ajax_object.ajax_url, data);
    alert("Order marked as all quotes requested.");
    window.location = "https://merafab.com/admin_open-orders/";
}

function return_query_name(var_name) {
    var url = window.location.search;
    var start_of_var = url.indexOf(var_name) + 1 + var_name.length;//looks for the var name ie oid in ?oid=xxx, then adds 1 to deal with = sign
    var next_var = url.indexOf('&', start_of_var); //starting at the end of the wanted variable, checks if there is another variable
    if (next_var == -1) next_var = url.length;
    return url.substring(start_of_var, next_var);
}

function monitor_bid_dropdown_script() {
    jQuery(document).ready(function() {
        jQuery('#input_15_7').bind('change', function() {
            //get selected value from drop down;
            var selected_value = jQuery("#input_15_7").val();
            var data = {
                'action': 'populate_bid_ajax', //action passes the name of the action to run on the server-side, which then links to the php function
                'quote_selected': selected_value
            };

            jQuery.post(ajax_object.ajax_url, data, function(result, status){
                if(result != 0) {
                    jQuery("#input_15_8").val(result.quote_vendor_price);
                    jQuery("#input_15_10").val(result.quote_fee);
                    jQuery("#input_15_9").val(result.quote_total_cost);
                    var promise_date_formatted = result.quote_promise_date.slice(5,7) + '/' + result.quote_promise_date.slice(8) + '/' + result.quote_promise_date.slice(0,4);
                    jQuery("#input_15_11").val(promise_date_formatted);
                    jQuery("#input_15_12").val(result.quote_comments);
                }
            }, "json");
        });
    });
}

function restrict_need_date_script() {
    gform.addFilter( 'gform_datepicker_options_pre_init', function( optionsObj, formId, fieldId ) {
        if ( formId == 3 && fieldId == 8 ) {
            var business_days = parseInt( php_vars.quote_dur_non_3d, 10 ) + parseInt( php_vars.quote_select_dur, 10 ) + 1;
            var dateMin = new Date();
            var week_days = AddBusinessDays(business_days);
            optionsObj.minDate = week_days;
            optionsObj.maxDate = '+5 Y';
            optionsObj.firstDay = 1;
            optionsObj.beforeShowDay = jQuery.datepicker.noWeekends;
        }
        return optionsObj;
    } );
}

//NOT CURRENTLY WORKING - NO ACTIVE LINKS FROM ANYWHERE - USING FORM VALIDATION INSTEAD
function restrict_promise_date_script() {
    var oid = return_query_name('oid');
    var data = {
        'oid_filter' : oid,
        'action': 'restrict_promise_date_ajax' //action passes the name of the action to run on the server-side, which then links to the php function
    };
    //console.log(data);
    var atp_due;
    var ajax_return = jQuery.post(ajax_object.ajax_url, data, function(result, status){
        //console.log(result);
        if(result != 0) {
            atp_due = result;
            //console.log(atp_due);
        }
    }, "json");
    ajax_return.then(function() { //need to wait on ajax to return due to asynchronous request
        console.log(atp_due);
        console.log(window.location.search);
        promise_date_helper();
    } );
}

function post_bid_script(){
    alert("Thank you for your bid! You will receive an email when the customer makes a selection, or you may track your order in the Vendor Portal.");
    window.location = "https://merafab.com/vendor-portal/";
}

function promise_date_helper(){ //NOT WORKING. GFORM Addfilter does not call the success function.
    gform.addFilter( 'gform_datepicker_options_pre_init', function( optionsObj, formId, fieldId ) {
        console.log('in outer function');
        if ( formId == 12 && fieldId == 11 ) {
            //console.log('in function');
            //console.log(new Date(atp_due).toString());
            optionsObj.minDate = '+5';
            optionsObj.maxDate = '+5 Y';
        }
        return optionsObj;
    } );
}

//Below lines calculate weekday offsets for datepicker functions based on business days, adding time for weekends and holidays
//holidays
var natDays = [ //using 2018 dates
  [1, 1, 'us'],
  [11,22,'us'],
  [11,23,'us'],
  [7,4,'us'],
  [9,3,'us'],
  [5,28,'us'],
  [12, 25, 'us'],
  [12, 24, 'us']
];

function AddBusinessDays(weekDaysToAdd) {
  var curdate = new Date();
  var realDaysToAdd = 0;
  while (weekDaysToAdd > 0){
    curdate.setDate(curdate.getDate()+1);
    realDaysToAdd++;
    //check if current day is business day
    if (noWeekendsOrHolidays(curdate)[0]) {
      weekDaysToAdd--;
    }
  }
  return realDaysToAdd;
}

function noWeekendsOrHolidays(date) {
    var noWeekend = jQuery.datepicker.noWeekends(date);
    if (noWeekend[0]) {
        return nationalDays(date);
    } else {
        return noWeekend;
    }
}
function nationalDays(date) {
    for (i = 0; i < natDays.length; i++) {
        if (date.getMonth() == natDays[i][0] - 1 && date.getDate() == natDays[i][1]) {
            return [false, natDays[i][2] + '_day'];
        }
    }
    return [true, ''];
}