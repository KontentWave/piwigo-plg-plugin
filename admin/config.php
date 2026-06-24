<?php
defined('PROFILE_LIVENESS_GUARD_PATH') or die('Hacking attempt!');

// +-----------------------------------------------------------------------+
// | Configuration tab                                                     |
// +-----------------------------------------------------------------------+

// save config
if (isset($_POST['save_config']))
{
  $conf['profile_liveness_guard'] = array(
    'verification_interval_days' => max(1, intval($_POST['verification_interval_days'])),
    'challenge_grace_hours' => max(1, intval($_POST['challenge_grace_hours'])),
    'due_scan_enabled' => isset($_POST['due_scan_enabled']),
    'auto_privatize_enabled' => isset($_POST['auto_privatize_enabled']),
  );

  conf_update_param('profile_liveness_guard', $conf['profile_liveness_guard']);
  $page['infos'][] = l10n('Information data registered in database');
}

// send config to template
$template->assign(array(
  'profile_liveness_guard' => $conf['profile_liveness_guard'],
));

// define template file
$template->set_filename('profile_liveness_guard_content', realpath(PROFILE_LIVENESS_GUARD_PATH . 'admin/template/config.tpl'));
