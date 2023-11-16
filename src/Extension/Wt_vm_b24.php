<?php
/**
 * @package     WT Virtuemart Bitrix24 system plugin
 * @version     2.0.0
 * @Author      Sergey Tolkachyov, https://web-tolk.ru
 * @copyright   Copyright (C) 2023 Sergey Tolkachyov
 * @license     GNU/GPL http://www.gnu.org/licenses/gpl-2.0.html
 * @since       1.0.0
 * @link        https://web-tolk.ru/dev/joomla-plugins/wt-virtuemart-bitrix24
 */

namespace Joomla\Plugin\System\Wt_vm_b24\Extension;

defined('_JEXEC') or die;

use Joomla\Application\SessionAwareWebApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Wt_vm_b24\Library\Crest;
use Joomla\CMS\Router\Route;

final class Wt_vm_b24 extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	/**
	 *
	 * @return array
	 *
	 * @throws \Exception
	 * @since 4.1.0
	 *
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'plgVmConfirmedOrder' => 'plgVmConfirmedOrder',
			'onAfterDispatch' => 'onAfterDispatch',
		];
	}

	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$crm_host        = $this->params->get('crm_host');
		$webhook_secret  = $this->params->get('crm_webhook_secret');
		$crm_assigned_id = $this->params->get('crm_assigned');
		if (!empty($crm_host) && !empty($webhook_secret) && !empty($crm_assigned_id))
		{
			$b24 = new Uri();
			$b24->setScheme('https');
			$b24->setHost($crm_host);
			$b24->setPath('/rest/' . $crm_assigned_id . '/' . $webhook_secret . '/');
			define('C_REST_WEB_HOOK_URL', $b24->toString());//url on creat Webhook
		}
		else
		{
			if ($this->params->get('debug') == 1)
			{
				$this->prepareDebugInfo('Bitrix 24 connection status', Text::_('PLG_WT_VM_B24_B24_NOT_CONNECTED'));
			}
		}

	}

	public function plgVmConfirmedOrder($event)
	{
		$vm_this      = $event->getArgument(0);
		$orderDetails = $event->getArgument(1);


		$order       = $orderDetails['details']['BT'];
		$orderItems  = $orderDetails['items'];
		$plugin_mode = $this->params->get('lead_vs_deal');
		$debug       = $this->params->get('debug');
		//getOrderStatusName ($_code) -  Return the order status name for a given code
		if ($debug == 1)
		{
			$this->prepareDebugInfo("Plugin mode", $plugin_mode);
		}

		$qr = [
			'fields' => [],
			'params' => ['REGISTER_SONET_EVENT' => 'Y']
		];

		if ($plugin_mode == "deal" || ($plugin_mode == "lead" && $this->params->get('create_contact_for_unknown_lead') == 1))
		{
			$contact    = [
				'fields' => []
			];
			$requisites = [
				'fields' => []
			];
		}

		$b24_fields = $this->params->get('fields');
		$this->prepareDebugInfo('Product fields',$b24_fields);
		for ($i = 0; $i < count((array) $b24_fields); $i++)
		{
			$fields_num  = 'fields' . $i;
			$b24_field   = '';
			$store_field = '';
			if ($b24_fields->$fields_num->b24fieldtype == 'standart')
			{
				$b24_field = $b24_fields->$fields_num->b24fieldstandart;
				if ($b24_field == 'TITLE')
				{
					foreach ($b24_fields->$fields_num->storefield as $value)
					{

						$store_field .= $order->$value . ' ';
					}
					$store_field = $this->params->get('order_name_prefix') . $store_field;

				}
				elseif ($b24_field == 'EMAIL' || $b24_field == 'PHONE')
				{
					$store_field = [];

//					$k = 0;
					foreach ($b24_fields->$fields_num->storefield as $value)
					{
//						$email_name = 'n' . $k;
						if(!empty($order->$value))
						{
							$store_field['VALUE'] = $order->$value;
							$store_field['VALUE_TYPE'] = 'WORK';

						}

//						$k++;
					}

				}
//				elseif ($b24_field == 'PHONE')
//				{
//					$store_field = [];
//
//					//$k = 0;
//					foreach ($b24_fields->$fields_num->storefield as $value)
//					{
//						//$phone_name = 'n' . $k;
//						if(!empty($order->$value))
//						{
//							$store_field[] = [
//								'VALUE'      => $order->$value,
//								'VALUE_TYPE' => 'WORK'
//							];
//						}
//
//						//$k++;
//					}
//
//				}
				else
				{
					// TODO: Сделать функцию, а не копировать 2 раза цикл
					foreach ($b24_fields->$fields_num->storefield as $value)
					{
						if ($value == 'virtuemart_country_id')
						{//Получаем название страны

							$store_field .= $this->getCountryName($order->$value) . ' ';

						}
						elseif ($value == 'virtuemart_state_id')
						{//Получаем название региона

							$store_field .= $this->getStateName($order->$value) . ' ';

						}
						elseif ($value == 'virtuemart_shipmentmethod_id')
						{//название способа доставки

							$store_field .= $this->getShippingMethodName($order->$value, $order->order_language) . ' ';

						}
						elseif ($value == 'virtuemart_paymentmethod_id')
						{//название способа оплаты

							$store_field .= $this->getPaymentMethodName($order->$value, $order->order_language) . ' ';

						}
						else
						{

							$store_field .= $order->$value . ' ';

						}
					}
				}

			}
			elseif ($b24_fields->$fields_num->b24fieldtype == 'custom')
			{// Пользовательское поле Битрикс24
				$b24_field = $b24_fields->$fields_num->b24fieldcustom;

				foreach ($b24_fields->$fields_num->storefield as $value)
				{
					if ($value == 'virtuemart_country_id')
					{//Получаем название страны
						$store_field .= $this->getCountryName($order->$value) . ' ';
					}
					elseif ($value == 'virtuemart_state_id')
					{//Получаем название региона
						$store_field .= $this->getStateName($order->$value) . ' ';
					}
					elseif ($value == 'virtuemart_shipmentmethod_id')
					{//название способа доставки
						$store_field .= $this->getShippingMethodName($order->$value, $order->order_language) . ' ';
					}
					elseif ($value == 'virtuemart_paymentmethod_id')
					{//название способа оплаты
						$store_field .= $this->getPaymentMethodName($order->$value, $order->order_language) . ' ';
					}
					else
					{
						$store_field .= $order->$value . ' ';
					}
				}
			}

//			$qr['fields'][$b24_field] = $store_field;


			/**
			 * Если Сделка или Лид+Контакт
			 */

			if ($plugin_mode == "deal" || ($plugin_mode == "lead" && $this->params->get('create_contact_for_unknown_lead') == 1))
			{
				if ($b24_field == "NAME" || //Fields for contact
					$b24_field == "LAST_NAME" ||
					$b24_field == "SECOND_NAME" ||
					$b24_field == "BIRTHDATE" ||
					$b24_field == "PHONE" ||
					$b24_field == "EMAIL" ||
					$b24_field == "FAX"
				)
				{
					if($b24_field == "PHONE" || $b24_field == "EMAIL"){
						$contact["fields"][$b24_field][] = $store_field;
					} else {
						$contact["fields"][$b24_field] = $store_field;
					}


				}
				elseif ($b24_field == "ADDRESS" ||  //Fields for contact's requisites
					$b24_field == "ADDRESS_2" ||
					$b24_field == "ADDRESS_CITY" ||
					$b24_field == "ADDRESS_POSTAL_CODE" ||
					$b24_field == "ADDRESS_REGION" ||
					$b24_field == "ADDRESS_PROVINCE" ||
					$b24_field == "ADDRESS_COUNTRY"
				)
				{
					$requisites["fields"][$b24_field] = $store_field;
				}
				else
				{
					$qr["fields"][$b24_field] = $store_field;
				}


			}// end if deal or lead+contact
			/**
			 * Если простой Лид
			 */
			else
			{
				$qr["fields"][$b24_field] = $store_field;
			}

		}//END FOR


		$qr['fields']['SOURCE_ID']          = $this->params->get('lead_source');
		$qr['fields']['SOURCE_DESCRIPTION'] = $this->params->get('source_description');
		$product_rows                       = [];
		$b24_comment                        = '<br><hr><br>';
		$a                                  = 0;
		foreach ($orderItems as $items)
		{
			$product_rows[$a]['PRODUCT_NAME'] = $items->order_item_name;
			$product_rows[$a]['PRICE']        = $items->product_final_price;
			$product_rows[$a]['QUANTITY']     = $items->product_quantity;


			if ($this->params->get('product_image') == 1)
			{
				$b24_comment .= HTMLHelper::image(Uri::root() . $this->getProductImage($items->virtuemart_media_id[0]), '', ['width' => '150px']) . '<br/>';
			}
			if ($this->params->get('product_link') == 1)
			{
				$b24_comment .= HTMLHelper::link(Route::_($items->link, false, '', true), $items->product_name) . '<br/>';
			}
			else
			{
				$b24_comment .= $items->product_name . '<br/>';
			}
			if ($this->params->get('product_sku') == 1)
			{
				$b24_comment .= Text::_('PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_SKU') . ': ' . $items->product_sku . '<br/>';
			}
			if ($this->params->get('product_gtin') == 1)
			{
				$b24_comment .= Text::_('PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_GTIN') . ': ' . $items->product_gtin . '<br/>';
			}
			if ($this->params->get('product_mpn') == 1)
			{
				$b24_comment .= Text::_('PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_MPN') . ': ' . $items->product_mpn . '<br/>';
			}
			if ($this->params->get('product_weight') == 1)
			{
				$b24_comment .= Text::_('PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_WEIGHT') . ': ' . $items->product_weight . '<br/>';
			}
			if ($this->params->get('product_length') == 1)
			{
				$b24_comment .= Text::_('PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_LENGTH') . ': ' . $items->product_length . '<br/>';
			}
			if ($this->params->get('product_width') == 1)
			{
				$b24_comment .= Text::_('PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_WIDTH') . ': ' . $items->product_width . '<br/>';
			}
			if ($this->params->get('product_height') == 1)
			{
				$b24_comment .= Text::_('PLG_WT_VM_B24_LEAD_VIRTUEMART_PRODUCT_HEIGHT') . ': ' . $items->product_height . '<br/>';
			}


			/*
			 * Virtuemart Custom fields - cart attributes
			 */
			if ($this->params->get('product_cf_cart_attr_desc') == 1 && !empty($items->product_attribute))
			{
				$product_attributes = json_decode($items->product_attribute);

				foreach ($product_attributes as $attr => $value)
				{

					foreach ($items->customfields as $customfield)
					{
						if (($customfield->virtuemart_custom_id == $attr) && ($customfield->virtuemart_customfield_id == $value))
						{
							$b24_comment .= '<strong>' . $customfield->custom_title . ':</strong> ' . $customfield->customfield_value . '<br/>';
						}
					}
				}
			}
			$a++;
		}


		$b24_comment              .= HTMLHelper::link(URI::root() . 'administrator?option=com_virtuemart&view=orders&task=edit&virtuemart_order_id=' . $order->virtuemart_order_id, 'See this order in Virtuemart', ['target' => '_blank']);
		$qr['fields']['COMMENTS'] .= $b24_comment;

		$this->checkUtms($qr);

		/**
		 * Добавление лида или сделки на определенную стадию (с определенным статусом)
		 */

		if ($this->params->get("create_lead_or_deal_on_specified_stage") == 1)
		{
			if ($plugin_mode == "lead" && !empty($this->params->get("lead_status")))
			{
				$qr["fields"]["STATUS_ID"] = $this->params->get("lead_status");
			}
			elseif ($plugin_mode == "deal" && !empty($this->params->get("deal_stage")))
			{

				$qr["fields"]["STAGE_ID"]    = $this->params->get("deal_stage");
				$qr["fields"]["CATEGORY_ID"] = $this->params->get("deal_category");
			}
		}


