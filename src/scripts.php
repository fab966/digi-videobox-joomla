<?php
/**
 * @author      HitkoDev
 * @copyright   Copyright (C) 2016 HitkoDev
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @package     pkg_videobox - Videobox
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;

class pkg_videoboxInstallerScript
{
    public function install($parent)
    {
        $this->in_up($parent, 'install');
    }

    public function update($parent)
    {
        $this->in_up($parent, 'update');
    }

    private function in_up($parent, $type)
    {
        echo Text::_('PLG_SYSTEM_VIDEOBOX_INSTALL_DESCRIPTION');
    }

    public function uninstall($parent)
    {
        // Nothing specific for uninstall
    }

    public function preflight($type, $parent)
    {
        $version = new Version();

        if (!$version->isCompatible('4.0'))
        {
            $typing = $type === 'update' ? 'updating' : 'installing';
            Factory::getApplication()->enqueueMessage(
                'Please update Joomla! to version 4.0 or later before ' . $typing . ' Videobox',
                'warning'
            );

            return false;
        }

        return true;
    }

    public function postflight($type, $parent)
    {
        // Nothing specific after install/update
    }
}
