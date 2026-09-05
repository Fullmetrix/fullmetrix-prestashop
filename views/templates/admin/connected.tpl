{*
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="panel" id="fullmetrix-connected">
    <div style="display:flex;align-items:center;gap:16px;padding-bottom:16px;border-bottom:1px solid #f0f0f0;margin-bottom:18px;">
        <img src="{$fullmetrix_logo|escape:'htmlall':'UTF-8'}" alt="Fullmetrix" style="width:52px;height:52px;border-radius:13px;box-shadow:0 1px 4px rgba(0,0,0,0.12);" />
        <div style="flex:1;min-width:0;">
            <div style="font-size:21px;font-weight:700;color:#1a1a1a;line-height:1.2;">Fullmetrix</div>
            <div style="font-size:13px;color:#6b7280;margin-top:3px;">{l s='E-commerce analytics and reporting' mod='fullmetrixconnector'}</div>
        </div>
        <span style="display:inline-flex;align-items:center;gap:7px;background:#e8f6ee;color:#1a8245;font-size:13px;font-weight:600;padding:8px 16px;border-radius:999px;white-space:nowrap;">
            <span style="width:9px;height:9px;border-radius:999px;background:#1a8245;"></span>
            {l s='Connected' mod='fullmetrixconnector'}
        </span>
    </div>

    <label for="fullmetrix-code" style="display:block;font-size:14px;font-weight:600;color:#374151;margin-bottom:8px;">
        {l s='Connection code' mod='fullmetrixconnector'}
    </label>
    <form method="post" action="{$form_action|escape:'htmlall':'UTF-8'}" style="margin:0;display:flex;gap:10px;align-items:stretch;max-width:640px;">
        <input type="text" id="fullmetrix-code" value="{$connection_code|escape:'htmlall':'UTF-8'}" readonly
            style="flex:1;height:50px!important;box-sizing:border-box!important;font-family:monospace;font-size:16px!important;letter-spacing:1px;background:#f8f9fa;border:1px solid #dfe3e8;border-radius:8px;padding:0 16px!important;color:#333;line-height:50px;" />
        <button type="submit" name="submitFullmetrixDisconnect" class="btn btn-default"
            style="height:50px!important;padding:0 22px!important;font-size:14px;white-space:nowrap;"
            onclick="return confirm('{l s='Disconnect this store from Fullmetrix?' mod='fullmetrixconnector' js=1}');">
            {l s='Disconnect' mod='fullmetrixconnector'}
        </button>
    </form>
</div>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        ['.page-head .page-title', '.page-head .page-subtitle'].forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) { el.style.display = 'none'; });
        });
    });
</script>
