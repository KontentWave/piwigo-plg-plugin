{combine_css path=$PROFILE_LIVENESS_GUARD_PATH|@cat:"admin/template/style.css"}

{html_style}
  h4 {
    text-align:left !important;
  }
{/html_style}


<div lang="{$PLG_LANG_ATTR}"{if $PLG_FONT_STYLE} style="{$PLG_FONT_STYLE}"{/if}>
  <div class="titrePage">
    <h2>{'Profile Liveness Guard'|translate}</h2>
  </div>

  <form method="post" action="" class="properties">
  <fieldset>
    <legend>{'What Profile Liveness Guard can do for me?'|translate}</legend>

    {$INTRO_CONTENT}
  </fieldset>

  <fieldset>
    <legend>{'Current monitoring overview'|translate}</legend>

    <ul>
      <li><strong>{'Tracked profiles'|translate}</strong>: {$PLG_OVERVIEW.total}</li>
      <li><strong>{'Verified'|translate}</strong>: {$PLG_OVERVIEW.verified}</li>
      <li><strong>{'Verification pending'|translate}</strong>: {$PLG_OVERVIEW.sms_sent}</li>
      <li><strong>{'Albums privatized'|translate}</strong>: {$PLG_OVERVIEW.albums_privatized}</li>
      <li><strong>{'Awaiting admin restore'|translate}</strong>: {$PLG_OVERVIEW.awaiting_admin_restore}</li>
    </ul>
  </fieldset>

  <fieldset>
    <legend>{'Awaiting admin restore'|translate}</legend>

    {if not empty($PLG_RESTORE_CANDIDATES)}
    <table class="table2">
      <thead>
        <tr>
          <th>{'User'|translate}</th>
          <th>{'Root album id'|translate}</th>
          <th>{'Status'|translate}</th>
          <th>{'Last verified'|translate}</th>
          <th>{'Albums privatized'|translate}</th>
          <th>{'Action'|translate}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$PLG_RESTORE_CANDIDATES item=record}
        <tr>
          <td>{$record.username}</td>
          <td>{$record.root_category_id}</td>
          <td>{$record.status_label}</td>
          <td>{$record.last_verified_at}</td>
          <td>{$record.albums_privatized_at}</td>
          <td>
            <button type="submit" class="submit" name="plg_restore_record" value="{$record.user_id}:{$record.root_category_id}">{'Restore visibility'|translate}</button>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
    {else}
    <p>{'No profiles are currently waiting for admin restore.'|translate}</p>
    {/if}
  </fieldset>

  <fieldset>
    <legend>{'Recent audit events'|translate}</legend>

    {if not empty($PLG_RECENT_LOGS)}
    <table class="table2">
      <thead>
        <tr>
          <th>{'When'|translate}</th>
          <th>{'User'|translate}</th>
          <th>{'Root album id'|translate}</th>
          <th>{'Event'|translate}</th>
          <th>{'Actor'|translate}</th>
          <th>{'Details'|translate}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$PLG_RECENT_LOGS item=entry}
        <tr>
          <td>{$entry.created_at}</td>
          <td>{$entry.owner_username}</td>
          <td>{$entry.root_category_id}</td>
          <td>{$entry.event_type}</td>
          <td>{$entry.actor_username}</td>
          <td>{if $entry.event_note}{$entry.event_note}{else}-{/if}</td>
        </tr>
        {/foreach}
      </tbody>
    </table>
    {else}
    <p>{'No audit events have been logged yet.'|translate}</p>
    {/if}
  </fieldset>

  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">

  </form>
</div>