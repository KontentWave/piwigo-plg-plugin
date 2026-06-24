<?php
defined('PROFILE_LIVENESS_GUARD_PATH') or die('Hacking attempt!');

include_once(PROFILE_LIVENESS_GUARD_PATH . 'include/functions.inc.php');

function profile_liveness_guard_handle_profile_actions()
{
  global $user, $page;

  $is_send_request = isset($_POST['plg_request_sms']);
  $is_verify_request = isset($_POST['plg_verify_code']);
  if (!$is_send_request && !$is_verify_request)
  {
    return;
  }

  if (is_a_guest() || empty($user['id']) || !connected_with_pwg_ui())
  {
    $message = l10n('Access denied.');
    $page['errors'][] = $message;
    $page['profile_liveness_guard_feedback'] = array('type' => 'error', 'message' => $message);
    return;
  }

  if (!profile_liveness_guard_is_eligible_user((int) $user['id']))
  {
    $message = l10n('Profile Liveness Guard applies only to non-admin album owners.');
    $page['errors'][] = $message;
    $page['profile_liveness_guard_feedback'] = array('type' => 'error', 'message' => $message);
    return;
  }

  if ($is_send_request)
  {
    $result = profile_liveness_guard_request_sms((int) $user['id'], (int) $user['id'], 'owner');
    if (!empty($result['success']))
    {
      $message = l10n('A verification SMS was sent to %s.', $result['masked_phone']);
      $page['infos'][] = $message;
      $page['profile_liveness_guard_feedback'] = array('type' => 'success', 'message' => $message);
    }
    else
    {
      $page['errors'][] = $result['message'];
      $page['profile_liveness_guard_feedback'] = array('type' => 'error', 'message' => $result['message']);
    }
    return;
  }

  $code = trim((string) ($_POST['plg_verification_code'] ?? ''));
  $result = profile_liveness_guard_confirm_code((int) $user['id'], $code, (int) $user['id']);
  if (!empty($result['success']))
  {
    if (!empty($result['late']))
    {
      $message = l10n('Verification succeeded, but administrator restoration is still required.');
    }
    else
    {
      $message = l10n('Profile verification confirmed successfully.');
    }

    $page['infos'][] = $message;
    $page['profile_liveness_guard_feedback'] = array('type' => 'success', 'message' => $message);
  }
  else
  {
    $page['errors'][] = $result['message'];
    $page['profile_liveness_guard_feedback'] = array('type' => 'error', 'message' => $result['message']);
  }
}