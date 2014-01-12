<?php

/**
 * PaymentService
 *
 * 
 */

use Omnipay\Common\GatewayFactory;
use Omnipay\Common\CreditCard;
use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\AbstractRequest;

abstract class PaymentService{
	
	private static $depends = array(
		'httpClient' => '%$Guzzle\Http\Client',
		'httpRequest' => '%$Symfony\Component\HttpFoundation\Request'
	);

	protected $payment;

	private static $httpclient, $httprequest;

	private $returnurl, $cancelurl;

	public function __construct(Payment $payment){
		$this->payment = $payment;
	}

	/**
	 * Attempt to make a payment
	 * @param  array $data returnUrl/cancelUrl + customer creditcard and billing/shipping details.
	 * @return ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function purchase($data = array()) {
		if($this->Status !== "Created"){
		 	return null; //could be handled better? send payment response?
		}
		if(!$this->isInDB()){
			$this->write();
		}
		$this->returnurl = isset($data['returnUrl']) ? $data['returnUrl'] : $this->returnurl;
		$this->cancelurl = isset($data['cancelUrl']) ? $data['cancelUrl'] : $this->cancelurl;
		$message = $this->createMessage('PurchaseRequest');
		$request = $this->oGateway()->purchase(array(
			'card' => new CreditCard($data),
			'amount' => (float)$this->MoneyAmount,
			'currency' => $this->MoneyCurrency,
			'transactionId' => $message->Identifier,
			'clientIp' => isset($data['clientIp']) ? $data['clientIp'] : null,
			'returnUrl' => PaymentGatewayController::get_return_url($message, 'complete', $this->returnurl),
			'cancelUrl' => PaymentGatewayController::get_return_url($message,'cancel', $this->cancelurl)
		));
		$this->logToFile($request->getParameters());
		
		$gatewayresponse = new GatewayResponse($this);

		try{
			$response = $request->send();
			//update payment model
			if ($response->isSuccessful()) {
				$this->createMessage('PurchasedResponse', $response);
				$this->Status = 'Captured';
				$this->write();
				$gatewayresponse->setOmnipayResponse($response);
				$gatewayresponse->setMessage("Payment successful");
			} elseif ($response->isRedirect()) { // redirect to off-site payment gateway
				$this->createMessage('PurchaseRedirectResponse', $response);
				$this->Status = 'Authorized'; //or should this be 'Pending'?
				$this->write();
				$gatewayresponse->setOmnipayResponse($response);
				$gatewayresponse->setMessage("Redirecting to gateway");
			} else {
				$this->createMessage('PurchaseError', $response);
				$gatewayresponse->setOmnipayResponse($response);
				$gatewayresponse->setMessage("Error (".$response->getCode()."): ".$response->getMessage());
			}
		}catch(Exception $e){
			$this->createMessage('PurchaseError', $e->getMessage());
			$gatewayresponse->setMessage($e->getMessage());
		}
		return $gatewayresponse;
	}

	/**
	 * Finalise this payment, after off-site external processing.
	 * This is ususally only called by PaymentGatewayController.
	 * @return PaymentResponse encapsulated response info
	 */
	public function completePurchase(){

		$gatewayresponse = new GatewayResponse($this);
		$request = $this->oGateway()->completePurchase(array(
			'amount' => (float)$this->MoneyAmount
		));
		$this->createMessage('CompletePurchaseRequest', $request);
		$response = null;
		try{
			$response = $request->send();
			
			if($response->isSuccessful()){
				$this->createMessage('PurchasedResponse', $response);
				$this->Status = 'Captured';
				$this->write();
			}else{
				$this->createMessage('CompletePurchaseError', $response);
			}
			$gatewayresponse->setOmnipayResponse($response);
		} catch (\Exception $e) {
			$this->createMessage("CompletePurchaseError", $e->getMessage());
		}

		return $gatewayresponse;
	}

	/**
	 * Initiate the authorisation process for on-site and off-site gateways.
	 * @param  array $data returnUrl/cancelUrl + customer creditcard and billing/shipping details.
	 * @return ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function authorize($data = array()) {
		//TODO
	}

	/**
	 * Complete authorisation, after off-site external processing.
	 * This is ususally only called by PaymentGatewayController.
	 * @return PaymentResponse encapsulated response info
	 */
	public function completeAuthorize() {
		//TODO
	}

	/**
	 * Do the capture of money on authorised credit card. Money exchanges hands.
	 * @return PaymentResponse encapsulated response info
	 */
	public function capture() {
		//TODO
	}

	/**
	 * Return money to the previously charged credit card.
	 * @return PaymentResponse encapsulated response info
	 */
	public function refund() {
		//TODO
	}

	/**
	 * Cancel this payment, and prevent any future changes.
	 * @return PaymentResponse encapsulated response info
	 */
	public function void() {
		//TODO: call gateway function, if available
		$this->Status = "Void";
		$this->write();
	}

}