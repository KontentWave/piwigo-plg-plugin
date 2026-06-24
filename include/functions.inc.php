<?php
defined('PROFILE_LIVENESS_GUARD_PATH') or die('Hacking attempt!');

function profile_liveness_guard_get_default_conf()
{
  return array(
    'verification_interval_days' => 7,
    'challenge_grace_hours' => 48,
    'due_scan_enabled' => true,
    'auto_privatize_enabled' => true,
    'max_send_attempts_per_day' => 3,
    'require_admin_restore' => true,
    'debug_log' => false,
  );
}

function profile_liveness_guard_get_current_conf()
{
  global $conf;

  $stored = isset($conf['profile_liveness_guard']) && is_array($conf['profile_liveness_guard'])
    ? $conf['profile_liveness_guard']
    : array();

  return array_merge(profile_liveness_guard_get_default_conf(), $stored);
}

function profile_liveness_guard_get_locale_presentation($language = null)
{
  global $user;

  if ($language === null)
  {
    $language = isset($user['language']) ? (string) $user['language'] : 'en_UK';
  }

  $font_styles = array(
    'es_ES' => 'font-family: "Source Sans 3", "Noto Sans", "Segoe UI", Arial, sans-serif;',
    'hu_HU' => 'font-family: "Source Sans 3", "Noto Sans", "Segoe UI", Arial, sans-serif;',
    'ru_RU' => 'font-family: "Noto Sans", "DejaVu Sans", "Segoe UI", Arial, sans-serif;',
    'sk_SK' => 'font-family: "Source Sans 3", "Noto Sans", "Segoe UI", Arial, sans-serif;',
    'uk_UA' => 'font-family: "Noto Sans", "DejaVu Sans", "Segoe UI", Arial, sans-serif;',
    'zh_CN' => 'font-family: "Noto Sans CJK SC", "Noto Sans SC", "Microsoft YaHei", "PingFang SC", "Heiti SC", SimSun, sans-serif;',
  );

  return array(
    'language' => $language,
    'lang_attr' => substr(str_replace('_', '-', $language), 0, 5),
    'font_style' => isset($font_styles[$language]) ? $font_styles[$language] : '',
  );
}

function profile_liveness_guard_sql_value($value)
{
  if ($value === null)
  {
    return 'NULL';
  }

  if (is_bool($value))
  {
    return $value ? '1' : '0';
  }

  if (is_int($value) || is_float($value))
  {
    return (string) $value;
  }

  return '\'' . pwg_db_real_escape_string((string) $value) . '\'';
}

function profile_liveness_guard_bootstrap_two_factor()
{
  if (class_exists('PwgTwoFactor') && function_exists('tf_send_sms_message') && function_exists('tf_get_sms_code_ttl'))
  {
    return true;
  }

  $main_file = PHPWG_ROOT_PATH . 'plugins/two_factor/main.inc.php';
  $class_file = PHPWG_ROOT_PATH . 'plugins/two_factor/class/twofactor.class.php';
  $functions_file = PHPWG_ROOT_PATH . 'plugins/two_factor/includes/functions.inc.php';

  if (!defined('TF_REALPATH') && file_exists($main_file))
  {
    include_once($main_file);
  }
  else
  {
    if (!class_exists('PwgTwoFactor') && file_exists($class_file))
    {
      include_once($class_file);
    }
    if (!function_exists('tf_send_sms_message') && file_exists($functions_file))
    {
      include_once($functions_file);
    }
  }

  return class_exists('PwgTwoFactor') && function_exists('tf_send_sms_message') && function_exists('tf_get_sms_code_ttl');
}

