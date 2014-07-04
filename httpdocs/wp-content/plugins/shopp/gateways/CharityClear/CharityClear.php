<?php
/**
 * CharityClear
 *
 * @author Jonathan Davis
 * @copyright Ingenesis Limited, May 2009-2014
 * @package shopp
 * @version 1.3.4
 * @since 1.1
 **/

defined( 'WPINC' ) || header( 'HTTP/1.1 403' ) & exit; // Prevent direct access

class ShoppCharityClear extends GatewayFramework implements GatewayModule {

	// Settings
	public $secure = false;
	public $saleonly = true;

	// URLs
	const LIVEURL = 'https://gateway.charityclear.com/paymentform/';
	const SANDBOX = 'https://sandbox.2checkout.com/checkout/purchase';

	public function __construct () {
		parent::__construct();

		$this->setup('merchantID', 'verify', 'secret', 'testmode');

		add_filter('shopp_purchase_order_charityclear_processing', array($this, 'processing'));
		add_action('shopp_remote_payment', array($this, 'returned'));

	}

	public function actions () { /* Not implemented */ }

	public function processing () {
		return array($this, 'submit');
	}

	public function form ( ShoppPurchase $Purchase ) {


		$fields = array();

		$fields['merchantID']        = str_true( $this->settings['testmode'] ) ? 100003 : $this->settings['merchantID'];
		$fields['amount']            = $this->amount( 'total' ) * 100; // multiply by 100 to remove the floating point number
		$fields['transactionUnique'] = date( 'mdy' ) . '-' . date( 'His' ) . '-' .$Purchase->id; // this will stop a customer paying for the same order twice within 5 minutes
		$fields['action']            = 'SALE'; // sale as action type as we wants all of dems monies
		$fields['type']              = 1; // type =1 for ecommerce, would need to be 2 for moto (staff/phone ordering)
		$fields['redirectURL']       = $this->settings['returnurl']; // the page the customer gets returned to
		$fields['orderRef']       = 	$Purchase->id; // the page the customer gets returned to
		$fields['customerAddress']   = $this->Order->Billing->address . "\n" . $this->Order->Billing->xaddress . "\n" . $this->Order->Billing->city . "\n" . $this->Order->Billing->state .
									   "\n" . $this->Order->Billing->country;
		$fields['customerName']      = $this->Order->Billing->name;
		$fields['customerPostcode']  = $this->Order->Billing->postcode;
		$fields['customerEmail']     = $this->Order->Customer->email;
		$fields['customerPhone']     = $this->Order->Customer->phone;

		ksort( $fields );

		$fields['signature'] = hash( 'SHA512', http_build_query( $fields, '', '&' ) . $this->settings['secret'] ) . '|' . implode( ',', array_keys( $fields ) );

		return $this->format( $fields );



	}

	/**
	 * Builds a form to send the order to PayPal for processing
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @return string PayPal cart form
	 **/
	public function submit ( ShoppPurchase $Purchase ) {
		$id = sanitize_key( $this->module );
		$title = Shopp::__( 'Sending order to Charity Clear&hellip;' );
		$message = '<form id="' . $id . '" action="' . self::LIVEURL . '" method="POST">' .
					$this->form( $Purchase ) .
					'<h1>' . $title . '</h1>' .
					'<noscript>' .
					'<p>' . Shopp::__( 'Click the &quot;Submit Order to Charity Clear&quot; button below to submit your order to Charity Clear for payment processing:' ) . '</p>' .
					'<p><input type="submit" name="submit" value="' . Shopp::__('Submit Order to Charity Clear'). '" id="' . $id . '" /></p>' .
					'</noscript>' .
					'</form>' .
					'<script type="text/javascript">document.getElementById("' . $id . '").submit();</script></body></html>';

		wp_die( $message, $title, array( 'response' => 200 ) );
	}


