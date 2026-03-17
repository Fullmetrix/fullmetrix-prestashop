{**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="panel">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;">
        <h3 style="margin:0;"><i class="icon-list-alt"></i> {l s='Activity log' mod='fullmetrixconnector'}</h3>

        {if $has_logs}
            <form method="post" action="{$admin_url|escape:'htmlall':'UTF-8'}" style="margin:0;">
                <button type="submit" name="submitFullmetrixClearLogs" class="btn btn-default btn-sm">
                    <i class="icon-trash"></i> {l s='Clear logs' mod='fullmetrixconnector'}
                </button>
            </form>
        {/if}
    </div>

    {if !$has_logs}
        <p class="text-muted">{l s='No activity recorded.' mod='fullmetrixconnector'}</p>
    {else}
        <table class="table table-striped" style="font-size:13px;">
            <thead>
                <tr>
                    <th>{l s='Date' mod='fullmetrixconnector'}</th>
                    <th>{l s='Type' mod='fullmetrixconnector'}</th>
                    <th>{l s='Message' mod='fullmetrixconnector'}</th>
                    <th>{l s='Details' mod='fullmetrixconnector'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $logs as $log}
                    <tr>
                        <td style="white-space:nowrap;color:#888;font-size:12px;">{$log.date_added|escape:'htmlall':'UTF-8'}</td>
                        <td><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:{$log.color|escape:'htmlall':'UTF-8'};">{$log.label|escape:'htmlall':'UTF-8'}</span></td>
                        <td>{$log.message|escape:'htmlall':'UTF-8'}</td>
                        <td style="color:#888;font-size:12px;max-width:250px;word-break:break-word;">{$log.details|escape:'htmlall':'UTF-8'}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {/if}
</div>

{if $sync_in_progress}
    <script>setTimeout(function() { location.reload(); }, 10000);</script>
{/if}
