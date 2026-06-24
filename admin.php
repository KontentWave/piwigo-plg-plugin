<?php
/**
 * This is the main administration page, if you have only one admin page you can put
 * directly its code here or using the tabsheet system like bellow
 */

defined('PROFILE_LIVENESS_GUARD_PATH') or die('Hacking attempt!');

global $template, $page, $conf;

include_once(PROFILE_LIVENESS_GUARD_PATH . 'include/functions.inc.php');


// get current tab
$page['tab'] = isset($_GET['tab']) ? $_GET['tab'] : $page['tab'] = 'home';

// plugin tabsheet is not present on photo page
if ($page['tab'] != 'photo')
{
  // tabsheet
  include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');
  $tabsheet = new tabsheet();
  $tabsheet->set_id('profile_liveness_guard');

  $tabsheet->add('home', l10n('Welcome'), PROFILE_LIVENESS_GUARD_ADMIN . '-home');
  $tabsheet->add('config', l10n('Configuration'), PROFILE_LIVENESS_GUARD_ADMIN . '-config');
  $tabsheet->select($page['tab']);
  $tabsheet->assign();
}

// include page
include(PROFILE_LIVENESS_GUARD_PATH . 'admin/' . $page['tab'] . '.php');

// template vars
$locale = profile_liveness_guard_get_locale_presentation();

$template->assign(array(
  'PROFILE_LIVENESS_GUARD_PATH'=> PROFILE_LIVENESS_GUARD_PATH, // used for images, scripts, ... access
  'PROFILE_LIVENESS_GUARD_ABS_PATH'=> realpath(PROFILE_LIVENESS_GUARD_PATH), // used for template inclusion (Smarty needs a real path)
  'PROFILE_LIVENESS_GUARD_ADMIN' => PROFILE_LIVENESS_GUARD_ADMIN,
  'PLG_LANG_ATTR' => $locale['lang_attr'],
  'PLG_FONT_STYLE' => $locale['font_style'],
  ));

// send page content
$template->assign_var_from_handle('ADMIN_CONTENT', 'profile_liveness_guard_content');
