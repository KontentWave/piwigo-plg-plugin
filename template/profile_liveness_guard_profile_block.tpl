<div class="plg-profile-block" data-plg-profile-block lang="{$PLG_LANG_ATTR}"{if $PLG_FONT_STYLE} style="{$PLG_FONT_STYLE}"{/if}>
  {if $PLG_PROFILE_FEEDBACK_MESSAGE}
  <p>
    <strong>{if $PLG_PROFILE_FEEDBACK_TYPE == 'success'}{'Status'|translate}{else}{'Error'|translate}{/if}:</strong>
    {$PLG_PROFILE_FEEDBACK_MESSAGE}
  </p>
  {/if}

  <p>{$PLG_PROFILE_STATUS_MESSAGE}</p>

  <ul>
    <li><strong>{'Status'|translate}</strong>: {$PLG_PROFILE_STATUS}</li>
    <li><strong>{'Last verified'|translate}</strong>: {$PLG_PROFILE_LAST_VERIFIED_AT}</li>
    <li><strong>{'Next verification due'|translate}</strong>: {$PLG_PROFILE_NEXT_DUE_AT}</li>
    {if $PLG_PROFILE_MASKED_PHONE}
    <li><strong>{'Verified phone'|translate}</strong>: {$PLG_PROFILE_MASKED_PHONE}</li>
    {/if}
    {if $PLG_PROFILE_CHALLENGE_EXPIRES_AT}
    <li><strong>{'Current challenge expires'|translate}</strong>: {$PLG_PROFILE_CHALLENGE_EXPIRES_AT}</li>
    {/if}
    {if $PLG_PROFILE_ROOT_CATEGORY_ID}
    <li><strong>{'Root album id'|translate}</strong>: {$PLG_PROFILE_ROOT_CATEGORY_ID}</li>
    {/if}
  </ul>

  {if not $PLG_PROFILE_HAS_RECORD}
  <p>{'The first SMS verification flow will create the initial liveness record for this profile.'|translate}</p>
  {/if}

  <p>
    <button type="button" class="submit" value="1" onclick="profileLivenessGuardSubmit(this, 'plg_request_sms'); return false;">{'Send verification SMS'|translate}</button>
  </p>

  <p>
    <label for="plg_verification_code"><strong>{'Verification code'|translate}</strong></label><br>
    <input id="plg_verification_code" type="text" name="plg_verification_code" value="" maxlength="6" pattern="[0-9][0-9][0-9][0-9][0-9][0-9]" inputmode="numeric">
    <button type="button" class="submit" value="1" onclick="profileLivenessGuardSubmit(this, 'plg_verify_code'); return false;">{'Confirm code'|translate}</button>
  </p>
</div>

<script>
if (typeof window.profileLivenessGuardSubmit !== 'function') {
  window.profileLivenessGuardSubmit = function(trigger, actionName) {
    var block = trigger.closest('[data-plg-profile-block]');
    var postForm = document.createElement('form');
    var tokenField = document.querySelector('input[name="pwg_token"], #pwg_token');
    var codeField = block ? block.querySelector('input[name="plg_verification_code"]') : null;

    postForm.method = 'post';
    postForm.action = window.location.href;

    if (tokenField && tokenField.value) {
      var tokenInput = document.createElement('input');
      tokenInput.type = 'hidden';
      tokenInput.name = 'pwg_token';
      tokenInput.value = tokenField.value;
      postForm.appendChild(tokenInput);
    }

    var actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = actionName;
    actionInput.value = trigger.value || '1';
    postForm.appendChild(actionInput);

    if (codeField && codeField.value) {
      var codeInput = document.createElement('input');
      codeInput.type = 'hidden';
      codeInput.name = 'plg_verification_code';
      codeInput.value = codeField.value;
      postForm.appendChild(codeInput);
    }

    document.body.appendChild(postForm);
    postForm.submit();
  };
}
</script>
