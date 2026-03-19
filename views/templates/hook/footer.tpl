{**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
{if isset($loaderOrg) && $loaderOrg}
    <script src="{$loaderOrigin|escape:'htmlall':'UTF-8'}/widgets/fullmetrix-loader.js" data-org="{$loaderOrg|escape:'htmlall':'UTF-8'}" defer></script>
{/if}
