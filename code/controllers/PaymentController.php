<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category    CosmoCommerce
 * @package     CosmoCommerce_Alipay
 * @copyright   Copyright (c) 2009-2014 CosmoCommerce,LLC. (http://www.cosmocommerce.com)
 * @contact :
 * T: +86-021-66346672
 * L: Shanghai,China
 * M:sales@cosmocommerce.com
 */

class CosmoCommerce_Alipay_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Order instance
     */
    protected $_order;
	protected $_gateway="https://mapi.alipay.com/gateway.do?";

    /**
     *  Get order
     *
     *  @param    none
     *  @return	  Mage_Sales_Model_Order
     */
    public function logTrans($trans,$type){
		$log = Mage::getModel('alipay/log');
        $log->setLogAt(time());
        $log->setOrderId($trans['out_trade_no']);
        $log->setTradeNo(null);
        $log->setType($type);
        $log->setPostData(implode('|',$trans));
        $log->save();
    }
    public function getOrder()
    {
        if ($this->_order == null)
        {
            $session = Mage::getSingleton('checkout/session');
            $this->_order = Mage::getModel('sales/order');
            if($orderId=$session->getAlipayPaymentOrderId()){
            
                $order = Mage::getModel('sales/order')->load($orderId);
                if (!$order->getId())
                {
                    $this->norouteAction();
                    return;
                }
                $order_cid=$order->getCustomerId();
                $current_cid=0;
                if(Mage::helper('customer')->getCustomer()){
                    $current_cid=Mage::helper('customer')->getCustomer()->getId();
                }else{
                    $this->_redirect('customer/account/login');
                    return;
                }
                
                if ($current_cid!=$order_cid)
                {
                    $this->norouteAction();
                    return;
                }
            
                $this->_order->load($orderId);
            }else{
                $this->_order->loadByIncrementId($session->getLastRealOrderId());
            }
        }
        return $this->_order;
    }

    /**
     * When a customer chooses Alipay on Checkout/Payment page
     *
     */
     
    public function payAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $orderId = (int) $this->getRequest()->getParam('order_id');
        if ($orderId) {
            $session->setAlipayPaymentOrderId($orderId);
        }
        $order = $this->getOrder();
        if (!$order)
        {
            return;
        }
        if (!$order->getId())
        {
            return;
        }

        $order->addStatusToHistory(
        $order->getStatus(),
        Mage::helper('alipay')->__('Customer was redirected to payment confirm page')
        );
        $order->save();

        
        $this->loadLayout();
        $this->renderLayout();
    }

    
    public function redirectAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $orderId = (int) $this->getRequest()->getParam('order_id');
        if ($orderId) {
            $session->setAlipayPaymentOrderId($orderId);
        }
        $order = $this->getOrder();

        if (!$order)
        {
            $this->norouteAction();
            return;
        }
        if (!$order->getId())
        {
            $this->norouteAction();
            return;
        }

        $order->addStatusToHistory(
        $order->getStatus(),
        Mage::helper('alipay')->__('Customer was redirected to Alipay')
        );
        $order->save();

        $this->getResponse()
        ->setBody($this->getLayout()
        ->createBlock('alipay/redirect')
        ->setOrder($order)
        ->toHtml());

        $session->unsQuoteId();
    }

    public function returnAction()
    {
        Mage::log("returnAction is called", Zend_log::DEBUG, "payment_alipay.log");
        $tradeStatus = strval($this->getRequest()->getParam('trade_status'));
        $isSign = Mage::helper('alipay/data')->getSignVeryfy($_GET, $_GET["sign"]);
        $session = Mage::getSingleton('checkout/session');
        if($session) {
            $session->setQuoteId($session->getAlipayPaymentOrderId(true));
            $order = $this->retrieveOrder($session);
            if ($order) {
                if ($isSign && 'TRADE_FINISHED' == $tradeStatus) {
                    $order->addStatusHistoryComment(Mage::helper('alipay')->__('Alipay Trade Finished.'));
                    $session->getQuote()->setIsActive(false)->save();
                    $this->saveInvoice($order);
                    $order->save();
                    $this->_redirect('checkout/onepage/success', array('_secure'=>true));
                } else {
                    if ($order && $order->getId()) {
                        $order->addStatusHistoryComment(Mage::helper('alipay')->__('Alipay Trade Aborted.'));
                        $order->cancel()->save();
                    }
                    Mage::helper('paypal/checkout')->restoreQuote();
                    $this->_redirect('checkout/onepage/failure', array('_secure'=>true));
                }
            }

        } else {
            Mage::log("Session doesn't exist. returnAction abort.", Zend_log::ERROR, "payment_alipay.log");
        }
    }

    public function notifyAction()
    {
        Mage::log("notifyAction is called", Zend_log::DEBUG, "payment_alipay.log");
        if ($this->getRequest()->isPost())
        {
            $postData = $this->getRequest()->getPost();
            $method = 'post';


        } else if ($this->getRequest()->isGet())
        {
            $postData = $this->getRequest()->getQuery();
            $method = 'get';

        } else
        {
            return;
        }
		$alipay = Mage::getModel('alipay/payment');
		
		$partner=$alipay->getConfigData('partner_id');
		$security_code=$alipay->getConfigData('security_code');
		$sign_type='MD5';
		$mysign="";
		$_input_charset='utf-8'; 
		
        $gateway = $alipay->getAlipayUrl();
        if(isset($postData["notify_id"])) {
            Mage::log("notify_id in the alipay response: " . $postData["notify_id"], Zend_log::DEBUG, 'payment_alipay.log');
        } else {
            Mage::log("notify_id not found in the alipay response: ", Zend_log::DEBUG, 'payment_alipay.log');
        }

		$veryfy_url = $gateway. "service=notify_verify" ."&partner=" .$partner. "&notify_id=".$postData["notify_id"];
		$veryfy_result="";
		$veryfy_result  = $this->get_verify($veryfy_url);
		
		$post           = $this->para_filter($postData);
		$sort_post      = $this->arg_sort($post);
		
		$arg="";
		while (list ($key, $val) = each ($sort_post)) {
		
			$arg.=$key."=".$val."&";
		}
		$prestr="";
		$prestr = substr($arg,0,count($arg)-2);  //去掉最后一个&号
		$mysign = $this->sign($prestr.$security_code);

		$sendemail=$alipay->getConfigData('sendemail');
		$sendemail_wbp=$alipay->getConfigData('sendemail_wbp');
		$sendemail_wssg=$alipay->getConfigData('sendemail_wssg');
		$sendemail_wbcg=$alipay->getConfigData('sendemail_wbcg');

		if ( $mysign == $postData["sign"])  {
            $this->logTrans($postData,$postData['trade_status']);//交易成功
			
			//以下是担保交易的交易状态
			if($postData['trade_status'] == 'WAIT_BUYER_PAY') {                   //担保交易 交易创建 等待买家付款
				
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
				//$order->setAlipayTradeno($postData['trade_no']);
                if($sendemail_wbp){
                    $order->sendNewOrderEmail();
                }
                        
                if ($order->getState() == 'new' ) {
                    $order->addStatusToHistory(
                    'alipay_wait_buyer_pay',
                    Mage::helper('alipay')->__('WAIT BUYER PAY'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                        
                    }
                }
                
			}
			else if($postData['trade_status'] == 'WAIT_SELLER_SEND_GOODS') {      //买家付款成功,等待卖家发货
				
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
				//$order->setAlipayTradeno($postData['trade_no']);
                if($sendemail_wssg){
                    $order->sendOrderUpdateEmail(false,Mage::helper('alipay')->__('WAIT SELLER SEND GOODS'));
                }
                if ($order->getStatus() == 'alipay_wait_buyer_pay' ) {
                
                    $order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
                    $order->addStatusToHistory(
                    'alipay_wait_seller_send_goods',
                    Mage::helper('alipay')->__('WAIT SELLER SEND GOODS'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                        
                    }
                }
			}
			else if($postData['trade_status'] == 'WAIT_BUYER_CONFIRM_GOODS') {    //卖家已经发货等待买家确认
			
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                if ($order->getStatus() == 'alipay_wait_seller_send_goods' ) {
                    //$order->setAlipayTradeno($postData['trade_no']);
                    $order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
                    if($sendemail_wbcg){
                        $order->sendOrderUpdateEmail(true,Mage::helper('alipay')->__('WAIT BUYER CONFIRM GOODS'));
                    }
                    $order->addStatusToHistory(
                    'alipay_wait_buyer_confirm_goods',
                    Mage::helper('alipay')->__('WAIT BUYER CONFIRM GOODS'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                    }
                }

			}
			else if($postData['trade_status'] == 'TRADE_CLOSED') {    //交易关闭
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                    $order->addStatusToHistory(
                    'alipay_trade_closed',
                    Mage::helper('alipay')->__('TRADE CLOSED'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                    }

			}
			else if($postData['trade_status'] == 'WAIT_SELLER_AGREE') {    //退款状态-退款协议等待卖家确认中

				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                    $order->addStatusToHistory(
                    'alipay_wait_seller_agree',
                    Mage::helper('alipay')->__('WAIT SELLER AGREE'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                    }

			}
			else if($postData['trade_status'] == 'SELLER_REFUSE_BUYER') {    //退款状态-卖家不同意协议，等待买家修改
						
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                    $order->addStatusToHistory(
                    'alipay_seller_refuse_buyer',
                    Mage::helper('alipay')->__('SELLER REFUSE BUYER'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                    }

			}
			else if($postData['trade_status'] == 'WAIT_BUYER_RETURN_GOODS') {    //退款状态-退款协议达成，等待买家退货
			
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                    $order->addStatusToHistory(
                    'alipay_wait_buyer_return_goods',
                    Mage::helper('alipay')->__('WAIT BUYER RETURN GOODS'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                    }

			}
			else if($postData['trade_status'] == 'WAIT_SELLER_CONFIRM_GOODS') {    //退款状态-等待卖家收货
			
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                if ($order->getStatus() == 'alipay_wait_buyer_return_goods' ) {
                    $order->addStatusToHistory(
                    'alipay_wait_seller_confirm_goods',
                    Mage::helper('alipay')->__('WAIT SELLER CONFIRM GOODS'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                    }
                }

			}
			else if($postData['trade_status'] == 'REFUND_SUCCESS') {    //退款状态-退款成功
			
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                if ($order->getStatus() == 'alipay_wait_seller_confirm_goods' ) {
                    $order->addStatusToHistory(
                    'alipay_refund_success',
                    Mage::helper('alipay')->__('REFUND SUCCESS'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                    }
                }

			}
			else if($postData['trade_status'] == 'REFUND_CLOSED') {    //退款状态-退款关闭
			
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                    $order->addStatusToHistory(
                    'alipay_refund_closed',
                    Mage::helper('alipay')->__('REFUND CLOSED'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                    }

			}
			else if($postData['trade_status'] == 'TRADE_FINISHED' ){  //担保交易
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                if ($order->getStatus() == 'alipay_wait_buyer_confirm_goods' || $order->getStatus() == 'alipay_wait_buyer_pay') {
                    //$order->setAlipayTradeno($postData['trade_no']);
                    $order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
                    if($sendemail){
                        $order->sendOrderUpdateEmail(true,Mage::helper('alipay')->__('TRADE FINISHED'));
                    }
                    $order->addStatusToHistory(
                    'alipay_trade_finished',
                    Mage::helper('alipay')->__('TRADE FINISHED'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                        
                    }
                }
			}else if ( $postData['trade_status'] == "TRADE_SUCCESS") { //即时到帐完成交易   
            
				$order = Mage::getModel('sales/order');
				$order->loadByIncrementId($postData['out_trade_no']);
                if ($order->getState() == 'new' || $order->getState() == 'new'  ) {
                    //$order->setAlipayTradeno($postData['trade_no']);
                    $order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
                    if($sendemail){
                        $order->sendOrderUpdateEmail(true,Mage::helper('alipay')->__('TRADE SUCCESS'));
                    }
                    $order->addStatusToHistory(
                    'alipay_trade_success',
                    Mage::helper('alipay')->__('TRADE SUCCESS'));
                    try{
                        $order->save();
                        echo "success";
                        exit();
                    } catch(Exception $e){
                        
                    }
                }
            }
			else {
				echo "fail";
				$this->logTrans($postData,'Notify Sign Error');//交易失败
			}	

		} else {
			echo "fail";
            $this->logTrans($postData,'Order Not Found');//交易订单未找到
		}
    }

	public function get_verify($url,$time_out = "60") {
		$urlarr     = parse_url($url);
		$errno      = "";
		$errstr     = "";
		$transports = "";
		if($urlarr["scheme"] == "https") {
			$transports = "ssl://";
			$urlarr["port"] = "443";
		} else {
			$transports = "tcp://";
			$urlarr["port"] = "80";
		}
		$fp=@fsockopen($transports . $urlarr['host'],$urlarr['port'],$errno,$errstr,$time_out);
		if(!$fp) {
			die("ERROR: $errno - $errstr<br />\n");
		} else {
			fputs($fp, "POST ".$urlarr["path"]." HTTP/1.1\r\n");
			fputs($fp, "Host: ".$urlarr["host"]."\r\n");
			fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
			fputs($fp, "Content-length: ".strlen($urlarr["query"])."\r\n");
			fputs($fp, "Connection: close\r\n\r\n");
			fputs($fp, $urlarr["query"] . "\r\n\r\n");
			while(!feof($fp)) {
				$info[]=@fgets($fp, 1024);
			}
			fclose($fp);
			$info = implode(",",$info);
			$arg="";
			while (list ($key, $val) = each ($_POST)) {
				$arg.=$key."=".$val."&";
			}

		return $info;
		}

	}

    /**
     * @param $session
     * @return mixed
     */
    public function retrieveOrder($session)
    {
        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if (is_null($order)) {
                Mage::log("Order[" . $session->getLastRealOrderId() . "] cannot be loaded.", Zend_log::ERROR, "payment_alipay.log");
            }
            return $order;
        }
        Mage::log("Cannot retrieve the LastRealOrderId from the session.", Zend_log::ERROR, "payment_alipay.log");
        return null;
    }
    /**
     *  Alipay response router
     *
     *  @param    none
     *  @return	  void
     public function notifyAction()
     {
     $model = Mage::getModel('alipay/payment');
     
     if ($this->getRequest()->isPost()) {
     $postData = $this->getRequest()->getPost();
     $method = 'post';
     } else if ($this->getRequest()->isGet()) {
     $postData = $this->getRequest()->getQuery();
     $method = 'get';
     } else {
     $model->generateErrorResponse();
     }
     $order = Mage::getModel('sales/order')
     ->loadByIncrementId($postData['reference']);
     if (!$order->getId()) {
     $model->generateErrorResponse();
     }
     if ($returnedMAC == $correctMAC) {
     if (1) {
     $order->addStatusToHistory(
     $model->getConfigData('order_status_payment_accepted'),
     Mage::helper('alipay')->__('Payment accepted by Alipay')
     );
     
     $order->sendNewOrderEmail();
     if ($this->saveInvoice($order)) {
     //                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
     }
     
     } else {
     $order->addStatusToHistory(
     $model->getConfigData('order_status_payment_refused'),
     Mage::helper('alipay')->__('Payment refused by Alipay')
     );
     
     // TODO: customer notification on payment failure
     }
     
     $order->save();
     } else {
     $order->addStatusToHistory(
     Mage_Sales_Model_Order::STATE_CANCELED,//$order->getStatus(),
     Mage::helper('alipay')->__('Returned MAC is invalid. Order cancelled.')
     );
     $order->cancel();
     $order->save();
     $model->generateErrorResponse();
     }
     }
     */
     /**
     *  Save invoice for order
     *
     *  @param    Mage_Sales_Model_Order $order
     *  @return	  boolean Can save invoice or not
     */
    protected function saveInvoice(Mage_Sales_Model_Order $order)
    {
        if ($order->canInvoice())
        {
            $convertor = Mage::getModel('sales/convert_order');
            $invoice = $convertor->toInvoice($order);
            foreach ($order->getAllItems() as $orderItem)
            {
                if (!$orderItem->getQtyToInvoice())
                {
                    continue ;
                }
                $item = $convertor->itemToInvoiceItem($orderItem);
                $item->setQty($orderItem->getQtyToInvoice());
                $invoice->addItem($item);
            }
            $invoice->collectTotals();
            $invoice->register()->capture();
            Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();
            return true;
        }

        return false;
    }

    /**
     *  Success payment page
     *
     *  @param    none
     *  @return	  void
     */
    public function successAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getAlipayPaymentQuoteId());
        $session->unsAlipayPaymentQuoteId();

        $order = $this->getOrder();

        if (!$order->getId())
        {
            $this->norouteAction();
            return;
        }

        $order->addStatusToHistory(
        $order->getStatus(),
        Mage::helper('alipay')->__('Customer successfully returned from Alipay')
        );

        $order->save();

        $this->_redirect('checkout/onepage/success');
    }

    /**
     *  Failure payment page
     *
     *  @param    none
     *  @return	  void
     */
    public function errorAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $errorMsg = Mage::helper('alipay')->__(' There was an error occurred during paying process.');

        $order = $this->getOrder();

        if (!$order->getId())
        {
            $this->norouteAction();
            return;
        }
        if ($order instanceof Mage_Sales_Model_Order && $order->getId())
        {
            $order->addStatusToHistory(
            Mage_Sales_Model_Order::STATE_CANCELED,//$order->getStatus(),
            Mage::helper('alipay')->__('Customer returned from Alipay.').$errorMsg
            );

            $order->save();
        }

        $this->loadLayout();
        $this->renderLayout();
        Mage::getSingleton('checkout/session')->unsLastRealOrderId();
    }
	
	
    
	public function sign($prestr) {
		$mysign = md5($prestr);
		return $mysign;
	}
    
	public function para_filter($parameter) {
		$para = array();
		while (list ($key, $val) = each ($parameter)) {
			if($key == "sign" || $key == "sign_type" || $val == "")continue;
			else	$para[$key] = $parameter[$key];

		}
		return $para;
	}
	
	public function arg_sort($array) {
		ksort($array);
		reset($array);
		return $array;
	}

	public function charset_encode($input,$_output_charset ,$_input_charset ="GBK" ) {
		
		$output = "";
		if($_input_charset == $_output_charset || $input ==null) {
			$output = $input;
		} elseif (function_exists("mb_convert_encoding")){
			$output = mb_convert_encoding($input,$_output_charset,$_input_charset);
		} elseif(function_exists("iconv")) {
			$output = iconv($_input_charset,$_output_charset,$input);
		} else die("sorry, you have no libs support for charset change.");
		
		return $output;
	}	
}
