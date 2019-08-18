<?php
/*------------------------------------------------------------------------
 # plg_jeemasms_pg_paystack - Plugin Paystack For JEEMASMS
 #------------------------------------------------------------------------
 # author    Daydah Concepts Ltd
 # copyright Copyright (C) 2016 daydah.com. All Rights Reserved.
 # @license - https://www.gnu.org/licenses/agpl-3.0.en.html GNU/AGPL3
 # Websites: https://www.github.com/daydah
 # Technical Support:  Forum - http://www.github.com/daydah
 -------------------------------------------------------------------------*/

/*
 * ----------------------------------------- CHANGE LOGS -------------------------------------
 * -Fixed zero amount percentage addition bug
 * -------------------------------------------------------------------------------------------
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

//import the joomla library file
jimport('joomla.plugin.plugin');

class plgJeemaSMSPaystack extends JPlugin
{
  private $_element_name = "paystack";
  private $_plugin = null;
  protected $_pluginparams = null;
  private $_args = null;
  private $_apps = null;
  private $_session = null;
  private $_user = null;


  public function __construct(& $subject, $config)
  {
    $this->_plugin = JPluginHelper::getPlugin( 'jeemasms', 'paystack' );
    $this->_pluginparams = new JRegistry( $this->_plugin->params );
    $this->_apps = JFactory::getApplication();
    $this->_session = JFactory::getSession();
    $this->_user = JFactory::getUser();

    parent::__construct($subject, $config);
  }

  public function onClickBuyNow($args)
  {

    if ($args['element_name'] != $this->_element_name) return;
    $this->_args = $args;
    //see if admin switched on phone checker or not
    $tocheckphone = $this->_pluginparams->get('paystack_number_check');
    if($tocheckphone == 1){
      if(!$this->checkPhoneNumber()){
        $this->redirectBackPhone();
      }
    }

    if(!$this->checkMandatoryParameters()){
		$this->redirectBack();
    }

    $response = $this->doPayment();
  }

  private function checkMandatoryParameters()
  {
  	$can_proceed = true;
	if (
  		($this->_args['package_price'] == '') ||
  		($this->_args['package_id'] == '') ||
  		($this->_args['joomla_user_id'] == '') ||
  		($this->_args['payment_tranx_id'] == '') ||
  		($this->_args['payment_id'] == '') ||
  		($this->_args['payment_currency'] == '')
	   )
     {
		   $can_proceed = false;
	   }

	return $can_proceed;
  }

	private function redirectBack(){
		$menu = $this->_apps->getMenu();
		$items   = $menu->getItems('link', 'index.php?option=com_jeemasms&view=smspackagebuy');

		if(isset($items[0])){
			$itemid = $items[0]->id;
		}else{
			$itemid = 0;
		}

		$msg = JText::_("PLG_PG_GATEWAY_MANDATORY_MISSING");
		$msg_type='error';

		$tpl = '';
		if($this->_args['mobile']=='1'){
			$tpl='tmpl=component&mobile=1&';
		}

		$this->_apps->redirect('index.php?option=com_jeemasms&'.$tpl.'view=smspackagebuy&Itemid='.$itemid,$msg,$msg_type);
 	}

  private function checkPhoneNumber()
  {
    $can_continue = true;
    $currentpn = trim(getUserPhoneNumber());
    $curlen = strlen((string)$currentpn);
    if(empty($currentpn) ||
        ($curlen < 11) ||
        ($curlen > 11)
      )
    {
      $can_continue = false;
    }
    return $can_continue;
  }

  private function redirectBackPhone()
  {//this captures the new error message to reflect the lack of phone number of user
		$menu = $this->_apps->getMenu();
		$items = $menu->getItems('link', 'index.php?option=com_jeemasms&view=smspackagebuy');

		if(isset($items[0])){
			$itemid = $items[0]->id;
		}else{
			$itemid = 0;
		}

		$msg = JText::_("Your phone number is either missing or inaccurate. Please correct it in your account before trying to purchase again. Thank you.");
		$msg_type='error';

		$tpl = '';
		if($this->_args['mobile']=='1'){
			$tpl='tmpl=component&mobile=1&';
		}

		$this->_apps->redirect('index.php?option=com_jeemasms&'.$tpl.'view=smspackagebuy&Itemid='.$itemid,$msg,$msg_type);
 	}

  private function getUserPhoneNumber()
  {
    $curruserid = $this->_user(id);
    /* $curruserid = $this->_args['joomla_user_id']*/
    // Get a db connection.
    $db = JFactory::getDbo();
    // Create a new query object.
    $query = $db->getQuery(true);
    $query->select($db->quoteName('mobile'));
    $query->from($db->quoteName('#__jeemasms_account'));
    $query->where($db->quoteName('jid')." = ".$db->quote($curruserid));
    $db->setQuery($query);
    $thenum =  $db->loadResult();
    return $thenum;
  }

  private function calcFinalAmount($initialval, $extra1, $extratype, $extraval)
  {
		$finvalue = 0; $calcpercent = 0;
	//check if there extra switch is on or off
	if($extra1 == 0){
		//then extra switch is off.
		$finvalue = $initialval;
	}
	else{ //the extra switch is on, so we need to calculate final value
		//check type
		if($extratype == 0){
			//then the type is a percentage
			$finvalue = $initialval + (($extraval * $initialval)/100);
		}
		else{//then the type is a fixed amount
			$finvalue = $initialval + $extraval;
		}

	}
	return $finvalue;
	}

  private function doPayment()
  {
  	if($this->_args['mobile']=='1'){ $this->_session->set('ses_mobile',true);	}
	else{ 		$this->_session->set('ses_mobile',false);  	}

  	//from paystack.xml
  	$paystack_mode = $this->_pluginparams->get('paystack_live_demo');//paystack demo or live setting. default is demo or zero
  	$pl_secret_key = $this->_pluginparams->get('paystack_live_secret_key');//paystack live secret key
  	$pl_public_key = $this->_pluginparams->get('paystack_live_public_key');//paystack live public key
  	$pt_secret_key = $this->_pluginparams->get('paystack_test_secret_key');//paystack test secret key
	$pt_public_key = $this->_pluginparams->get('paystack_test_public_key');//paystack test public key

	//create the keys
	if($paystack_mode == 0){
		$secret_key = $pt_secret_key; $public_key = $pt_public_key;
	}
	else{
		$secret_key = $pl_secret_key; $public_key = $pl_public_key;
	}

	$d_ps_extra = $this->_pluginparams->get('paystack_extra_yes_no');
	$d_ps_extratype = $this->_pluginparams->get('paystack_extra_type');
	$d_ps_extraval = $this->_pluginparams->get('paystack_extra_charges_value');
	$d_ps_initval = $this->_args['package_price'];

  	$currency = $this->_args['payment_currency'];
  	$package_price = $this->calcFinalAmount($d_ps_initval, $d_ps_extra, $d_ps_extratype, $d_ps_extraval);
  	$joomla_user_id = $this->_args['joomla_user_id'];
  	$tranx_id = $this->_args['payment_tranx_id'];
  	$pack_name = $this->_args['package_name'];

	//get user email
	$email = $this->_user->email;


  	$return_url = JURI::base().'plugins/jeemasms/paystack/paystack/pages/return.php';
  	//$ipnurl = JURI::base().'plugins/jeemasms/paystack/paystack/pages/ipn.php';


  	$session = JFactory::getSession();
  	$this->_session->set('session_transaction_id',$tranx_id);


	//build the html and javascript form to send out
  	$tosend = '<p>Your order is being processed. Please wait...</p>
	<form id="paystack-pay-form" action="' . $return_url . '" method="post">
  <script src="https://js.paystack.co/v1/inline.js"></script>
   <button id="paystack-pay-btn" style="display:none" type="button" onclick="payWithPaystack()"> Click here </button>

        </form>

<script>
        function formatAmount(amount) {
            var strAmount = amount.toString().split(".");
            var decimalPlaces = strAmount[1] === undefined ? 0: strAmount[1].length;
            var formattedAmount = strAmount[0];

            if (decimalPlaces === 0) {
                formattedAmount += \'00\';

            } else if (decimalPlaces === 1) {
                formattedAmount += strAmount[1] + \'0\';

            } else if (decimalPlaces === 2) {
                formattedAmount += strAmount[1];
            }

            return formattedAmount;
        }

        var amount = formatAmount("' . $package_price . '");

	function payWithPaystack(){
			var handler = PaystackPop.setup({
			  key: \'' . $public_key . '\',
              email: \'' . $email . '\',
			  amount: amount,
			  ref: \'' . $tranx_id . '\',
			  callback: function(response){
                  document.getElementById(\'paystack-pay-form\').submit();
              },
              onClose: function(){
                  document.getElementById(\'paystack-pay-form\').submit();
              }
            });
            handler.openIframe();
          }
          payWithPaystack();

        </script>';

        echo $tosend;
  }

}
