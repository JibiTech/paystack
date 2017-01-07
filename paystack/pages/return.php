<?php
/*------------------------------------------------------------------------
 # plg_jeemasms_pg_paystack - Plugin Paystack For JEEMASMS
 #------------------------------------------------------------------------
 # author    Daydah Concepts Ltd
 # copyright Copyright (C) 2016 daydah.com. All Rights Reserved.
 # @license - https://www.gnu.org/licenses/agpl-3.0.en.html GNU/AGPL3
 # Websites: https://www.daydah.com
 # Technical Support:  Forum - https://www.daydah.com
 -------------------------------------------------------------------------*/

/*
 * ----------------------------------------- CHANGE LOGS -------------------------------------
 * 
 * -------------------------------------------------------------------------------------------
 */

/* Initialize Joomla framework */
define( '_JEXEC', 1 );
define('JPATH_BASE',realpath(dirname(__FILE__).'/../../../../..'));
define( 'DS', DIRECTORY_SEPARATOR );

/* Required Files */
require_once (JPATH_BASE .DS.'includes'.DS.'defines.php' );
require_once ( JPATH_BASE .DS.'includes'.DS.'framework.php' );

//import the joomla library file
jimport( 'joomla.plugin.helper' );

/* Create the Application */
$mainframe = JFactory::getApplication('site');

// No direct access
defined('_JEXEC') or die('Restricted access');

class PaystackGatewayReturn{

	private $_session = null;
	private $_responseArray = null;
	private $_jinput = null;
	private $_pluginparams = null;

	function __construct(){
		$this->_session = JFactory::getSession();
		$this->_jinput = JFactory::getApplication()->input;
		
		$plugin = JPluginHelper::getPlugin( 'jeemasms', 'paystack' );
		$this->_pluginparams = new JRegistry( $plugin->params );
	}
	
	public function getResponse(){
		$transactionid = $this->_session->get('session_transaction_id');
	
		if($transactionid == '')
		{
			$this->_responseArray['layout'] = "sessionexpired";
			$this->_responseArray['gateway_txn_id'] = $this->_jinput->get('transaction_id');
			$this->_responseArray['payment_tranx_id'] = '';
			$this->_responseArray['gateway_response'] = "Sorry, your payment session got expired. Please try again in a few minutes.";
			
			return $this->_responseArray;
		}
		
		//check if its demo
		if($this->_pluginparams->get('paystack_live_demo')==0){ $secret_key = $this->_pluginparams->get('paystack_test_secret_key');}
		else{ $secret_key = $this->_pluginparams->get('paystack_live_secret_key');}
		
		//call the function that will create the url and use file get contents to get response from Paystack. Returns an array
		$transData = $this->verifyPaystackTransaction($transactionid, $secret_key);
                $sentreference = '';
                if(!property_exists($transData, 'reference')){ $sentreference = $transData->reference;}
		if (!property_exists($transData, 'error') && property_exists($transData, 'status') && ($transData->status === 'success') && (strpos($transData->reference, $transactionid) === 0)) 
		{
			// Update order status - From pending to complete
			$this->_responseArray['layout'] = "success";
			$this->_responseArray['gateway_txn_id'] = $this->_jinput->get('transaction_id');
			$this->_responseArray['payment_tranx_id'] = $sentreference;
			$this->_responseArray['gateway_response'] = "Payment Successfull";
			return $this->_responseArray;
		}
		 
		 else if (property_exists($transData, 'error')) 
		 {
			$this->_responseArray['layout'] = "failure";
			$this->_responseArray['gateway_txn_id'] = $this->_jinput->get('transaction_id');
			$this->_responseArray['payment_tranx_id'] = $sentreference;
			$this->_responseArray['gateway_response'] = "Payment not approved.";
			return $this->_responseArray;
		}
		
		else
		{
			$this->_responseArray['layout'] = "pending";
			$this->_responseArray['gateway_txn_id'] = $this->_jinput->get('transaction_id');
			$this->_responseArray['payment_tranx_id'] = $sentreference;
			$this->_responseArray['gateway_response'] = "Payment status is Pending.";
			return $this->_responseArray;
		}
	}

 private function verifyPaystackTransaction($transactionid, $secret_key){
	 /*$secret_key is either the demo or live secret key from your dashboard. $transactionid is the transaction reference code sent to the API previously */
	 $transactionStatus  = new stdClass();
        $transactionStatus->error = "";

        // try a file_get verification
        $opts = array(
            'http' => array(
                'method' => "GET",
                'header' => "Authorization: Bearer " . $secret_key
            )
        );

        $context  = stream_context_create($opts);
        $url      = "https://api.paystack.co/transaction/verify/" . $transactionid;
        $response = file_get_contents($url, false, $context);

        // if file_get didn't work, try curl
        if (!$response) {
            curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/verify/" . $transactionid);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $secret_key
            ));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, false);

            // Make sure CURL_SSLVERSION_TLSv1_2 is defined as 6
            // cURL must be able to use TLSv1.2 to connect
            // to Paystack servers
            if (!defined('CURL_SSLVERSION_TLSv1_2')) {
                define('CURL_SSLVERSION_TLSv1_2', 6);
            }
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            // exec the cURL
            $response = curl_exec($ch);
            // should be 0
            if (curl_errno($ch)) {
                // curl ended with an error
                $transactionStatus->error = "cURL said:" . curl_error($ch);
            }
            //close connection
            curl_close($ch);
        }

        if ($response) {
            $body = json_decode($response);
            if (!$body->status) {
                // paystack has an error message for us
                $transactionStatus->error = "Paystack API said: " . $body->message;
            } else {
                // get body returned by Paystack API
                $transactionStatus = $body->data;
            }
        } else {
            // no response
            $transactionStatus->error = $transactionStatus->error . " : No response";
        }


        return $transactionStatus;

 }

 }
 ?>

<?php
	echo JText::_('Processing.... Please Wait...');

	$gatewayObj = new PaystackGatewayReturn();
	$response = $gatewayObj->getResponse();
	$website_url = JURI::root()."../../../../..";
?>

<form action="<?php echo JRoute::_($website_url.'/index.php'); ?>" method="POST" name="adminForm" id="adminForm">

<input type="hidden" name="gateway_txn_id" value=<?php echo $response['gateway_txn_id']; ?> />
<input type="hidden" name="gateway_payment_id" value="<?php echo $response['payment_tranx_id']; ?>" />
<input type="hidden" name="gateway_response_msg" value="<?php echo $response['gateway_response']; ?>" />
<input type="hidden" name="gateway_name" value="Paystack" />

<input type="hidden" name="option" value="com_jeemasms" />
<input type="hidden" name="task" value="" />
<input type="hidden" name="view" value="paymentgateway" />
<input type="hidden" name="layout" value="<?php echo $response['layout']; ?>" />
<input type="hidden" name="boxchecked" value="" />
<input type="hidden" name="controller" value="" />

</form>

<script language="javascript">
  document.adminForm.submit();
</script>
