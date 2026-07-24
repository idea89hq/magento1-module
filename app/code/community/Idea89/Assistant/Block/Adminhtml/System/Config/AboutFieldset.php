<?php
declare(strict_types=1);
/**
 * Copyright (c) 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Brand strip rendered above System > Configuration > IDEA89 > General.
 *
 * Wired in etc/system.xml as the <frontend_model> of the empty <about> group.
 * We override render() so the group emits only the brand strip with NO
 * collapsible header, NO group label, NO accordion. Always-visible above the
 * actual settings — establishes vendor identity without consuming a fieldset.
 *
 * M2 -> M1 substitutions:
 *   PackageInfo DI          -> Mage::getConfig()->getModuleConfig() static call
 *   $this->escapeHtml()     -> htmlspecialchars() (Mage_Core_Block_Abstract
 *                              does have escapeHtml but the parent Fieldset
 *                              doesn't call it; we use the built-in directly
 *                              on the version string to keep strict_types safe)
 *   Namespace class         -> PEAR_Case flat class name
 *   Constructor DI removed  -> no constructor override needed
 *
 * IMPORTANT: render() signature MUST match the untyped M1 parent exactly.
 * Do NOT add ": string" return type — PHP will fatal on a strict-type mismatch
 * against the untyped Mage_Adminhtml_Block_System_Config_Form_Fieldset::render().
 */
class Idea89_Assistant_Block_Adminhtml_System_Config_AboutFieldset
    extends Mage_Adminhtml_Block_System_Config_Form_Fieldset
{
    /**
     * Inline SVG for the animated lightbulb brand mark.
     * Kept as a class constant so it isn't re-declared on each render call.
     */
    private const LIGHTBULB_SVG = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="#0B6B47" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <g><line x1="12" y1="1" x2="12" y2="3"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="19.78" y1="4.22" x2="18.36" y2="5.64"/><line x1="21" y1="12" x2="23" y2="12"/></g>
  <path d="M9 21h6M10 17h4M12 3a6 6 0 0 0-4 10.5V16a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.5A6 6 0 0 0 12 3z"/>
  <rect x="9" y="17" width="6" height="2" rx="1" fill="#4A5552" stroke="none"/>
</svg>
SVG;

    /**
     * Skip Magento's standard collapsible fieldset rendering and emit
     * the brand strip directly. Replaces the entire group the merchant
     * would otherwise see (accordion header + empty field table).
     *
     * Signature matches parent (Mage_Adminhtml_Block_System_Config_Form_Fieldset)
     * exactly — no return-type hint, as the parent is untyped PHP 5/7 code.
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $version = (string) Mage::getConfig()->getModuleConfig('Idea89_Assistant')->version;
        if ($version === '') {
            $version = '1.0.0';
        }
        $escVer = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
        $svg = self::LIGHTBULB_SVG;

        // Scoped @keyframes + class prefix avoids collision with anything else
        // on the admin page. The <style> block is inlined because admin pages
        // have no theme entry point we can write to at module build time.
        return <<<HTML
<style>
@keyframes idea89-bulb-glow {
    0%, 100% { filter: drop-shadow(0 0 4px rgba(11, 107, 71, 0.35)); }
    50%      { filter: drop-shadow(0 0 14px rgba(11, 107, 71, 0.75)); }
}
.idea89-about-bulb {
    display:inline-block;
    line-height:0;
    text-decoration:none !important;
    animation: idea89-bulb-glow 2.4s ease-in-out infinite;
    transition: transform 220ms ease;
}
.idea89-about-bulb:hover,
.idea89-about-bulb:focus {
    transform: scale(1.08);
    animation-duration: 1.2s;
    outline: none;
}
.idea89-about-bulb svg {
    display:block;
}
</style>
<div style="
    display:flex; align-items:center; gap:20px;
    margin:0 0 24px;
    padding:20px 24px;
    background:linear-gradient(135deg, #FBFAF6 0%, #F2EFE8 100%);
    border:1px solid #E3DFD3;
    border-left:4px solid #0B6B47;
    border-radius:6px;
    font-family:'Open Sans', 'Helvetica Neue', Arial, sans-serif;
    box-shadow:0 1px 2px rgba(14,22,18,0.04);
">
    <a href="https://idea89.com" target="_blank" rel="noopener"
       title="Visit idea89.com"
       class="idea89-about-bulb"
       style="flex:0 0 auto;">{$svg}</a>
    <div style="flex:1 1 auto; min-width:0;">
        <div style="font-size:18px; font-weight:600; color:#0E1612; letter-spacing:-0.01em; line-height:1.2;">
            IDEA89 — AI Shopping Assistant
            <span style="
                font-size:11px; font-weight:500; color:#4A5552;
                background:#E3DFD3; padding:2px 8px; border-radius:999px;
                margin-left:8px; vertical-align:middle;
            ">v{$escVer}</span>
        </div>
        <div style="font-size:13px; color:#4A5552; margin-top:4px; font-style:italic;">
            Your storefront, now fluent in shopper.
        </div>
        <div style="font-size:12px; color:#4A5552; margin-top:10px;">
            <a href="https://idea89.com/docs" target="_blank" rel="noopener"
               style="color:#0B6B47; text-decoration:none; font-weight:500;">
                Documentation
            </a>
            <span style="margin:0 8px; color:#C4BFB1;">·</span>
            <a href="https://idea89.com" target="_blank" rel="noopener"
               style="color:#0B6B47; text-decoration:none; font-weight:500;">
                Website
            </a>
            <span style="margin:0 8px; color:#C4BFB1;">·</span>
            <a href="mailto:support@idea89.com"
               style="color:#0B6B47; text-decoration:none; font-weight:500;">
                Support
            </a>
            <span style="margin:0 8px; color:#C4BFB1;">·</span>
            <a href="https://app.idea89.com" target="_blank" rel="noopener"
               style="color:#0B6B47; text-decoration:none; font-weight:500;">
                Open dashboard ↗
            </a>
        </div>
    </div>
</div>
HTML;
    }
}
