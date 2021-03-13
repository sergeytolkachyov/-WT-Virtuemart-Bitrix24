<?php
// No direct access
defined( '_JEXEC' ) or die;

/**
 * @package     WT Virtuemart Bitrix24 system plugin
 * @version     1.0.0
 * WT Virtuemart Bitrix24 system plugin - advanced tool for reciving order information from Virtuemart into CRM Bitrix24
 * @Author Sergey Tolkachyov, https://web-tolk.ru
 * @copyright   Copyright (C) 2020 Sergey Tolkachyov
 * @license     GNU/GPL http://www.gnu.org/licenses/gpl-2.0.html
 * @since 1.0
 */

class plgSystemWt_vm_b24 extends JPlugin
{
	/**
	 * Class Constructor
	 * @param object $subject
	 * @param array $config
	 */
	public function __construct( & $subject, $config )
	{
		parent::__construct( $subject, $config );
        $this->loadLanguage();

    }



    public function plgVmConfirmedOrder($vm_this, $orderDetails)
    {

        $crm_host = $this->params->get('crm_host');
        $webhook_secret = $this->params->get('crm_webhook_secret');
        $crm_assigned_id = $this->params->get('crm_assigned');

        define('C_REST_WEB_HOOK_URL', 'https://' . $crm_host . '/rest/' . $crm_assigned_id . '/' . $webhook_secret . '/');//url on creat Webhook
        include_once("plugins/system/wt_vm_b24/lib/crest.php");


		$order = $orderDetails["details"]["BT"];
	    $orderItems = $orderDetails["items"];

	    //getOrderStatusName ($_code) -  Return the order status name for a given code

        $qr = array(
            'fields' => array(),
            'params' => array("REGISTER_SONET_EVENT" => "Y")
        );
        $b24_fields = $this->params->get('fields');
     for ($i=0; $i<count((array)$b24_fields);$i++){
        $fields_num = "fields".$i;
         $b24_field = "";
         $store_field ="";
             if($b24_fields->$fields_num->b24fieldtype == "standart"){
                $b24_field = $b24_fields->$fields_num->b24fieldstandart;
                      if($b24_field == "TITLE"){
                            foreach ($b24_fields->$fields_num->storefield as $value){

                                    $store_field .= $order->$value . " ";
                            }
                        $store_field = $this->params->get('order_name_prefix').$store_field;

                    }elseif($b24_field == "EMAIL"){
                        $store_field = array();

                        $k=0;
                        foreach($b24_fields->$fields_num->storefield as $value){
                            $email_name="n".$k;

                            $store_field[$email_name] = array(
                                "VALUE" => $order->$value,
                                "VALUE_TYPE" => "WORK"
                            );
                            $k++;
                        }

                    }elseif($b24_field == "PHONE"){
                        $store_field = array();

                        $k=0;
                        foreach($b24_fields->$fields_num->storefield as $value){
                            $phone_name="n".$k;

                            $store_field[$phone_name] = array(
                                        "VALUE" => $order->$value,
                                        "VALUE_TYPE" => "WORK"
                                    );
                            $k++;
                        }

                    }else {
                        // TODO: Сделать функцию, а не копировать 2 раза цикл
	                      foreach ($b24_fields->$fields_num->storefield as $value){
		                      if($value == "virtuemart_country_id"){//Получаем название страны

			                        $store_field .= $this->getCountryName($order->$value)." ";
		                      }elseif($value == "virtuemart_state_id"){//Получаем название региона
			                       $store_field .= $this->getStateName($order->$value)." ";

		                      }elseif($value == "virtuemart_shipmentmethod_id"){//название способа доставки
			                        $store_field .= $this->getShippingMethodName($order->$value,$order->order_language)." ";
		                      }elseif($value == "virtuemart_paymentmethod_id"){//название способа оплаты
			                       $store_field .= $this->getPaymentMethodName($order->$value,$order->order_language)." ";
		                      }else {
			                      $store_field .= $order->$value . " ";
		                      }
	                      }
                    }

             }elseif ($b24_fields->$fields_num->b24fieldtype == "custom"){// Пользовательское поле Битрикс24
                 $b24_field = $b24_fields->$fields_num->b24fieldcustom;

                     foreach ($b24_fields->$fields_num->storefield as $value){
                         if($value == "virtuemart_country_id"){//Получаем название страны
                            $store_field .= $this->getCountryName($order->$value)." ";
                         }elseif($value == "virtuemart_state_id"){//Получаем название региона
	                         $store_field .= $this->getStateName($order->$value)." ";
                         }elseif($value == "virtuemart_shipmentmethod_id"){//название способа доставки
                             $store_field .= $this->getShippingMethodName($order->$value,$order->order_language)." ";
                         }elseif($value == "virtuemart_paymentmethod_id"){//название способа оплаты
                             $store_field .= $this->getPaymentMethodName($order->$value,$order->order_language)." ";
                         }else {
                             $store_field .= $order->$value . " ";
                         }
                     }
             }

         $qr["fields"][$b24_field] = $store_field;

         }//END FOR


        $qr["fields"]["SOURCE_ID"] = $this->params->get('lead_source');
        $qr["fields"]["SOURCE_DESCRIPTION"] = $this->params->get('source_description');
	    $product_rows = array();
        $b24_comment="<br/>";
       $a=0;
       foreach($orderItems as $items){
           $product_rows[$a]["PRODUCT_NAME"] = $items->order_item_name;
           $product_rows[$a]["PRICE"] = $items->product_final_price;
           $product_rows[$a]["QUANTITY"] = $items->product_quantity;


           if($this->params->get('product_image') == 1) {
               $b24_comment .= "<img src='" .JUri::root(). $this->getProductImage($items->virtuemart_media_id[0]). "' width='150px'/><br/>";
           }
           if($this->params->get('product_link') == 1) {
               $b24_comment .= "<a href='" . substr(JURI::root(),0,-1) . JRoute::_($items->link) . "'/>" . $items->product_name . "</a><br/>";
           }else {
               $b24_comment .= $items->product_name."<br/>";
           }
           if($this->params->get('product_sku') == 1) {
               $b24_comment .= JText::_("PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_SKU").": ".$items->product_sku."<br/>";
           }
	       if($this->params->get('product_gtin') == 1) {
		       $b24_comment .= JText::_("PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_GTIN").": ".$items->product_gtin."<br/>";
	       }
	       if($this->params->get('product_mpn') == 1) {
		       $b24_comment .= JText::_("PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_MPN").": ".$items->product_mpn."<br/>";
	       }
           if($this->params->get('product_weight') == 1) {
               $b24_comment .= JText::_("PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_WEIGHT").": ".$items->product_weight."<br/>";
           }
	       if($this->params->get('product_length') == 1) {
		       $b24_comment .= JText::_("PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_LENGTH").": ".$items->product_length."<br/>";
	       }
	       if($this->params->get('product_width') == 1) {
		       $b24_comment .= JText::_("PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_WIDTH").": ".$items->product_width."<br/>";
	       }
	       if($this->params->get('product_height') == 1) {
		       $b24_comment .= JText::_("PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_HEIGHT").": ".$items->product_height."<br/>";
	       }


	           /*
	            * Virtuemart Custom fields - cart attributes
	            */
				if($this->params->get("product_cf_cart_attr_desc") == 1 && !empty($items->product_attribute)){
					$product_attributes = json_decode($items->product_attribute);

					foreach ($product_attributes as $attr => $value){

						foreach ($items->customfields as $customfield){
							if(($customfield->virtuemart_custom_id == $attr) && ($customfield->virtuemart_customfield_id == $value)){
								$b24_comment .= "<strong>".$customfield->custom_title.":</strong> ".$customfield->customfield_value."<br/>";
							}
						}
					}
				}
           $a++;
       }


        $b24_comment .= "<a href='".JURI::root()."administrator?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id=".$order->virtuemart_order_id."'>See this order in Virtuemart</a>";
        $qr["fields"]["COMMENTS"] .= $b24_comment;

		$getCookie = JFactory::getApplication()->input->cookie;
        $utms = array(
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term'
        );
        foreach ($utms as $key){
            if($key != "utm_term"){
                $utm = $getCookie->get($name = $key);
            } else {
                $utm = $getCookie->get($name = $key);
                $utm = urldecode($utm);
            }
            $utm_name = strtoupper($key);
            $qr["fields"][$utm_name] .=  $utm;
        }

        $arData = [
            'add_lead' => [
                'method' => 'crm.lead.add',
                'params' => $qr
            ],
            'add_products' => [
                'method' => 'crm.lead.productrows.set',
                'params' => [
                    'id' => '$result[add_lead]',
                    'rows' => $product_rows
                ]
            ]
        ];
       $resultBitrix24 = CRest::callBatch($arData);

        /************* DEBUG *****************/
        $debug = $this->params->get('debug');
        if($debug == 1){
            echo"<pre><h3>Query array (to Bitrix24)</h3><br/>";
            print_r($qr);
            echo "</pre>";

            echo"<pre><h3>Product rows array (to Bitrix24)</h3><br/>";
            print_r($product_rows);
            echo"</pre>";
            echo"<pre><h3>Bitrix24 response array</h3><br/>";
             print_r($resultBitrix24);
            echo "</pre>";
            echo"<pre><h3>Virtuemart order array</h3><br/>";
            print_r($orderDetails);
            echo "</pre>";

        }// if debug


    }// END onBeforeDisplayCheckoutFinish