function profile_liveness_guard_bootstrap_cpt()
{
  if (function_exists('cpt_get_effective_owner_root_album_data') && function_exists('cpt_update_album'))
  {
    return true;
  }

  $main_file = PHPWG_ROOT_PATH . 'plugins/core_privacy_toggle/main.inc.php';
  $functions_file = PHPWG_ROOT_PATH . 'plugins/core_privacy_toggle/include/functions.inc.php';

  if (!defined('CORE_PRIVACY_TOGGLE_PATH') && file_exists($main_file))
  {
    include_once($main_file);
  }
  else if (!function_exists('cpt_get_effective_owner_root_album_data') && file_exists($functions_file))
  {
    include_once($functions_file);
  }

  return function_exists('cpt_get_effective_owner_root_album_data') && function_exists('cpt_update_album');
}

function profile_liveness_guard_get_now()
{
  list($now) = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
  return $now;
}

function profile_liveness_guard_add_interval($base, $amount, $unit)
{
  $amount = max(0, (int) $amount);
  $unit = strtoupper($unit);
  $allowed_units = array('MINUTE', 'HOUR', 'DAY');

  if (!in_array($unit, $allowed_units, true))
  {
    $unit = 'DAY';
  }

  $query = "SELECT DATE_ADD('" . pwg_db_real_escape_string($base) . "', INTERVAL " . $amount . ' ' . $unit . ") AS target;";
  list($target) = pwg_db_fetch_row(pwg_query($query));
  return $target;
}

function profile_liveness_guard_get_record($user_id, $root_category_id = null)
{
  $user_id = (int) $user_id;

  if ($user_id <= 0)
  {
    return null;
  }

  $query = '
SELECT
    *
  FROM '.PROFILE_LIVENESS_GUARD_TABLE.'
  WHERE user_id = '.$user_id;

  if ($root_category_id !== null)
  {
    $query .= '
    AND root_category_id = '.(int) $root_category_id;
  }

  $query .= '
  ORDER BY id ASC
  LIMIT 1
;';

  $result = pwg_query($query);
  if (pwg_db_num_rows($result) === 0)
  {
    return null;
  }

  return pwg_db_fetch_assoc($result);
}

function profile_liveness_guard_save_record(array $record)
{
  $allowed_columns = array(
    'user_id',
    'root_category_id',
    'status',
    'verified_phone',
    'last_verified_at',
    'next_due_at',
    'challenge_sent_at',
    'challenge_expires_at',
    'last_batch_id',
    'last_msg_id',
    'albums_privatized_at',
    'restored_by',
    'restored_at',
    'last_error',
  );

  $columns = array();
  $values = array();
  $updates = array();

  foreach ($allowed_columns as $column)
  {
    if (!array_key_exists($column, $record))
    {
      continue;
    }

    $columns[] = '`'.$column.'`';
    $values[] = profile_liveness_guard_sql_value($record[$column]);
    $updates[] = '`'.$column.'` = '.profile_liveness_guard_sql_value($record[$column]);
  }

  if (empty($columns))
  {
    return null;
  }

  $query = '
INSERT INTO '.PROFILE_LIVENESS_GUARD_TABLE.'
  ('.implode(', ', $columns).')
VALUES
  ('.implode(', ', $values).')
ON DUPLICATE KEY UPDATE
  '.implode(",\n  ", $updates).'
;';
  pwg_query($query);

  return profile_liveness_guard_get_record(
    (int) $record['user_id'],
    isset($record['root_category_id']) ? (int) $record['root_category_id'] : null
  );
}

function profile_liveness_guard_log_event($user_id, $root_category_id, $event_type, $event_note = null, $actor_user_id = null)
{
  $query = '
INSERT INTO '.PROFILE_LIVENESS_GUARD_LOG_TABLE.'
  (user_id, root_category_id, event_type, event_note, actor_user_id)
VALUES
  ('.(int) $user_id.', '.profile_liveness_guard_sql_value($root_category_id).', '.profile_liveness_guard_sql_value($event_type).', '.profile_liveness_guard_sql_value($event_note).', '.profile_liveness_guard_sql_value($actor_user_id).')
;';
  pwg_query($query);
}

