<?php
defined('PROFILE_LIVENESS_GUARD_PATH') or die('Hacking attempt!');

include_once(PROFILE_LIVENESS_GUARD_PATH . 'include/functions.inc.php');

function profile_liveness_guard_ws_add_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'profile_liveness_guard.runDueScan',
    'profile_liveness_guard_ws_run_due_scan',
    array(
      'pwg_token' => array(),
    ),
    'Run the Profile Liveness Guard due scan and expiry handling.',
    null,
    array(
      'admin_only' => true,
      'post_only' => true,
    )
  );
}

function profile_liveness_guard_ws_run_due_scan($params, &$service)
{
  global $user;

  if (!is_webmaster())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() !== (string) ($params['pwg_token'] ?? ''))
  {
    return new PwgError(403, 'Invalid security token');
  }

  return profile_liveness_guard_run_due_scan((int) ($user['id'] ?? 0));
}