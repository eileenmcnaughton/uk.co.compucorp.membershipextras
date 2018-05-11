{foreach from=$settingFields item=elementName}
    <div class="crm-section">
        <div class="label">{$form.$elementName.label} {help id=$elementName file="CRM/MembershipExtras/Form/PaymentPlanSettings.hlp"}</div>
        <div class="content">{$form.$elementName.html}</div>
        <div class="clear"></div>
    </div>
{/foreach}

<div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