    /** Returns product image src by id
     * @param Int $virtuemart_media_id
     *
     * @return string file_url
     *
     * @since version 1.0
     */
    private function getProductImage ($virtuemart_media_id){
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select($db->quoteName('file_url'))
            ->from($db->quoteName('#__virtuemart_medias'))
            ->where($db->quoteName('virtuemart_media_id') . ' = '. $db->quote($virtuemart_media_id));
        $db->setQuery($query);
        $file_url = $db->loadAssoc();
        return $file_url["file_url"];
    }



    /** Returns country name by id
     * @param Int $country_id
     *
     * @return string
     *
     * @since version 1.0
     */
    private function getCountryName ($country_id){
        $lang = JFactory::getLanguage();
        $current_lang = $lang->getTag();
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select($db->quoteName('country_name'))
            ->from($db->quoteName('#__virtuemart_countries'))
            ->where($db->quoteName('virtuemart_country_id') . ' = '. $db->quote($country_id));
        $db->setQuery($query);
        $country_name = $db->loadAssoc();
        return $country_name["country_name"];
    }

	/** Returns state name by id
	 * @param Int $country_id
	 *
	 * @return string
	 *
	 * @since version 1.0
	 */
	private function getStateName ($state_id){
		$lang = JFactory::getLanguage();
		$current_lang = $lang->getTag();
		$db = JFactory::getDBO();
		$query = $db->getQuery(true);
		$query->select($db->quoteName('state_name'))
			->from($db->quoteName('#__virtuemart_states'))
			->where($db->quoteName('virtuemart_state_id') . ' = '. $db->quote($state_id));
		$db->setQuery($query);
		$country_name = $db->loadAssoc();
		return $country_name["state_name"];
	}




