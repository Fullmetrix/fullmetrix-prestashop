{**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<ul class="nav nav-tabs" role="tablist">
    <li class="active"><a href="#fullmetrix-tab-connection" data-toggle="tab">{l s='Connection' mod='fullmetrixconnector'}</a></li>
    <li><a href="#fullmetrix-tab-logs" data-toggle="tab">{l s='Logs' mod='fullmetrixconnector'}</a></li>
</ul>
<div class="tab-content">
    <div class="tab-pane active" id="fullmetrix-tab-connection">
        {$form_html nofilter}
        {$sync_html nofilter}
    </div>
    <div class="tab-pane" id="fullmetrix-tab-logs">
        {$logs_html nofilter}
    </div>
</div>