function profile_liveness_guard_get_send_attempts_today($user_id)
{
  $query = '
SELECT COUNT(*)
  FROM '.PROFILE_LIVENESS_GUARD_LOG_TABLE.'
  WHERE user_id = '.(int) $user_id.'
    AND event_type = \'sms_sent\'
    AND DATE(created_at) = CURDATE()
;';

  list($count) = pwg_db_fetch_row(pwg_query($query));
  return (int) $count;
}

function profile_liveness_guard_get_root_album_data($user_id)
{
  if (!profile_liveness_guard_bootstrap_cpt())
  {
    return null;
  }

  return cpt_get_effective_owner_root_album_data((int) $user_id);
}

function profile_liveness_guard_get_user_status($user_id)
{
  $query = '
SELECT status
  FROM '.USER_INFOS_TABLE.'
  WHERE user_id = '.(int) $user_id.'
  LIMIT 1
;';

  $result = pwg_query($query);
  if (pwg_db_num_rows($result) === 0)
  {
    return null;
  }

  $row = pwg_db_fetch_assoc($result);
  return $row['status'] ?? null;
}

function profile_liveness_guard_is_eligible_user($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0)
  {
    return false;
  }

  $status = profile_liveness_guard_get_user_status($user_id);
  if (in_array($status, array('admin', 'webmaster'), true))
  {
    return false;
  }

  $root_album = profile_liveness_guard_get_root_album_data($user_id);
  return !empty($root_album['id']);
}

function profile_liveness_guard_disable_sms_2fa($user_id)
{
  if (!profile_liveness_guard_bootstrap_two_factor())
  {
    return false;
  }

  if (!PwgTwoFactor::isEnabled((int) $user_id, 'sms'))
  {
    return false;
  }

  $tf = new PwgTwoFactor('sms');
  return $tf->deleteSecret((int) $user_id);
}

function profile_liveness_guard_get_phone_number($user_id)
{
  if (!profile_liveness_guard_bootstrap_two_factor())
  {
    return null;
  }

  $tf = new PwgTwoFactor('sms');
  $phone = $tf->getPhoneNumber((int) $user_id);

  if (empty($phone))
  {
    return null;
  }

  return function_exists('tf_normalize_phone_number') ? tf_normalize_phone_number($phone) : $phone;
}

function profile_liveness_guard_mask_phone($phone)
{
  if (empty($phone))
  {
    return null;
  }

  if (function_exists('tf_mask_phone_number'))
  {
    return tf_mask_phone_number($phone);
  }

  $length = strlen($phone);
  if ($length <= 4)
  {
    return str_repeat('*', $length);
  }

  return substr($phone, 0, 3) . str_repeat('*', max(0, $length - 6)) . substr($phone, -3);
}

function profile_liveness_guard_ensure_record($user_id)
{
  $user_id = (int) $user_id;
  if (!profile_liveness_guard_is_eligible_user($user_id))
  {
    return array('success' => false, 'message' => l10n('Profile Liveness Guard applies only to non-admin album owners.'));
  }

  $root_album = profile_liveness_guard_get_root_album_data($user_id);
  if (empty($root_album['id']))
  {
    return array('success' => false, 'message' => l10n('No owned root album could be resolved for this user.'));
  }

  $root_category_id = (int) $root_album['id'];
  $record = profile_liveness_guard_get_record($user_id, $root_category_id);
  $phone = profile_liveness_guard_get_phone_number($user_id);

  if ($record === null)
  {
    if (empty($phone))
    {
      return array('success' => false, 'message' => l10n('No verified SMS phone is available for this user.'));
    }

    $record = profile_liveness_guard_save_record(array(
      'user_id' => $user_id,
      'root_category_id' => $root_category_id,
      'status' => 'not_started',
      'verified_phone' => $phone,
      'next_due_at' => profile_liveness_guard_get_now(),
      'last_error' => null,
    ));
    profile_liveness_guard_log_event($user_id, $root_category_id, 'record_created');
  }
  else
  {
    if (empty($phone))
    {
      $phone = $record['verified_phone'] ?? null;
    }

    if (empty($phone))
    {
      return array('success' => false, 'message' => l10n('No verified SMS phone is available for this user.'));
    }

    if (($record['verified_phone'] ?? '') !== $phone)
    {
      $record = profile_liveness_guard_save_record(array(
        'user_id' => $user_id,
        'root_category_id' => $root_category_id,
        'verified_phone' => $phone,
      ));
    }
  }

  return array(
    'success' => true,
    'record' => $record,
    'root_album' => $root_album,
    'phone' => $phone,
  );
}

