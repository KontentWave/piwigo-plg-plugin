<?php
defined('PROFILE_LIVENESS_GUARD_PATH') or die('Hacking attempt!');

include_once(PROFILE_LIVENESS_GUARD_PATH . 'include/functions.inc.php');

global $conf, $page, $template, $user;

if (isset($_POST['plg_restore_record']))
{
  if (get_pwg_token() !== (string) ($_POST['pwg_token'] ?? ''))
  {
    $page['errors'][] = l10n('Invalid security token');
  }
  else
  {
    $target = explode(':', (string) ($_POST['plg_restore_record'] ?? ''), 2);
    $result = profile_liveness_guard_restore_record(
      (int) ($target[0] ?? 0),
      (int) ($target[1] ?? 0),
      (int) $user['id']
    );

    if (!empty($result['success']))
    {
      $page['infos'][] = l10n('Profile visibility restored successfully.');
    }
    else
    {
      $page['errors'][] = $result['message'];
    }
  }
}

// +-----------------------------------------------------------------------+
// | Home tab                                                              |
// +-----------------------------------------------------------------------+

// send variables to template
$template->assign(array(
  'profile_liveness_guard' => $conf['profile_liveness_guard'],
  'PLG_OVERVIEW' => profile_liveness_guard_get_admin_overview(),
  'PLG_RESTORE_CANDIDATES' => profile_liveness_guard_get_admin_restore_candidates(),
  'PLG_RECENT_LOGS' => profile_liveness_guard_get_admin_recent_logs(),
  'PWG_TOKEN' => get_pwg_token(),
  'INTRO_CONTENT' => load_language('intro.html', PROFILE_LIVENESS_GUARD_PATH, array('return'=>true)),
));

// define template file
$template->set_filename('profile_liveness_guard_content', realpath(PROFILE_LIVENESS_GUARD_PATH . 'admin/template/home.tpl'));
