<?php
defined('PROFILE_LIVENESS_GUARD_PATH') or die('Hacking attempt!');

include_once(PROFILE_LIVENESS_GUARD_PATH . 'include/functions.inc.php');

/**
 * add a block in profile page
 */
function profile_liveness_guard_add_profile_block($userdata)
{
  global $template, $page;

  if (empty($userdata['id']))
  {
    return;
  }

  $template_path = realpath(PROFILE_LIVENESS_GUARD_PATH . 'template/profile_liveness_guard_profile_block.tpl');
  if ($template_path === false)
  {
    return;
  }

  $profile_view = profile_liveness_guard_get_profile_view_data((int) $userdata['id']);
  if ($profile_view === null)
  {
    return;
  }

  $locale = profile_liveness_guard_get_locale_presentation();

  $block = array(
    'name' => l10n('Profile Verification'),
    'desc' => l10n('Shows the current liveness verification state for this profile.'),
    'template' => $template_path,
    'standard_show_save' => false,
  );

  $template->assign(array(
    'PLG_LANG_ATTR' => $locale['lang_attr'],
    'PLG_FONT_STYLE' => $locale['font_style'],
    'PLG_PROFILE_STATUS' => $profile_view['status_label'],
    'PLG_PROFILE_STATUS_MESSAGE' => $profile_view['status_message'],
    'PLG_PROFILE_LAST_VERIFIED_AT' => $profile_view['last_verified_at'],
    'PLG_PROFILE_NEXT_DUE_AT' => $profile_view['next_due_at'],
    'PLG_PROFILE_CHALLENGE_EXPIRES_AT' => $profile_view['challenge_expires_at'],
    'PLG_PROFILE_ROOT_CATEGORY_ID' => $profile_view['root_category_id'],
    'PLG_PROFILE_HAS_RECORD' => $profile_view['has_record'],
    'PLG_PROFILE_MASKED_PHONE' => $profile_view['masked_phone'],
    'PLG_PROFILE_FEEDBACK_TYPE' => $page['profile_liveness_guard_feedback']['type'] ?? null,
    'PLG_PROFILE_FEEDBACK_MESSAGE' => $page['profile_liveness_guard_feedback']['message'] ?? null,
  ));

  $template->append('PLUGINS_PROFILE', $block);
}