	public function returned () {

		if ( $this->id() != $_GET['rmtpay'] ) return; // Not our offsite payment


		if ( isset( $_POST['signature'] ) ) {
			// do a signature check
			ksort( $_POST );
			$signature = $_POST['signature'];
			unset( $_POST['signature'] );
			$check = preg_replace( '/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', http_build_query( $_POST, '', '&' ) . $this->settings['secret'] );

			if ( $signature !== hash( 'SHA512', $check ) ) {
				shopp_add_error(Shopp::__( 'The calculated signature of the payment return did not match, for security this order cant complete automatically please contact support.', 'Shopp' ), 'cc_validation_error', SHOPP_TRXN_ERR );
				shopp::redirect( shopp::url( false, 'checkout' ) );

			}
		}

		// do a check to make sure it was actually a good payment
		if ( (int)$_POST['responseCode'] !== 0 ) {
			shopp_add_error(Shopp::__( 'There was a issue with that card, no payment has been taken, please retry', 'Shopp' ), 'cc_validation_error', SHOPP_TRXN_ERR );
			shopp::redirect( shopp::url( false, 'checkout' ) );
		}


		if ( empty($_POST['orderRef']) ) {
			shopp_add_error(Shopp::__('The order submitted by Charity Clear did not specify a transaction ID.'), SHOPP_TRXN_ERR);
			Shopp::redirect(Shopp::url(false, 'checkout'));
		}

		$Purchase = ShoppPurchase(new ShoppPurchase((int)$_POST['orderRef']));
		if ( ! $Purchase->exists() ) {
			shopp_add_error(Shopp::__('The order submitted by Charity Clear did not match any submitted orders.'), SHOPP_TRXN_ERR);
			Shopp::redirect(Shopp::url(false, 'checkout'));
		}



		add_action( 'shopp_authed_order_event', array( ShoppOrder(), 'notify' ) );
		add_action( 'shopp_authed_order_event', array( ShoppOrder(), 'accounts' ) );
		add_action( 'shopp_authed_order_event', array( ShoppOrder(), 'success' ) );

		shopp_add_order_event($Purchase->id, 'authed', array(
			'txnid' => $_POST['xref'],   // Transaction ID
			'amount' => (float)$_POST['ammount']/100,  // Gross amount authorized
			'fees' => false,            // Fees associated with transaction
			'gateway' => $this->module, // The gateway module name
			'paymethod' => 'Charity Clear', // Payment method (payment method label from payment settings)
			'paytype' => $pay_method,   // Type of payment (check, MasterCard, etc)
			'payid' => $invoice_id,     // Payment ID (last 4 of card or check number or other payment id)
			'capture' => true           // Capture flag
		));

		ShoppOrder()->purchase = ShoppPurchase()->id;
		Shopp::redirect( Shopp::url(false, 'thanks', false) );

	}

	public function authed ( ShoppPurchase $Order ) {

		$Paymethod = $this->Order->paymethod();
		$Billing = $this->Order->Billing;

		shopp_add_order_event($Order->id, 'authed', array(
			'txnid' => $_POST['xref'],						// Transaction ID
			'amount' => $_POST['ammount']/100,							// Gross amount authorized
			'gateway' => $this->module,								// Gateway handler name (module name from @subpackage)
			'paymethod' => $Paymethod->label,						// Payment method (payment method label from payment settings)
			'paytype' => $Billing->cardtype,						// Type of payment (check, MasterCard, etc)
			'payid' => $Billing->card,								// Payment ID (last 4 of card or check number)
			'capture' => true										// Capture flag
		));

	}

	protected function verify ( $key ) {
		if ( Shopp::str_true($this->settings['testmode']) ) return true;
		$order = $_GET['order_number'];
		$total = $_GET['total'];

		$verification = strtoupper(md5($this->settings['secret'] .
							$this->settings['sid'] .
							$order .
							$total
						));

		return ( $verification == $key );
	}

	protected function returnurl () {
		return add_query_arg('rmtpay', $this->id(), Shopp::url(false, 'thanks'));
	}

	protected function itemname ( $Item ) {
		$name = $Item->name . ( empty($Item->option->label) ? '' : ' ' . $Item->option->label );
		$name = str_replace(array('<', '>'), '', $name);
		return substr($name, 0, 128);
	}

	public function settings () {

		$this->ui->text(0,array(
			'name' => 'merchantID',
			'size' => 10,
			'value' => $this->settings['merchantID'],
			'label' => __('Your CharityClear merchant ID.','Shopp')
		));


		$this->ui->checkbox(0,array(
			'name' => 'verify',
			'checked' => $this->settings['verify'],
			'label' => __('Enable order verification','Shopp')
		));

		$this->ui->text(0,array(
			'name' => 'secret',
			'size' => 10,
			'value' => $this->settings['secret'],
			'label' => __('Your Charity Clear signature key.','Shopp')
		));

		$this->ui->checkbox(0,array(
			'name' => 'testmode',
			'checked' => $this->settings['testmode'],
			'label' => __('Enable test mode','Shopp')
		));

		$this->ui->text(1, array(
			'name' => 'returnurl',
			'size' => 64,
			'value' => $this->returnurl(),
			'readonly' => 'readonly',
			'class' => 'selectall',
			'label' => __('','Shopp')
		));

		$script = "var tc ='shoppcharityclear';jQuery(document).bind(tc+'Settings',function(){var $=jqnc(),p='#'+tc+'-',v=$(p+'verify'),t=$(p+'secret');v.change(function(){v.prop('checked')?t.parent().fadeIn('fast'):t.parent().hide();}).change();});";
		$this->ui->behaviors( $script );
	}

}