function profile_liveness_guard_get_challenge_file($user_id, $root_category_id)
{
  return PROFILE_LIVENESS_GUARD_DIR . 'challenge-' . (int) $user_id . '-' . (int) $root_category_id . '.json';
}

function profile_liveness_guard_store_challenge(array $record, $code, $expires_at)
{
  if (!file_exists(PROFILE_LIVENESS_GUARD_DIR))
  {
    mkdir(PROFILE_LIVENESS_GUARD_DIR, 0755, true);
  }

  $payload = array(
    'code_hash' => pwg_password_hash((string) $code),
    'expires_at' => $expires_at,
    'created_at' => profile_liveness_guard_get_now(),
  );

  return false !== file_put_contents(
    profile_liveness_guard_get_challenge_file($record['user_id'], $record['root_category_id']),
    json_encode($payload)
  );
}

function profile_liveness_guard_load_challenge(array $record)
{
  $file = profile_liveness_guard_get_challenge_file($record['user_id'], $record['root_category_id']);
  if (!file_exists($file))
  {
    return null;
  }

  $payload = json_decode((string) file_get_contents($file), true);
  return is_array($payload) ? $payload : null;
}

function profile_liveness_guard_clear_challenge(array $record)
{
  $file = profile_liveness_guard_get_challenge_file($record['user_id'], $record['root_category_id']);
  if (file_exists($file))
  {
    @unlink($file);
  }
}

function profile_liveness_guard_send_sms_transport($user_id, $phone, $code)
{
  if (!profile_liveness_guard_bootstrap_two_factor())
  {
    return array('success' => false, 'message' => l10n('Two Factor SMS dependency is unavailable.'));
  }

  return tf_send_sms_message($phone, $code, false, (int) $user_id);
}

function profile_liveness_guard_request_sms($user_id, $actor_user_id = null, $source = 'owner')
{
  $settings = profile_liveness_guard_get_current_conf();
  $context = profile_liveness_guard_ensure_record($user_id);
  if (!$context['success'])
  {
    return $context;
  }

  $record = $context['record'];
  $phone = $context['phone'];
  $now = profile_liveness_guard_get_now();

  if ('due_scan' === $source && !$settings['due_scan_enabled'])
  {
    return array('success' => false, 'message' => l10n('Due scan sending is disabled.'));
  }

  if ((int) $settings['max_send_attempts_per_day'] > 0 && profile_liveness_guard_get_send_attempts_today($user_id) >= (int) $settings['max_send_attempts_per_day'])
  {
    return array('success' => false, 'message' => l10n('Daily SMS send limit reached for this profile.'));
  }

  if ('sms_sent' === ($record['status'] ?? '')
    && !empty($record['challenge_expires_at'])
    && strtotime($record['challenge_expires_at']) >= strtotime($now)
    && 'due_scan' === $source)
  {
    return array('success' => true, 'record' => $record, 'masked_phone' => profile_liveness_guard_mask_phone($phone), 'duplicate' => true);
  }

  $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $sms = profile_liveness_guard_send_sms_transport($user_id, $phone, $code);

  if (empty($sms['success']))
  {
    profile_liveness_guard_save_record(array(
      'user_id' => (int) $record['user_id'],
      'root_category_id' => (int) $record['root_category_id'],
      'last_error' => $sms['message'] ?? l10n('Unable to send SMS code right now.'),
    ));
    profile_liveness_guard_log_event($user_id, $record['root_category_id'], 'sms_send_failed', $sms['message'] ?? null, $actor_user_id);
    return array('success' => false, 'message' => $sms['message'] ?? l10n('Unable to send SMS code right now.'));
  }

  $expires_at = profile_liveness_guard_add_interval($now, (int) $settings['challenge_grace_hours'], 'HOUR');
  profile_liveness_guard_store_challenge($record, $code, $expires_at);

  $record = profile_liveness_guard_save_record(array(
    'user_id' => (int) $record['user_id'],
    'root_category_id' => (int) $record['root_category_id'],
    'status' => 'sms_sent',
    'verified_phone' => $phone,
    'challenge_sent_at' => $now,
    'challenge_expires_at' => $expires_at,
    'last_batch_id' => $sms['batch_id'] ?? null,
    'last_msg_id' => $sms['msg_id'] ?? null,
    'last_error' => null,
  ));

  profile_liveness_guard_log_event($user_id, $record['root_category_id'], 'sms_sent', profile_liveness_guard_mask_phone($phone), $actor_user_id);

  return array(
    'success' => true,
    'record' => $record,
    'masked_phone' => profile_liveness_guard_mask_phone($phone),
  );
}

