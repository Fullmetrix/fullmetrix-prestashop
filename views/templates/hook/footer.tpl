{**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
{if isset($forms) && $forms|count > 0}
    {foreach $forms as $form}
        <script src="{$form.origin|escape:'htmlall':'UTF-8'}/forms/fullmetrix-forms.js" data-form-id="{$form.id|escape:'htmlall':'UTF-8'}" data-token="{$form.token|escape:'htmlall':'UTF-8'}" defer></script>
    {/foreach}
{/if}
{if isset($whatsappWidget) && $whatsappWidget}
    <script src="{$whatsappWidget.origin|escape:'htmlall':'UTF-8'}/widgets/fullmetrix-widget.js" data-phone="{$whatsappWidget.phoneNumber|escape:'htmlall':'UTF-8'}" data-message="{$whatsappWidget.welcomeMessage|escape:'htmlall':'UTF-8'}" data-position="{$whatsappWidget.position|escape:'htmlall':'UTF-8'}" data-color="{$whatsappWidget.color|escape:'htmlall':'UTF-8'}" defer></script>
{/if}