    /** Returns shipping method name by id
     * @param Int $shipping_method_id
     * @return string
     *
     * @since version 1.0
     */

    private function getShippingMethodName ($shipping_method_id,$order_language){
         $order_language = str_replace("-","_",$order_language);
		$db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select($db->quoteName('shipment_name'))
            ->from($db->quoteName('#__virtuemart_shipmentmethods_'.$order_language)) //.$current_lang
            ->where($db->quoteName('virtuemart_shipmentmethod_id') . ' = '. $db->quote($shipping_method_id));
        $db->setQuery($query);
        $shipping_name = $db->loadAssoc();
        return $shipping_name["shipment_name"];
    }

    /** Returns payment method name by id
     * @param Int $payment_method_id
     *
     * @return string
     *
     * @since version 1.0
     */
    private function getPaymentMethodName ($payment_method_id,$order_language){

        $order_language = str_replace("-","_",$order_language);
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select($db->quoteName('payment_name'))
            ->from($db->quoteName('#__virtuemart_paymentmethods_'.$order_language))
            ->where($db->quoteName('virtuemart_paymentmethod_id') . ' = '. $db->quote($payment_method_id));
        $db->setQuery($query);
        $payment_name = $db->loadAssoc();
        return $payment_name["payment_name"];
    }



function onBeforeCompileHead()
    {
        $load_jquery_coockie_script = $this->params->get('load_jquery_coockie_script');
        $document = JFactory::getDocument();
        if ($load_jquery_coockie_script == 1) {
            $document->addScript(JURI::root(true) . "plugins/system/wt_vm_b24/js/jquery.coockie.js");
        }
        $document->addScript(JURI::root(true) . "plugins/system/wt_vm_b24/js/jquery.coockie.utm.js");


    }



}//plgSystemWt_vm_b24_pro