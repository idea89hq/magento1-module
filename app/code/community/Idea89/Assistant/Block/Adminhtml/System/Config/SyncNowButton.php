<?php
declare(strict_types=1);
/**
 * Copyright (c) 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Renders the "Sync Catalog Now" button in System > Configuration > IDEA89 > General.
 *
 * Wired in system.xml as the <frontend_model> of the sync_now field.
 * The button POSTs to adminhtml/ideaApi/sync (controller built in Task 5)
 * and displays the number of synced products (or an error) inline.
 *
 * M2 -> M1 substitutions:
 *   Namespace class          -> PEAR_Case flat class name
 *   Constructor DI removed   -> no constructor override needed
 *   getUrl('idea89/...')     -> getUrl('adminhtml/ideaApi/sync') (admin router)
 *   AbstractElement type     -> Varien_Data_Form_Element_Abstract
 *
 * IMPORTANT: _getElementHtml() and render() signatures MUST match the untyped
 * M1 parent (Mage_Adminhtml_Block_System_Config_Form_Field) exactly. Do NOT
 * add return-type hints on overridden methods — PHP will fatal on mismatch.
 */
class Idea89_Assistant_Block_Adminhtml_System_Config_SyncNowButton
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * Render the button + inline JS result indicator.
     *
     * No return type — matches parent signature.
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $url = $this->getUrl('adminhtml/ideaApi/sync');

        return <<<HTML
<button type="button" id="idea89-sync-now" class="scalable" onclick="idea89SyncNow('{$url}')">
    <span>Sync Catalog Now</span>
</button>
<span id="idea89-sync-result" style="margin-left:10px;"></span>
<script type="text/javascript">
function idea89SyncNow(url) {
    var btn = document.getElementById('idea89-sync-now');
    var result = document.getElementById('idea89-sync-result');
    btn.disabled = true;
    result.innerHTML = 'Syncing… (this may take a minute for large catalogs)';
    var body = new FormData();
    body.append('form_key', window.FORM_KEY || '');
    fetch(url, {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}, body: body})
        .then(function(r){return r.json();})
        .then(function(d){
            result.innerHTML = d.ok
                ? '<span style="color:#2c7a2c">&#10003; Synced ' + (d.synced || 0) + ' products</span>'
                : '<span style="color:#c00">&#10007; ' + (d.error || 'Sync failed') + '</span>';
        })
        .catch(function(e){result.innerHTML='<span style="color:#c00">Request failed: ' + e + '</span>';})
        .finally(function(){btn.disabled=false;});
}
</script>
HTML;
    }

    /**
     * Strip scope/inherit checkboxes — this button has no scope value to
     * inherit from default/website, so the "Use Default" checkbox is noise.
     *
     * No return type — matches parent signature.
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }
}