function profile_liveness_guard_confirm_code($user_id, $code, $actor_user_id = null)
{
  $settings = profile_liveness_guard_get_current_conf();
  $context = profile_liveness_guard_ensure_record($user_id);
  if (!$context['success'])
  {
    return $context;
  }

  $record = $context['record'];
  $challenge = profile_liveness_guard_load_challenge($record);
  if ($challenge === null || empty($challenge['code_hash']))
  {
    return array('success' => false, 'message' => l10n('No active verification challenge exists for this profile.'));
  }

  if (!preg_match('/^\d{6}$/', (string) $code) || !pwg_password_verify((string) $code, $challenge['code_hash']))
  {
    profile_liveness_guard_log_event($user_id, $record['root_category_id'], 'code_rejected', null, $actor_user_id);
    return array('success' => false, 'message' => l10n('The verification code is invalid.'));
  }

  $now = profile_liveness_guard_get_now();
  $expired = !empty($record['challenge_expires_at']) && strtotime($record['challenge_expires_at']) < strtotime($now);
  $needs_restore = $expired || 'albums_privatized' === $record['status'];
  if ($needs_restore && !empty($settings['require_admin_restore']))
  {
    $status = 'awaiting_admin_restore';
  }
  else
  {
    $status = 'verified';
  }

  $next_due_at = profile_liveness_guard_add_interval($now, (int) $settings['verification_interval_days'], 'DAY');
  profile_liveness_guard_clear_challenge($record);

  $record = profile_liveness_guard_save_record(array(
    'user_id' => (int) $record['user_id'],
    'root_category_id' => (int) $record['root_category_id'],
    'status' => $status,
    'last_verified_at' => $now,
    'next_due_at' => $next_due_at,
    'challenge_sent_at' => null,
    'challenge_expires_at' => null,
    'last_error' => null,
  ));

  profile_liveness_guard_log_event($user_id, $record['root_category_id'], $status, null, $actor_user_id);
  profile_liveness_guard_disable_sms_2fa($user_id);

  return array('success' => true, 'record' => $record, 'late' => $needs_restore);
}

function profile_liveness_guard_get_owned_tree_album_ids($root_category_id)
{
  $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.(int) $root_category_id.'
     OR CONCAT(",", uppercats, ",") LIKE "%,'.(int) $root_category_id.',%"
  ORDER BY global_rank ASC
;';

  $ids = array();
  $result = pwg_query($query);
  while ($row = pwg_db_fetch_assoc($result))
  {
    $ids[] = (int) $row['id'];
  }

  return $ids;
}

