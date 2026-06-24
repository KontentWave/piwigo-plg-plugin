{combine_css path=$PROFILE_LIVENESS_GUARD_PATH|@cat:"admin/template/style.css"}

<div lang="{$PLG_LANG_ATTR}"{if $PLG_FONT_STYLE} style="{$PLG_FONT_STYLE}"{/if}>
  <div class="titrePage">
    <h2>{'Profile Liveness Guard'|translate}</h2>
  </div>

  <form method="post" action="" class="properties">
  <fieldset>
    <legend>{'Common configuration'|translate}</legend>

    <ul>
      <li>
        <label>
          <input type="checkbox" name="due_scan_enabled" value="1" {if $profile_liveness_guard.due_scan_enabled}checked="checked"{/if}>
          <b>{'Enable due scan'|translate}</b>
        </label>
      </li>
      <li>
        <label>
          <b>{'Verification interval (days)'|translate}</b>
          <input type="text" name="verification_interval_days" value="{$profile_liveness_guard.verification_interval_days}" size="4">
        </label>
      </li>
      <li>
        <label>
          <b>{'Challenge grace period (hours)'|translate}</b>
          <input type="text" name="challenge_grace_hours" value="{$profile_liveness_guard.challenge_grace_hours}" size="4">
        </label>
      </li>
      <li>
        <label>
          <input type="checkbox" name="auto_privatize_enabled" value="1" {if $profile_liveness_guard.auto_privatize_enabled}checked="checked"{/if}>
          <b>{'Enable automatic privatization on expiry'|translate}</b>
        </label>
      </li>
    </ul>
  </fieldset>

  <p class="formButtons"><input type="submit" name="save_config" value="{'Save Settings'|translate}"></p>

  </form>
</div>