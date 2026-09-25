{*
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="panel" id="fullmetrix-connect" style="padding-bottom:28px;">
    <div style="display:flex;align-items:center;gap:16px;padding-bottom:16px;border-bottom:1px solid #f0f0f0;margin-bottom:20px;">
        <img src="{$fullmetrix_logo|escape:'htmlall':'UTF-8'}" alt="Fullmetrix" style="width:52px;height:52px;border-radius:13px;box-shadow:0 1px 4px rgba(0,0,0,0.12);" />
        <div style="flex:1;min-width:0;">
            <div style="font-size:21px;font-weight:700;color:#1a1a1a;line-height:1.2;">Fullmetrix</div>
            <div style="font-size:13px;color:#6b7280;margin-top:3px;">{l s='E-commerce analytics and reporting' mod='fullmetrixconnector'}</div>
        </div>
    </div>

    <ol style="list-style:none;margin:0 0 22px;padding:0;">
        <li style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;">
            <span style="flex-shrink:0;width:26px;height:26px;border-radius:999px;background:#2469fe;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;">1</span>
            <span style="font-size:14px;color:#333;line-height:1.5;padding-top:2px;">
                {l s='Create your Fullmetrix account at' mod='fullmetrixconnector'}
                <a href="https://fullmetrix.com" target="_blank" rel="noopener" style="color:#2469fe;font-weight:600;">fullmetrix.com</a>
            </span>
        </li>
        <li style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;">
            <span style="flex-shrink:0;width:26px;height:26px;border-radius:999px;background:#2469fe;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;">2</span>
            <span style="font-size:14px;color:#333;line-height:1.5;padding-top:2px;">
                {l s='Copy the connection code shown in your Fullmetrix dashboard' mod='fullmetrixconnector'}
            </span>
        </li>
        <li style="display:flex;align-items:flex-start;gap:12px;margin-bottom:0;">
            <span style="flex-shrink:0;width:26px;height:26px;border-radius:999px;background:#2469fe;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;">3</span>
            <span style="font-size:14px;color:#333;line-height:1.5;padding-top:2px;">
                {l s='Paste the code below and click Connect' mod='fullmetrixconnector'}
            </span>
        </li>
    </ol>

    <form method="post" action="{$form_action|escape:'htmlall':'UTF-8'}" style="margin:0;">
        <label for="fullmetrix-connect-code" style="display:block;font-size:14px;font-weight:600;color:#374151;margin-bottom:8px;">
            {l s='Connection code' mod='fullmetrixconnector'}
        </label>
        <div style="display:flex;gap:10px;align-items:stretch;max-width:560px;">
            <input type="text" id="fullmetrix-connect-code" name="FULLMETRIX_CONNECTION_CODE"
                value="{$connection_code|escape:'htmlall':'UTF-8'}" placeholder="FMTX-XXXX-XXXX-XXXX" required
                style="flex:1;height:52px!important;box-sizing:border-box!important;font-family:monospace;font-size:16px!important;letter-spacing:1px;background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:0 16px!important;color:#333;line-height:52px;" />
            <button type="submit" name="submitFullmetrixConnect" id="fullmetrix-connect-btn"
                style="height:52px!important;background:#2469fe;border:1px solid #2469fe;color:#fff;font-weight:600;font-size:15px;padding:0 30px!important;border-radius:8px;cursor:pointer;white-space:nowrap;">
                {l s='Connect' mod='fullmetrixconnector'}
            </button>
        </div>
    </form>
</div>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        ['.page-head .page-title', '.page-head .page-subtitle'].forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) { el.style.display = 'none'; });
        });
        var btn = document.getElementById('fullmetrix-connect-btn');
        if (btn) {
            btn.addEventListener('mouseenter', function () { btn.style.background = '#173ae1'; btn.style.borderColor = '#173ae1'; });
            btn.addEventListener('mouseleave', function () { btn.style.background = '#2469fe'; btn.style.borderColor = '#2469fe'; });
        }
    });
</script>
