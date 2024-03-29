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
namespace Joomla\Plugin\System\Wt_vm_b24\Fields;
defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Plugin\System\Wt_vm_b24\Library\CRest;

FormHelper::loadFieldClass('list');

class B24leadstatusField extends ListField
{

	protected $type = 'B24leadstatus';

	protected function getOptions()
	{

		if (PluginHelper::isEnabled('system', 'wt_vm_b24') === true)
		{
			$plugin          = PluginHelper::getPlugin('system', 'wt_vm_b24');
			$params          = (!empty($plugin->params) ? json_decode($plugin->params) : '');
			$crm_host        = (!empty($params->crm_host) ? $params->crm_host : '');
			$webhook_secret  = (!empty($params->crm_webhook_secret) ? $params->crm_webhook_secret : '');
			$crm_assigned_id = (!empty($params->crm_assigned) ? $params->crm_assigned : '');

			if (!empty($crm_host) && !empty($webhook_secret) && !empty($crm_assigned_id))
			{

				$params         = [
					'filter' => [
						'ENTITY_ID' => 'STATUS'
					],
					'order'  => [
						'SORT' => 'ASC'
					]
				];
				$resultBitrix24 = CRest::call("crm.status.list", $params);
				$options        = array();
				if (isset($resultBitrix24["result"]))
				{

					foreach ($resultBitrix24["result"] as $lead_status)
					{
						$options[] = HTMLHelper::_('select.option', $lead_status["STATUS_ID"], $lead_status["NAME"]);
					}

					return $options;
				}

				return $options;
			}
		}
	}
}

?>