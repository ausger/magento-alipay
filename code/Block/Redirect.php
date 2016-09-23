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
 * @category	CosmoCommerce
 * @package 	CosmoCommerce_Alipay
 * @copyright	Copyright (c) 2009-2014 CosmoCommerce,LLC. (http://www.cosmocommerce.com)
 * @contact :
 * T: +86-021-66346672
 * L: Shanghai,China
 * M:sales@cosmocommerce.com
 */
class CosmoCommerce_Alipay_Block_Redirect extends Mage_Core_Block_Abstract
{

	protected function _toHtml()
	{
		$standard = Mage::getModel('alipay/payment');
        $form = new Varien_Data_Form();
        $form->setAction($standard->getAlipayUrl())
            ->setId('alipay_payment_checkout')
            ->setName('alipay_payment_checkout')
            ->setMethod('GET')
            ->setUseContainer(true);
          
        
        $standard->setOrder($this->getOrder());    
        if($this->getRequest()->getParam('bank')){
           $standard->setBank($this->getRequest()->getParam('bank'));
        }
        if(Mage::helper('mobiledetect')->isMobile()) {
            foreach ($standard->getMobileCheckoutFormFields() as $field => $value) {
                $form->addField($field, 'hidden', array('name' => $field, 'value' => $value));
            }
        } else {
            foreach ($standard->getStandardCheckoutFormFields() as $field => $value) {
                $form->addField($field, 'hidden', array('name' => $field, 'value' => $value));
            }
        }

        $formHTML = $form->toHtml();

        $html = '<html><body>';
        $html.= $this->__('页面会在几秒内自动跳转至支付宝安全支付页面。如果无法自动跳转，请<b>手动复制链接到浏览器</b>以完成支付。');
        $html.= $formHTML;
        //$html.="<script type="text/javascript">window.open('http://www.baidu.com', 'window name', 'window settings');</script>";

        $html.= " <script src='/js/alipay/ap.js'></script><script>
	   			//该js用于微信上使用支付宝支付
	           window.onload = function() {
	           var queryParam = '';
	           Array.prototype.slice.call(document.querySelectorAll('input[type=hidden]')).forEach(function (ele) {
	        	   queryParam += ele.name + '=' + encodeURIComponent(ele.value) + '&';
	           });
	           var gotoUrl = document.querySelector('#alipay_payment_checkout').getAttribute('action') + queryParam;
	           _AP.pay(gotoUrl);
	        }
	    </script>";


        //$html.= '<script type="text/javascript">document.getElementById("alipay_payment_checkout").submit();</script>';
        $html.= '</body></html>';


        return $html;
    }
}