function profile_liveness_guard_make_owner_tree_private($root_category_id, $owner_user_id, $reason)
{
  if (function_exists('cpt_make_owner_tree_private'))
  {
    return cpt_make_owner_tree_private((int) $root_category_id, (int) $owner_user_id, (string) $reason);
  }

  if (!profile_liveness_guard_bootstrap_cpt())
  {
    return array('success' => false, 'message' => l10n('CPT dependency is unavailable.'));
  }

  $album_ids = profile_liveness_guard_get_owned_tree_album_ids($root_category_id);
  foreach ($album_ids as $album_id)
  {
    cpt_update_album((int) $album_id, array('status' => 'private'), false, array('mode' => 'private', 'shared_user_ids' => array()), (int) $owner_user_id);
  }

  return array(
    'success' => true,
    'affected_album_ids' => $album_ids,
  );
}

function profile_liveness_guard_handle_expired_record(array $record, $actor_user_id = null)
{
  $settings = profile_liveness_guard_get_current_conf();
  if (empty($settings['auto_privatize_enabled']))
  {
    return array('success' => false, 'message' => l10n('Automatic privatization is disabled.'));
  }

  $result = profile_liveness_guard_make_owner_tree_private(
    (int) $record['root_category_id'],
    (int) $record['user_id'],
    'profile_liveness_guard_expired'
  );

  if (empty($result['success']))
  {
    $message = $result['message'] ?? l10n('Unable to privatize the owner album tree.');
    profile_liveness_guard_save_record(array(
      'user_id' => (int) $record['user_id'],
      'root_category_id' => (int) $record['root_category_id'],
      'last_error' => $message,
    ));
    profile_liveness_guard_log_event($record['user_id'], $record['root_category_id'], 'privatize_failed', $message, $actor_user_id);
    return array('success' => false, 'message' => $message);
  }

  $record = profile_liveness_guard_save_record(array(
    'user_id' => (int) $record['user_id'],
    'root_category_id' => (int) $record['root_category_id'],
    'status' => 'albums_privatized',
    'albums_privatized_at' => profile_liveness_guard_get_now(),
    'last_error' => null,
  ));
  profile_liveness_guard_log_event($record['user_id'], $record['root_category_id'], 'albums_privatized', null, $actor_user_id);

  return array('success' => true, 'record' => $record, 'affected_album_ids' => $result['affected_album_ids'] ?? array());
}

function profile_liveness_guard_run_due_scan($actor_user_id = null)
{
  $settings = profile_liveness_guard_get_current_conf();
  $summary = array(
    'due_records' => 0,
    'sms_sent' => 0,
    'privatized' => 0,
    'errors' => 0,
  );

  $due_query = '
SELECT *
  FROM '.PROFILE_LIVENESS_GUARD_TABLE.'
  WHERE status = \'verified\'
    AND next_due_at IS NOT NULL
    AND next_due_at <= NOW()
;';
  $due_result = pwg_query($due_query);
  while ($record = pwg_db_fetch_assoc($due_result))
  {
    if (!profile_liveness_guard_is_eligible_user((int) $record['user_id']))
    {
      continue;
    }

    $summary['due_records']++;
    $send = profile_liveness_guard_request_sms((int) $record['user_id'], $actor_user_id, 'due_scan');
    if (!empty($send['success']))
    {
      if (empty($send['duplicate']))
      {
        $summary['sms_sent']++;
      }
    }
    else
    {
      $summary['errors']++;
    }
  }

  $expiry_query = '
SELECT *
  FROM '.PROFILE_LIVENESS_GUARD_TABLE.'
  WHERE status = \'sms_sent\'
    AND challenge_expires_at IS NOT NULL
    AND challenge_expires_at < NOW()
;';
  $expiry_result = pwg_query($expiry_query);
  while ($record = pwg_db_fetch_assoc($expiry_result))
  {
    if (!profile_liveness_guard_is_eligible_user((int) $record['user_id']))
    {
      continue;
    }

    $handled = profile_liveness_guard_handle_expired_record($record, $actor_user_id);
    if (!empty($handled['success']))
    {
      $summary['privatized']++;
    }
    else
    {
      $summary['errors']++;
    }
  }

  return $summary;
}

