<?php
/*
Plugin Name: profile_liveness_guard
Version: auto
Description: Profile Liveness Guard for periodic SMS ownership verification.
Plugin URI: auto
Author: Mistic
Author URI: http://www.strangeplanet.fr
Has Settings: true
*/

/**
 * This is the main file of the plugin, called by Piwigo in "include/common.inc.php" line 137.
 * At this point of the code, Piwigo is not completely initialized, so nothing should be done directly
 * except define constants and event handlers (see https://github.com/Piwigo/Piwigo/wiki#extension-coding-plugins--themes)
 */

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');


if (basename(dirname(__FILE__)) != 'profile_liveness_guard')
{
  add_event_handler('init', 'profile_liveness_guard_error');
  function profile_liveness_guard_error()
  {
    global $page;
    $page['errors'][] = 'Profile Liveness Guard folder name is incorrect, uninstall the plugin and rename it to "profile_liveness_guard"';
  }
  return;
}


// +-----------------------------------------------------------------------+
// | Define plugin constants                                               |
// +-----------------------------------------------------------------------+
global $prefixeTable;

define('PROFILE_LIVENESS_GUARD_ID',      basename(dirname(__FILE__)));
define('PROFILE_LIVENESS_GUARD_PATH' ,   PHPWG_PLUGINS_PATH . PROFILE_LIVENESS_GUARD_ID . '/');
define('PROFILE_LIVENESS_GUARD_TABLE',   $prefixeTable . 'profile_liveness_guard');
define('PROFILE_LIVENESS_GUARD_LOG_TABLE',   $prefixeTable . 'profile_liveness_guard_log');
define('PROFILE_LIVENESS_GUARD_ADMIN',   get_root_url() . 'admin.php?page=plugin-' . PROFILE_LIVENESS_GUARD_ID);
define('PROFILE_LIVENESS_GUARD_DIR',     PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'profile_liveness_guard/');



// +-----------------------------------------------------------------------+
// | Add event handlers                                                    |
// +-----------------------------------------------------------------------+
// init the plugin
add_event_handler('init', 'profile_liveness_guard_init');

if (!defined('IN_ADMIN'))
{
  $public_file = PROFILE_LIVENESS_GUARD_PATH . 'include/public_events.inc.php';
  $actions_file = PROFILE_LIVENESS_GUARD_PATH . 'include/actions.inc.php';

  add_event_handler('loc_begin_profile', 'profile_liveness_guard_handle_profile_actions',
    EVENT_HANDLER_PRIORITY_NEUTRAL, $actions_file);

  // profile add template block
  add_event_handler('load_profile_in_template', 'profile_liveness_guard_add_profile_block',
    EVENT_HANDLER_PRIORITY_NEUTRAL, $public_file);
}

$ws_file = PROFILE_LIVENESS_GUARD_PATH . 'include/ws_functions.inc.php';
add_event_handler('ws_add_methods', 'profile_liveness_guard_ws_add_methods',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $ws_file);


/**
 * plugin initialization
 *   - check for upgrades
 *   - unserialize configuration
 *   - load language
 */
function profile_liveness_guard_init()
{
  global $conf;

  include_once(PROFILE_LIVENESS_GUARD_PATH . 'include/functions.inc.php');

  // load plugin language file
  load_language('plugin.lang', PROFILE_LIVENESS_GUARD_PATH);

  // prepare plugin configuration
  $stored_conf = empty($conf['profile_liveness_guard'])
    ? array()
    : safe_unserialize($conf['profile_liveness_guard']);

  if (!is_array($stored_conf))
  {
    $stored_conf = array();
  }

  $conf['profile_liveness_guard'] = array_merge(
    profile_liveness_guard_get_default_conf(),
    $stored_conf
  );
}