//		if (!empty($this->params->get("assigned_by_id")))
//		{
//			$qr["fields"]["ASSIGNED_BY_ID"] = $this->params->get("assigned_by_id");
//		}

		if ($plugin_mode == "deal" || ($plugin_mode == "lead" && $this->params->get('create_contact_for_unknown_lead') == 1))
		{
			/**
			 * Ищем дубли контактов
			 *
			 */

			$search_duobles_by_phone = $contact["fields"]["PHONE"][0]["VALUE"];
			$search_duobles_by_email = $contact["fields"]["EMAIL"][0]["VALUE"];

			$find_doubles         = [
				'find_doubles_by_phone' => [
					'method' => 'crm.duplicate.findbycomm',
					'params' => [
						"type"   => "PHONE",
						"values" => [$search_duobles_by_phone]
					],
				],
				'find_doubles_by_email' => [
					'method' => 'crm.duplicate.findbycomm',
					'params' => [
						"type"   => "EMAIL",
						"values" => [$search_duobles_by_email]
					]
				]
			];
			$find_doublesBitrix24 = Crest::callBatch($find_doubles);

			if ($debug == 1)
			{
				$this->prepareDebugInfo("FIND_DOUBLES -> array TO BITRIX 24 with information for search duplicate contacts", $find_doubles);
				$this->prepareDebugInfo("FIND_DOUBLES <- response array FROM BITRIX 24 with information about search results for duplicate contacts", $find_doublesBitrix24);
			}


			/**
			 * Конец поиска дублей контактов
			 *
			 * Начинаем разбор.
			 * Проверяем, не пустой ли массив.
			 * Проверяем, сколько найдено совпадений. Если больше одного совпадения - всю информацию отправляем в комментарий к сделке.
			 */
			if (!empty($find_doublesBitrix24["result"]["result"]["find_doubles_by_phone"]["CONTACT"]) && !empty($find_doublesBitrix24["result"]["result"]["find_doubles_by_phone"]["CONTACT"][0]))
			{
				if (count($find_doublesBitrix24["result"]["result"]["find_doubles_by_phone"]["CONTACT"]) > 1)
				{
					/*
					 * Если найдено больше одного совпадения по телефону
					 */
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($contact, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_1'));
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($requisites, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_1'));

					if ($plugin_mode == "lead")
					{
						$this->addLead($qr, $product_rows, $debug);

						return;
					}
					elseif ($plugin_mode == "deal")
					{
						$this->addDeal($qr, $product_rows, $debug);

						return;
					}


				}
				else
				{
					$b24contact_id_by_phone = $find_doublesBitrix24["result"]["result"]["find_doubles_by_phone"]["CONTACT"][0];
				}
			}
			if (!empty($find_doublesBitrix24["result"]["result"]["find_doubles_by_email"]["CONTACT"]) && !empty($find_doublesBitrix24["result"]["result"]["find_doubles_by_email"]["CONTACT"][0]))
			{
				if (count($find_doublesBitrix24["result"]["result"]["find_doubles_by_email"]["CONTACT"]) > 1)
				{
					/*
					 * Если найдено больше одного совпадения по email
					 */

					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($contact, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_2'));
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($requisites, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_2'));

					if ($plugin_mode == "lead")
					{
						$this->addLead($qr, $product_rows, $debug);

						return;
					}
					elseif ($plugin_mode == "deal")
					{
						$this->addDeal($qr, $product_rows, $debug);

						return;
					}

				}
				else
				{
					$b24contact_id_by_email = $find_doublesBitrix24["result"]["result"]["find_doubles_by_email"]["CONTACT"][0];
				}

			}


			/**
			 *  Найдены совпадения И по email И по телефону
			 */
			if (!is_null($b24contact_id_by_email) && !is_null($b24contact_id_by_phone))
			{
				/*
				 * Проверяем, одинаковые ли CONTACT_ID при совпадении по телефону и почте
				 */
				if ($b24contact_id_by_email == $b24contact_id_by_phone)
				{
					$qr["fields"]["CONTACT_ID"] = $b24contact_id_by_email;
				}
				else
				{
					/**
					 * Если CONTACT_ID разные - пишем все в комментарий к сделке/лиду.
					 */

					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($contact, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_3'));
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($requisites, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_3'));

				}
			}// END Найдены совпадения И по email И по телефону

			/**
			 *  У контакта совпал телефон, но не совпал email
			 */
			elseif (!is_null($b24contact_id_by_phone) && is_null($b24contact_id_by_email))
			{
				$upd_info_email = [
					'EMAIL' => [
						'n0' => [
							'VALUE' => $contact["fields"]["EMAIL"]["n0"]["VALUE"],
							'TYPE'  => 'WORK'
						]
					]
				];


				$updateContactResult = $this->updateContact($b24contact_id_by_phone, $upd_info_email, $debug); //Добавляем в контакт EMAIL

				if ($updateContactResult == false)
				{
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($contact, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_4'));
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($requisites, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_4'));
				}
				else
				{

					$qr["fields"]["CONTACT_ID"] = $b24contact_id_by_phone;
				}
			}// END У контакта совпал телефон, но не совпал email

			/**
			 *  У контакта совпал email, но не совпал телефон
			 */
			elseif (!is_null($b24contact_id_by_email) && is_null($b24contact_id_by_phone))
			{
				$upd_info_phone = [
					'PHONE' => [
						'n0' => [
							'VALUE' => $contact["fields"]["PHONE"]["n0"]["VALUE"],
							'TYPE'  => 'WORK'
						]
					]
				];

				$updateContactResult = $this->updateContact($b24contact_id_by_email, $upd_info_phone, $debug); //Добавляем в контакт PHONE

				if ($updateContactResult == false)
				{
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($contact, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_4'));
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($requisites, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_4'));
				}
				else
				{

					$qr["fields"]["CONTACT_ID"] = $b24contact_id_by_email;
				}

			}// END У контакта совпал email, но не совпал телефон

			/**
			 *  Нет совпадений с контактами. Создаем новый контакт.
			 */
			elseif (is_null($b24contact_id_by_email) && is_null($b24contact_id_by_phone))
			{
				$b24contact_id = $this->addContact($contact, $debug); //Получаем contact id
				if ($b24contact_id == false)
				{
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($contact, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_5'));
					$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($requisites, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_5'));
				}
				else
				{
					$addRaddRequisitesResult = $this->addRequisites($b24contact_id, $requisites, $debug);
					/**
					 * Если ошибка добавления реквизитов
					 */
					if ($addRaddRequisitesResult == false)
					{
						/**
						 * Пишем реквизиты в комментарий
						 */
						$qr["fields"]["COMMENTS"] .= $this->prepareDataToSaveToComment($requisites, Text::_('PLG_WT_VM_B24_ALERT_MESSAGE_6'));
					}
					else
					{
						/**
						 * Добавляем к лиду/сделке CONTACT_ID
						 */
						$qr["fields"]["CONTACT_ID"] = $b24contact_id;
					}
				}
			}


			if ($debug == 1)
			{

				$this->prepareDebugInfo("QR - order info array prepared to send to Bitrix24", $qr);
				$this->prepareDebugInfo("Product_rows - products rows array for include to lead or deal, prepared to send to Bitrix24", $product_rows);
				$this->prepareDebugInfo("Contact - contact array to send to functions (name, phone, email etc.)", $contact);
				$this->prepareDebugInfo("Requisites - Requisites array to send to functions (address, city, country etc.)", $requisites);
			}


			if ($plugin_mode == "deal")
			{
				/**
				 * Добавляем сделку
				 */
				$b24result = $this->addDeal($qr, $product_rows, $debug, $order->order_id);
			}
			elseif ($plugin_mode == "lead" && $this->params->get('create_contact_for_unknown_lead') == 1)
			{
				/**
				 * Добавляем лид
				 */
				$b24result = $this->addLead($qr, $product_rows, $debug, $order->order_id);
			}

		}
		else
		{ // Простой лид
			$b24result = $this->addLead($qr, $product_rows, $debug, $order->order_id);
		}


		if ($debug == 1)
		{
			$this->prepareDebugInfo("Bitrix24 result array", $b24result);
		}

		if ($debug == 1)
		{
			$session    = ($this->getApplication() instanceof SessionAwareWebApplicationInterface ? $this->getApplication()->getSession() : null);
			$debug_info = $session->get("b24debugoutput");
			echo "<h3>WT Virtuemart Bitrix24 debug information</h3><br/>" . $debug_info;
			$session->clear("b24debugoutput");

		}

	}


	/** Returns product image src by id
	 *
	 * @param   Int  $virtuemart_media_id
	 *
	 * @return string file_url
	 *
	 * @since version 1.0
	 */
	private function getProductImage($virtuemart_media_id)
	{
		$db    = $this->getDatabase();
		$query = $db->getQuery(true);
		$query->select($db->quoteName('file_url'))
			->from($db->quoteName('#__virtuemart_medias'))
			->where($db->quoteName('virtuemart_media_id') . ' = ' . $db->quote($virtuemart_media_id));
		$db->setQuery($query);
		$file_url = $db->loadAssoc();

		return $file_url['file_url'];
	}


	/** Returns country name by id
	 *
	 * @param   Int  $country_id
	 *
	 * @return string
	 *
	 * @since version 1.0
	 */
	private function getCountryName($country_id)
	{
		$lang         = $this->getApplication()->getLanguage();
		$current_lang = $lang->getTag();
		$db           = $this->getDatabase();
		$query        = $db->getQuery(true);
		$query->select($db->quoteName('country_name'))
			->from($db->quoteName('#__virtuemart_countries'))
			->where($db->quoteName('virtuemart_country_id') . ' = ' . $db->quote($country_id));
		$db->setQuery($query);
		$country_name = $db->loadAssoc();

		return $country_name['country_name'];
	}

	/** Returns state name by id
	 *
	 * @param   Int  $country_id
	 *
	 * @return string
	 *
	 * @since version 1.0
	 */
	private function getStateName($state_id)
	{
		$lang         = Factory::getApplication()->getLanguage();
		$current_lang = $lang->getTag();
		$db           = $this->getDatabase();
		$query        = $db->getQuery(true);
		$query->select($db->quoteName('state_name'))
			->from($db->quoteName('#__virtuemart_states'))
			->where($db->quoteName('virtuemart_state_id') . ' = ' . $db->quote($state_id));
		$db->setQuery($query);
		$country_name = $db->loadAssoc();

		return $country_name['state_name'];
	}


	/** Returns shipping method name by id
	 *
	 * @param   Int  $shipping_method_id
	 *
	 * @return string
	 *
	 * @since version 1.0
	 */

	private function getShippingMethodName($shipping_method_id, $order_language)
	{
		$order_language = strtolower(str_replace('-', '_', $order_language));
		$db             = $this->getDatabase();
		$query          = $db->getQuery(true);
		$query->select($db->quoteName('shipment_name'))
			->from($db->quoteName('#__virtuemart_shipmentmethods_' . strtolower($order_language))) //.$current_lang
			->where($db->quoteName('virtuemart_shipmentmethod_id') . ' = ' . $db->quote($shipping_method_id));
		$db->setQuery($query);
		$shipping_name = $db->loadAssoc();

		return $shipping_name['shipment_name'];
	}

	/** Returns payment method name by id
	 *
	 * @param   Int  $payment_method_id
	 *
	 * @return string
	 *
	 * @since version 1.0
	 */
	private function getPaymentMethodName($payment_method_id, $order_language)
	{

		$order_language = strtolower(str_replace('-', '_', $order_language));
		$db             = $this->getDatabase();
		$query          = $db->getQuery(true);
		$query->select($db->quoteName('payment_name'))
			->from($db->quoteName('#__virtuemart_paymentmethods_' . $order_language))
			->where($db->quoteName('virtuemart_paymentmethod_id') . ' = ' . $db->quote($payment_method_id));
		$db->setQuery($query);
		$payment_name = $db->loadAssoc();

		return $payment_name['payment_name'];
	}

	/**
	 * Function checks the utm marks and set its to array fields
	 *
	 * @param  $qr        array    Bitrix24 array data
	 *
	 * @return            array    Bitrix24 array data with UTMs
	 * @since    2.4.1
	 */
	private function checkUtms(&$qr): array
	{
		$utms = array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content',
			'utm_term'
		);
		foreach ($utms as $key)
		{
			$utm                     = $this->getApplication()->getInput()->cookie->get($key, '', 'raw');
			$utm                     = urldecode($utm);
			$utm_name                = strtoupper($key);
			$qr["fields"][$utm_name] = $utm;
		}

		return $qr;
	}

	/**
	 * Adding Lead to Bitrix24
	 *
	 * @param   array   $qr            mixed array with contact and deal data
	 * @param   array   $product_rows  product rows for lead
	 * @param   string  $debug         to enable debug data from function
	 *
	 * @return array|bool Bitrix24 response array or false if
	 *
	 * @since 2.0.0
	 */

	private function addLead($qr, $product_rows, $debug)
	{
		$arData['add_lead'] = [
			'method' => 'crm.lead.add',
			'params' => $qr
		];

		if (!empty($product_rows))
		{
			$arData['add_products'] = [
				'method' => 'crm.lead.productrows.set',
				'params' => [
					'id'   => '$result[add_lead]',
					'rows' => $product_rows
				]
			];
		}
		$resultBitrix24 = Crest::callBatch($arData);
		if ($debug == 1)
		{
			$this->prepareDebugInfo("function addLead - prepared array to send to Bitrix 24(arData)", $arData);
			$this->prepareDebugInfo("function addLead - Bitrix 24 response array (resultBitrix24)", $resultBitrix24);

		}

		if (!isset($resultBitrix24["result"]["result_error"]) || !isset($resultBitrix24["error"]))
		{
			return $resultBitrix24;
		}
		else
		{
			$this->saveToLog(print_r($resultBitrix24, true),'ERROR');

			return false;
		}
	}

	/**
	 * Adding Deal to Bitrix24
	 *
	 * @param   array  $qr            array with deal data
	 * @param   array  $product_rows  product rows for lead
	 * @param   array  $debug         boolean to enable debug data from function
	 *
	 * @return array|bool Bitrix24 response array or false if there is no anything in Bitrix 24 response
	 *
	 * @see   https://dev.1c-bitrix.ru/rest_help/crm/productrow/crm_item_productrow_add.php
	 * @since 2.0.0
	 */
	private function addDeal($qr, $product_rows, $debug)
	{
		$arData = [
			'add_deal'     => [
				'method' => 'crm.deal.add',
				'params' => $qr
			],
			'add_products' => [
				'method' => 'crm.deal.productrows.set',
				'params' => [
					'id'   => '$result[add_deal]',
					'rows' => $product_rows
				]
			]
		];

		$resultBitrix24 = Crest::callBatch($arData);


		if ($debug == 1)
		{
			$this->prepareDebugInfo("function addDeal - prepared to Bitrix 24 array (arData)", $arData);
			$this->prepareDebugInfo("function addDeal - Bitrix 24 response array (resultBitrix24)", $resultBitrix24);
		}

		if (!isset($resultBitrix24["result"]["result_error"]) || !isset($resultBitrix24["error"]))
		{
			return $resultBitrix24;
		}
		else
		{
			$this->saveToLog(print_r($resultBitrix24, true),'ERROR');

			return false;
		}
	}

	/**
	 * function prepareDataToSaveToComment
	 *
	 * @param $data    array contact or requisite array to implode with key names
	 * @param $message string Message for to wrap this data in comment
	 *
	 * @return string  Stringified contact or requisite info to inqlude in lead/deal comment
	 * @since  2.0.0
	 */
	private function prepareDataToSaveToComment(array $data, string $message): string
	{
		$string = "<br/>== " . $message . " ==<br/>";
		foreach ($data["fields"] as $key => $value)
		{
			if ($key == "PHONE" || $key == "EMAIL" || $key == "FAX")
			{
				$string .= "<strong>" . Text::_('PLG_WT_VM_B24_LEAD_' . strtoupper($key)) . ":</strong> " . $value["n0"]["VALUE"] . "<br/>";
			}
			else
			{
				$string .= "<strong>" . Text::_('PLG_WT_VM_B24_LEAD_' . strtoupper($key)) . ":</strong> " . $value . "<br/>";
			}
		}
		$string .= "== " . $message . " ==<br/>";

		return $string;
	}

	/**
	 * @param   string  $debug_section_header
	 * @param   mixed   $debug_data
	 *
	 *
	 * @since 2.0.0
	 */
	private function prepareDebugInfo(string $debug_section_header, $debug_data): void
	{
		$app = $this->getApplication();

		// Берем сессию только в HTML фронте
		$session = ($app instanceof SessionAwareWebApplicationInterface ? $app->getSession() : null);
		if (is_array($debug_data) || is_object($debug_data))
		{
			$debug_data = print_r($debug_data, true);
		}
		$debug_output = $session->get("b24debugoutput");

		$debug_output .= "<details style='border:1px solid #0FA2E6; margin-bottom:5px;'>";
		$debug_output .= "<summary style='background-color:#384148; color:#fff;'>" . $debug_section_header . "</summary>";
		$debug_output .= "<pre style='background-color: #eee; padding:10px;'>";
		$debug_output .= $debug_data;
		$debug_output .= "</pre>";
		$debug_output .= "</details>";

		$session->set("b24debugoutput", $debug_output);

	}// END prepareDebugInfo

	/**
	 * Add Contact to Bitrix24
	 *
	 * @param $contact array array with user contact data
	 * @param $debug   string to enable debug data from function
	 *
	 * @return array|bool Bitrix24 response array or false
	 *
	 * @since 2.0.0
	 */

	private function addContact($contact, $debug)
	{
		$resultBitrix24 = Crest::call("crm.contact.add", $contact);

		if ($debug == 1)
		{
			$this->prepareDebugInfo("function addContact - Bitrix 24 response array", $resultBitrix24);
		}

		if (isset($resultBitrix24["result"]["result_error"]) || isset($resultBitrix24["error"]))
		{
			$this->saveToLog(print_r($resultBitrix24, true),'ERROR');
			return false;
		}
		else
		{
			return $resultBitrix24["result"];
		}


	}

	/**
	 * @param $contact_id
	 * @param $upd_info
	 * @param $debug
	 *
	 * @return bool
	 *
	 * @since 2.0.0
	 */
	private function updateContact($contact_id, $upd_info, $debug): bool
	{

		$req_crm_contact_fields = Crest::call(
			"crm.contact.update", [
				'ID'     => $contact_id,
				'fields' => $upd_info
			]
		);

		if ($debug == 1)
		{
			$this->prepareDebugInfo("function updateContact -> prepared info to send to Bitrix 24", $upd_info);
			$this->prepareDebugInfo("function updateContact <- respone array from Bitrix 24", $req_crm_contact_fields);
		}

		if (isset($req_crm_contact_fields["result"]["result_error"]) || isset($resultBitrix24["error"]))
		{
			$this->saveToLog(print_r($req_crm_contact_fields, true),'ERROR');
			return false;
		}
		else
		{
			return true;
		}


	}

	/**
	 * function for to add Requisites to Contact in Bitrix24
	 * @param $contact_id string a contact id in Bitrix24
	 * @param $requisites array an array with custromer address data
	 * @param $contact array an array with contact data. For naming requisites
	 * @param $debug string to enable debug data from function
	 * @return false boolean If any errors return false
	 * @return true boolean If Requisites added successfully
	 */
	private function addRequisites($contact_id, $requisites, $debug): bool
	{

		$url                  = $this->params->get('crm_host');
		$check_domain_zone_ru = preg_match("/(.ru)/", $url);
		if ($check_domain_zone_ru == 1)
		{
			$preset_id = 5;//Россия: Организация - 1, Индивидуальный предприниматель - 3, Физическое лицо - 5.
		}
		else
		{
			$preset_id = 3;//Остальные страны: Организация - 1, Физическое лицо  - 3,
		}
		$resultRequisite = Crest::call(
			'crm.requisite.add',
			[
				'fields' => [
					'ENTITY_TYPE_ID' => 3,//3 - контакт, 4 - компания
					'ENTITY_ID'      => $contact_id,//contact id
					'PRESET_ID'      => $preset_id,//Россия: Организация - 1, Индивидуальный предприниматель - 3, Физическое лицо - 5. Украина: Организация - 1, Физическое лицо  - 3,
					'NAME'           => 'Person',
					'ACTIVE'         => 'Y'
				]
			]
		);

		$resultAddress = Crest::call(
			'crm.address.add',
			[
				'fields' => [
					'TYPE_ID'        => 1,//Фактический адрес - 1, Юридический адрес - 6, Адрес регистрации - 4, Адрес бенефициара - 9
					'ENTITY_TYPE_ID' => 8,//ID типа родительской сущности. 8 - Реквизит
					'ENTITY_ID'      => $resultRequisite["result"],// ID созданного реквизита
					'COUNTRY'        => $requisites['fields']['ADDRESS_COUNTRY'],
					'PROVINCE'       => $requisites['fields']['ADDRESS_PROVINCE'],
					'POSTAL_CODE'    => $requisites['fields']['ADDRESS_POSTAL_CODE'],
					'CITY'           => $requisites['fields']['ADDRESS_CITY'],
					'ADDRESS_1'      => $requisites['fields']['ADDRESS'],
					'ADDRESS_2'      => $requisites['fields']['ADDRESS_2'],
				]
			]
		);

		if ($debug == 1)
		{
			$this->prepareDebugInfo("function addRequisites -> Requisites array", $requisites);
			$this->prepareDebugInfo("function addRequisites - addRequisites section - <- respone array from Bitrix 24", $resultRequisite);
			$this->prepareDebugInfo("function addRequisites - addAddress (to requisite) section -  <- respone array from Bitrix 24", $resultAddress);
		}
		if (isset($resultRequisite["result"]["result_error"]) || isset($resultBitrix24["error"]))
		{
			return false;
		}
		else
		{
			return true;
		}

	}

	/**
	 * Добавляем js-скрпиты на HTML-фронт
	 *
	 * @throws \Exception
	 * @since 3.0.0
	 */
	function onAfterDispatch()
	{
		// We are not work in Joomla API or CLI ar Admin area
		if (!$this->getApplication()->isClient('site')) return;
		$doc = $this->getApplication()->getDocument();
		$wa = $doc->getWebAssetManager();
		// Show plugin version in browser console from js-script for UTM
		$wtb24_plugin_info = simplexml_load_file(JPATH_SITE . "/plugins/system/wt_vm_b24/wt_vm_b24.xml");
		$doc->addScriptOptions('plg_system_wt_vm_b24', ['version' => (string) $wtb24_plugin_info->version]);
		$wa->registerAndUseScript('plg_system_wt_vm_b24.wt_vm_b24_utm', 'plg_system_wt_vm_b24/wt_vm_b24_utm.js', array('version' => 'auto', 'relative' => true));

	}

	/**
	 * Function for to log library errors in plg_system_wt_jshopping_b24_pro.log.php in
	 * Joomla log path. Default Log category plg_system_wt_jshopping_b24_pro
	 *
	 * @param   string  $data      error message
	 * @param   string  $priority  Joomla Log priority
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function saveToLog(string $data, string $priority = 'NOTICE'): void
	{
		Log::addLogger(
			array(
				// Sets file name
				'text_file' => 'plg_system_wt_vm_b24_pro.log.php',
			),
			// Sets all but DEBUG log level messages to be sent to the file
			Log::ALL & ~Log::DEBUG,
			array('plg_system_wt_vm_b24_pro')
		);
		$this->getApplication()->enqueueMessage($data, $priority);
		$priority = 'Log::' . $priority;
		Log::add($data, $priority, 'plg_system_wt_vm_b24');

	}

}//plgSystemWt_vm_b24_pro