function profile_liveness_guard_get_status_label($status)
{
  $labels = array(
    'not_started' => l10n('Not enrolled'),
    'due' => l10n('Verification due'),
    'verified' => l10n('Verified'),
    'sms_sent' => l10n('Verification pending'),
    'albums_privatized' => l10n('Albums privatized'),
    'awaiting_admin_restore' => l10n('Awaiting admin restore'),
  );

  return isset($labels[$status]) ? $labels[$status] : $status;
}

function profile_liveness_guard_format_datetime($value)
{
  if (empty($value) || '0000-00-00 00:00:00' === $value)
  {
    return l10n('Not scheduled');
  }

  return format_date($value, array('day', 'month', 'year', 'time'));
}

function profile_liveness_guard_get_profile_view_data($user_id)
{
  if (!profile_liveness_guard_is_eligible_user($user_id))
  {
    return null;
  }

  $record = profile_liveness_guard_get_record($user_id);

  if ($record === null)
  {
    $phone = profile_liveness_guard_get_phone_number($user_id);
    $status_message = empty($phone)
      ? l10n('Set up SMS in Two Factor Authentication first so this profile can receive verification codes.')
      : l10n('No liveness record exists yet for this profile.');

    return array(
      'has_record' => false,
      'status' => 'not_started',
      'status_label' => l10n('Not enrolled'),
      'status_message' => $status_message,
      'last_verified_at' => l10n('Never'),
      'next_due_at' => l10n('Not scheduled'),
      'challenge_expires_at' => null,
      'root_category_id' => null,
      'masked_phone' => profile_liveness_guard_mask_phone($phone),
    );
  }

  $status = $record['status'];
  $message = l10n('This profile is being monitored for periodic SMS ownership confirmation.');

  if ('sms_sent' === $status)
  {
    $message = l10n('A verification SMS challenge is active and awaiting confirmation.');
  }
  else if ('albums_privatized' === $status)
  {
    $message = l10n('The profile album tree has been made private until an administrator restores visibility.');
  }
  else if ('awaiting_admin_restore' === $status)
  {
    $message = l10n('Verification succeeded after expiry and is waiting for administrator restoration.');
  }

  return array(
    'has_record' => true,
    'status' => $status,
    'status_label' => profile_liveness_guard_get_status_label($status),
    'status_message' => $message,
    'last_verified_at' => profile_liveness_guard_format_datetime($record['last_verified_at']),
    'next_due_at' => profile_liveness_guard_format_datetime($record['next_due_at']),
    'challenge_expires_at' => empty($record['challenge_expires_at'])
      ? null
      : profile_liveness_guard_format_datetime($record['challenge_expires_at']),
    'root_category_id' => empty($record['root_category_id']) ? null : (int) $record['root_category_id'],
    'masked_phone' => profile_liveness_guard_mask_phone($record['verified_phone']),
  );
}

function profile_liveness_guard_get_admin_overview()
{
  $overview = array(
    'total' => 0,
    'due' => 0,
    'verified' => 0,
    'sms_sent' => 0,
    'albums_privatized' => 0,
    'awaiting_admin_restore' => 0,
  );

  $query = '
SELECT
    status,
    COUNT(*) AS row_count
  FROM '.PROFILE_LIVENESS_GUARD_TABLE.'
  GROUP BY status
;';

  $result = pwg_query($query);
  while ($row = pwg_db_fetch_assoc($result))
  {
    $status = $row['status'];
    $count = (int) $row['row_count'];

    $overview['total'] += $count;
    if (isset($overview[$status]))
    {
      $overview[$status] = $count;
    }
  }

  return $overview;
}

