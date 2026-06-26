<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * This class is used to expose maintenance methods to the plugins manager
 * It must extends PluginMaintain and be named "PLUGINID_maintain"
 * where PLUGINID is the directory name of your plugin.
 */
class profile_liveness_guard_maintain extends PluginMaintain
{
  private $default_conf = array(
    'verification_interval_days' => 7,
    'challenge_grace_hours' => 48,
    'due_scan_enabled' => true,
    'auto_privatize_enabled' => true,
    'max_send_attempts_per_day' => 3,
    'require_admin_restore' => true,
    'restore_original_privacy' => true,
    'allow_privatize_without_snapshot' => false,
    'debug_log' => false,
  );

  private $table;
  private $log_table;
  private $snapshot_table;
  private $dir;

  function __construct($plugin_id)
  {
    parent::__construct($plugin_id); // always call parent constructor

    global $prefixeTable;

    // Class members can't be declared with computed values so initialization is done here
    $this->table = $prefixeTable . 'profile_liveness_guard';
    $this->log_table = $prefixeTable . 'profile_liveness_guard_log';
    $this->snapshot_table = $prefixeTable . 'profile_liveness_guard_album_snapshot';
    $this->dir = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'profile_liveness_guard/';
  }

  /**
   * Plugin installation
   *
   * Perform here all needed step for the plugin installation such as create default config,
   * add database tables, add fields to existing tables, create local folders...
   */
  function install($plugin_version, &$errors=array())
  {
    global $conf;

    // add config parameter
    if (empty($conf['profile_liveness_guard']))
    {
      // conf_update_param well serialize and escape array before database insertion
      // the third parameter indicates to update $conf['profile_liveness_guard'] global variable as well
      conf_update_param('profile_liveness_guard', $this->default_conf, true);
    }
    else
    {
      $old_conf = safe_unserialize($conf['profile_liveness_guard']);

      if (!is_array($old_conf))
      {
        $old_conf = array();
      }

      conf_update_param(
        'profile_liveness_guard',
        array_merge($this->default_conf, $old_conf),
        true
      );
    }

    // store one liveness record per profile owner
    pwg_query('
CREATE TABLE IF NOT EXISTS `'. $this->table .'` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `root_category_id` mediumint(8) unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT "not_started",
  `verified_phone` varchar(32) DEFAULT NULL,
  `last_verified_at` datetime DEFAULT NULL,
  `next_due_at` datetime DEFAULT NULL,
  `challenge_sent_at` datetime DEFAULT NULL,
  `challenge_expires_at` datetime DEFAULT NULL,
  `last_batch_id` varchar(64) DEFAULT NULL,
  `last_msg_id` varchar(64) DEFAULT NULL,
  `albums_privatized_at` datetime DEFAULT NULL,
  `restored_by` mediumint(8) unsigned DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plg_user_id` (`user_id`),
  KEY `plg_status_due` (`status`, `next_due_at`),
  KEY `plg_status_expiry` (`status`, `challenge_expires_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4
;');

    pwg_query('
CREATE TABLE IF NOT EXISTS `'. $this->log_table .'` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `root_category_id` mediumint(8) unsigned DEFAULT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_note` text DEFAULT NULL,
  `actor_user_id` mediumint(8) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `plg_log_user_created` (`user_id`, `created_at`),
  KEY `plg_log_event_created` (`event_type`, `created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4
;');

    pwg_query('
CREATE TABLE IF NOT EXISTS `'. $this->snapshot_table .'` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `guard_record_id` int(11) unsigned NOT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `root_category_id` mediumint(8) unsigned NOT NULL,
  `album_id` mediumint(8) unsigned NOT NULL,
  `previous_status` varchar(16) NOT NULL,
  `previous_visibility_mode` varchar(16) NOT NULL,
  `previous_shared_user_ids` text DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL,
  `restored_by` mediumint(8) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plg_snapshot_album` (`guard_record_id`, `album_id`),
  KEY `plg_snapshot_record` (`guard_record_id`),
  KEY `plg_snapshot_user_root` (`user_id`, `root_category_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4
;');

    // create a local directory
    if (!file_exists($this->dir))
    {
      mkdir($this->dir, 0755);
    }
  }

  /**
   * Plugin activation
   *
   * This function is triggered after installation, by manual activation or after a plugin update
   * for this last case you must manage updates tasks of your plugin in this function
   */
  function activate($plugin_version, &$errors=array())
  {
  }

  /**
   * Plugin deactivation
   *
   * Triggered before uninstallation or by manual deactivation
   */
  function deactivate()
  {
  }

  /**
   * Plugin (auto)update
   *
   * This function is called when Piwigo detects that the registered version of
   * the plugin is older than the version exposed in main.inc.php
   * Thus it's called after a plugin update from admin panel or a manual update by FTP
   */
  function update($old_version, $new_version, &$errors=array())
  {
    // I (mistic100) chosed to handle install and update in the same method
    // you are free to do otherwize
    $this->install($new_version, $errors);
  }

  /**
   * Plugin uninstallation
   *
   * Perform here all cleaning tasks when the plugin is removed
   * you should revert all changes made in 'install'
   */
  function uninstall()
  {
    // delete configuration
    conf_delete_param('profile_liveness_guard');

    // delete table
    pwg_query('DROP TABLE `'. $this->table .'`;');
    pwg_query('DROP TABLE `'. $this->log_table .'`;');
    pwg_query('DROP TABLE `'. $this->snapshot_table .'`;');

    // delete local folder
    // use a recursive function if you plan to have nested directories
    foreach (scandir($this->dir) as $file)
    {
      if ($file == '.' or $file == '..') continue;
      unlink($this->dir.$file);
    }
    rmdir($this->dir);
  }
}