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

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Wt_vm_b24\Extension\Wt_vm_b24;

defined('_JEXEC') or die;

return new class () implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	public function register(Container $container)
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$subject = $container->get(DispatcherInterface::class);
				$config  = (array) PluginHelper::getPlugin('system', 'wt_vm_b24');
				$plugin = new Wt_vm_b24($subject, $config);
				$plugin->setApplication(Factory::getApplication());
				$plugin->setDatabase(Factory::getContainer()->get(DatabaseInterface::class));
				return $plugin;
			}
		);
	}
};