function profile_liveness_guard_restore_record($user_id, $root_category_id, $actor_user_id)
{
  $record = profile_liveness_guard_get_record((int) $user_id, (int) $root_category_id);
  if ($record === null)
  {
    return array('success' => false, 'message' => l10n('No matching liveness record could be found.'));
  }

  if (($record['status'] ?? '') !== 'awaiting_admin_restore')
  {
    return array('success' => false, 'message' => l10n('Only records awaiting admin restore can be restored.'));
  }

  if (!profile_liveness_guard_bootstrap_cpt())
  {
    return array('success' => false, 'message' => l10n('CPT dependency is unavailable.'));
  }

  $album_ids = profile_liveness_guard_get_owned_tree_album_ids((int) $record['root_category_id']);
  foreach ($album_ids as $album_id)
  {
    cpt_update_album((int) $album_id, array('status' => 'public'), false, array('mode' => 'public'), (int) $record['user_id']);
  }

  $restored_at = profile_liveness_guard_get_now();
  $record = profile_liveness_guard_save_record(array(
    'user_id' => (int) $record['user_id'],
    'root_category_id' => (int) $record['root_category_id'],
    'status' => 'verified',
    'restored_by' => (int) $actor_user_id,
    'restored_at' => $restored_at,
    'last_error' => null,
  ));

  profile_liveness_guard_log_event($record['user_id'], $record['root_category_id'], 'admin_restore_completed', null, $actor_user_id);

  return array(
    'success' => true,
    'record' => $record,
    'affected_album_ids' => $album_ids,
  );
}

function profile_liveness_guard_get_admin_restore_candidates()
{
  $rows = array();

  $query = '
SELECT
    plg.user_id,
    plg.root_category_id,
    plg.status,
    plg.last_verified_at,
    plg.next_due_at,
    plg.albums_privatized_at,
    plg.restored_at,
    u.username
  FROM '.PROFILE_LIVENESS_GUARD_TABLE.' AS plg
    INNER JOIN '.USERS_TABLE.' AS u
      ON u.id = plg.user_id
  WHERE plg.status = \'awaiting_admin_restore\'
  ORDER BY plg.albums_privatized_at DESC, plg.last_verified_at DESC, plg.user_id ASC
;';

  $result = pwg_query($query);
  while ($row = pwg_db_fetch_assoc($result))
  {
    $rows[] = array(
      'user_id' => (int) $row['user_id'],
      'username' => $row['username'],
      'root_category_id' => (int) $row['root_category_id'],
      'status_label' => profile_liveness_guard_get_status_label($row['status']),
      'last_verified_at' => empty($row['last_verified_at']) ? l10n('Never') : profile_liveness_guard_format_datetime($row['last_verified_at']),
      'next_due_at' => empty($row['next_due_at']) ? l10n('Not scheduled') : profile_liveness_guard_format_datetime($row['next_due_at']),
      'albums_privatized_at' => empty($row['albums_privatized_at']) ? l10n('Not scheduled') : profile_liveness_guard_format_datetime($row['albums_privatized_at']),
      'restored_at' => empty($row['restored_at']) ? null : profile_liveness_guard_format_datetime($row['restored_at']),
    );
  }

  return $rows;
}

function profile_liveness_guard_get_admin_recent_logs($limit = 20)
{
  $limit = max(1, (int) $limit);
  $rows = array();

  $query = '
SELECT
    log.user_id,
    log.root_category_id,
    log.event_type,
    log.event_note,
    log.created_at,
    owner.username AS owner_username,
    actor.username AS actor_username
  FROM '.PROFILE_LIVENESS_GUARD_LOG_TABLE.' AS log
    INNER JOIN '.USERS_TABLE.' AS owner
      ON owner.id = log.user_id
    LEFT JOIN '.USERS_TABLE.' AS actor
      ON actor.id = log.actor_user_id
  ORDER BY log.created_at DESC, log.id DESC
  LIMIT '.$limit.'
;';

  $result = pwg_query($query);
  while ($row = pwg_db_fetch_assoc($result))
  {
    $rows[] = array(
      'created_at' => profile_liveness_guard_format_datetime($row['created_at']),
      'owner_username' => $row['owner_username'],
      'root_category_id' => (int) $row['root_category_id'],
      'event_type' => $row['event_type'],
      'event_note' => $row['event_note'],
      'actor_username' => empty($row['actor_username']) ? l10n('System') : $row['actor_username'],
    );
  }

  return $rows;
}