{**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="panel">
    <h3><i class="icon-refresh"></i> {l s='Sync activity' mod='fullmetrixconnector'}</h3>

    {if $sync_in_progress}
        <div class="alert alert-info">
            <strong>{l s='Sync in progress' mod='fullmetrixconnector'}{$sync_type_label|escape:'htmlall':'UTF-8'}...</strong>
            ({$sync_elapsed|escape:'htmlall':'UTF-8'})
        </div>
        <p class="text-muted">
            {l s='Fullmetrix is fetching your store data. Refresh this page to track progress.' mod='fullmetrixconnector'}
        </p>

    {elseif $last_sync}
        <div class="alert alert-success">
            <strong>{l s='Last sync' mod='fullmetrixconnector'}:</strong>
            {$last_sync_time_ago|escape:'htmlall':'UTF-8'}
            {if $last_sync_mode}
                — {$last_sync_mode|escape:'htmlall':'UTF-8'}
            {/if}
        </div>

        {if $last_sync_entities|count > 0}
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:15px;">
                {foreach $last_sync_entities as $entity}
                    <div style="text-align:center;background:#f8f8f8;border:1px solid #e0e0e0;border-radius:4px;padding:10px 16px;min-width:80px;">
                        <div style="font-size:20px;font-weight:700;color:#333;">{$entity.count_formatted|escape:'htmlall':'UTF-8'}</div>
                        <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;">{$entity.label|escape:'htmlall':'UTF-8'}</div>
                    </div>
                {/foreach}
            </div>
        {/if}

    {else}
        <div class="alert alert-warning">
            {l s='Waiting for first sync. Start a sync from your Fullmetrix dashboard.' mod='fullmetrixconnector'}
        </div>
    {/if}

    {if $export_count > 0}
        <p class="text-muted" style="margin:0;font-size:12px;">
            {$export_count_formatted|escape:'htmlall':'UTF-8'} {l s='export requests processed in total' mod='fullmetrixconnector'}
        </p>
    {/if}
</div>
