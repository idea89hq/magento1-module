<?php
declare(strict_types=1);
/**
 * Copyright (c) 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Renders the "Test Connection" button in System > Configuration > IDEA89 > General.
 *
 * Wired in system.xml as the <frontend_model> of the test_connection field.
 * The button POSTs to adminhtml/ideaApi/test (controller built in Task 5)
 * and displays a pass/fail indicator inline.
 *
 * M2 -> M1 substitutions:
 *   Namespace class          -> PEAR_Case flat class name
 *   Constructor DI removed   -> no constructor override needed
 *   getUrl('idea89/...')     -> getUrl('adminhtml/ideaApi/test') (admin router)
 *   AbstractElement type     -> Varien_Data_Form_Element_Abstract
 *
 * IMPORTANT: _getElementHtml() and render() signatures MUST match the untyped
 * M1 parent (Mage_Adminhtml_Block_System_Config_Form_Field) exactly. Do NOT
 * add return-type hints on overridden methods — PHP will fatal on mismatch.
 */
class Idea89_Assistant_Block_Adminhtml_System_Config_TestConnectionButton
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
        $url = $this->getUrl('adminhtml/ideaApi/test');

        return <<<HTML
<button type="button" id="idea89-test-connection" class="scalable" onclick="idea89TestConnection('{$url}')">
    <span>Test Connection</span>
</button>
<span id="idea89-test-result" style="margin-left:10px;"></span>
<script type="text/javascript">
function idea89TestConnection(url) {
    var btn = document.getElementById('idea89-test-connection');
    var result = document.getElementById('idea89-test-result');
    btn.disabled = true;
    result.innerHTML = 'Testing…';
    var body = new FormData();
    body.append('form_key', window.FORM_KEY || '');
    fetch(url, {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}, body: body})
        .then(function(r){return r.json();})
        .then(function(d){
            result.innerHTML = d.ok
                ? '<span style="color:#2c7a2c">&#10003; Connected</span>'
                : '<span style="color:#c00">&#10007; ' + (d.error || 'Failed') + '</span>';
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
