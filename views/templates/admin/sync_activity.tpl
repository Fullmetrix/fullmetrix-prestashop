{**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="panel">
    <h3 style="margin:0 0 16px;padding:0;border:0;background:none;font-size:18px;font-weight:700;color:#1a1a1a;">{l s='Sync activity' mod='fullmetrixconnector'}</h3>

    {if $sync_in_progress}
        <div class="alert alert-info">
            <strong>{l s='Sync in progress' mod='fullmetrixconnector'}{$sync_type_label|escape:'htmlall':'UTF-8'}...</strong>
            ({$sync_elapsed|escape:'htmlall':'UTF-8'})
        </div>
        <p class="text-muted">
            {l s='Fullmetrix is fetching your store data. Refresh this page to track progress.' mod='fullmetrixconnector'}
        </p>

    {elseif $last_sync}
        <div class="alert alert-success" style="display:inline-block;width:auto;max-width:100%;padding-right:22px;">
            <strong>{l s='Last successful sync' mod='fullmetrixconnector'}:</strong>
            {$last_sync_time_ago|escape:'htmlall':'UTF-8'}
        </div>

        {if $last_sync_entities|count > 0}
            <p class="text-muted" style="margin-bottom:8px;">
                {l s='Records sent to Fullmetrix during the last sync:' mod='fullmetrixconnector'}
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:15px;">
                {foreach $last_sync_entities as $entity}
                    <div style="text-align:center;background:#f8f8f8;border:1px solid #e0e0e0;border-radius:4px;padding:10px 16px;min-width:80px;">
                        <div style="font-size:20px;font-weight:700;color:#333;">{$entity.count_formatted|escape:'htmlall':'UTF-8'}</div>
                        <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;">{$entity.label|escape:'htmlall':'UTF-8'}</div>
                    </div>
                {/foreach}
            </div>
        {/if}

        <p class="text-muted" style="margin-bottom:0;font-size:12px;">
            {l s='New orders and changes are synced automatically.' mod='fullmetrixconnector'}
        </p>

    {else}
        <div class="alert alert-info">
            <strong>{l s='No sync recorded yet.' mod='fullmetrixconnector'}</strong>
        </div>
        <p class="text-muted" style="margin-bottom:6px;">
            {l s='Syncs are started from your Fullmetrix dashboard, not from this page. Once your store is connected, the first import runs automatically and new orders are synced as they happen.' mod='fullmetrixconnector'}
        </p>
        <p class="text-muted" style="margin-bottom:0;font-size:12px;">
            {l s='If nothing appears, make sure this store is publicly reachable (a store returning an error page or behind maintenance mode cannot be synced).' mod='fullmetrixconnector'}
        </p>
    {/if}
</div>
