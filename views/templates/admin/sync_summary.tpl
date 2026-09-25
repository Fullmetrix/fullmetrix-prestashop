{**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="panel" id="fullmetrix-sync">
    <h3 style="margin:0 0 16px;padding:0;border:0;background:none;font-size:18px;font-weight:700;color:#1a1a1a;">{l s='Sync activity' mod='fullmetrixconnector'}</h3>

    {if $sync_in_progress}
        <span style="display:inline-flex;align-items:center;gap:8px;background:#e7f0ff;color:#2469fe;font-size:13px;font-weight:600;padding:8px 16px;border-radius:999px;">
            <span style="width:9px;height:9px;border-radius:999px;background:#2469fe;"></span>
            {l s='Sync in progress' mod='fullmetrixconnector'}
        </span>

    {elseif $last_sync}
        <span style="display:inline-flex;align-items:center;gap:8px;background:#e8f6ee;color:#1a8245;font-size:13px;font-weight:600;padding:8px 16px;border-radius:999px;">
            <span style="width:9px;height:9px;border-radius:999px;background:#1a8245;"></span>
            {l s='Sync complete' mod='fullmetrixconnector'}
        </span>

        {if $last_sync_entities|count > 0}
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:18px;">
                {foreach $last_sync_entities as $entity}
                    <div style="text-align:center;background:#f8f8f8;border:1px solid #e0e0e0;border-radius:8px;padding:10px 16px;min-width:80px;">
                        <div style="font-size:20px;font-weight:700;color:#1a1a1a;line-height:1.2;">{$entity.count_formatted|escape:'htmlall':'UTF-8'}</div>
                        <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;">{$entity.label|escape:'htmlall':'UTF-8'}</div>
                    </div>
                {/foreach}
            </div>
        {/if}

    {else}
        <span style="display:inline-flex;align-items:center;gap:8px;background:#f3f4f6;color:#6b7280;font-size:13px;font-weight:600;padding:8px 16px;border-radius:999px;">
            <span style="width:9px;height:9px;border-radius:999px;background:#9ca3af;"></span>
            {l s='Waiting for sync' mod='fullmetrixconnector'}
        </span>
    {/if}
</div>
