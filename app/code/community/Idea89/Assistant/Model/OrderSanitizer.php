<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * PII-safe serializer for Magento order objects. The privacy model keeps
 * order data in the shopper's browser and the merchant's storefront,
 * but the JSON shape the controllers return still needs to be strictly
 * minimal — even an XSS / open-tab attacker reading the response should
 * only see what the customer is allowed to know about themselves.
 *
 * ALWAYS EXCLUDED:
 *   - customer_id
 *   - full email / billing_email / customer_email
 *   - billing or shipping addresses (any field)
 *   - payment information (method, last4, transaction IDs, …)
 *   - per-item prices, discounts, subtotals
 *   - admin comment history / internal notes
 *   - tax / shipping cost breakdowns
 *   - any field starting with `customer_` or `payment_`
 *
 * ALWAYS INCLUDED:
 *   - increment_id (the customer-visible order number)
 *   - placed_at (ISO 8601 UTC)
 *   - status (canonical, see mapStatus)
 *   - status_label (Magento's display string for this status)
 *   - total_formatted (single currency-aware string, no breakdown)
 *   - shipping method title
 *   - item titles + qty (no prices, no SKUs, no images)
 *   - tracking entries (carrier + number + URL) — only in detail mode
 *
 * M2→M1 substitutions:
 *   OrderInterface           → Mage_Sales_Model_Order (positional, typed)
 *   PriceCurrencyInterface   → Mage_Directory_Model_Currency via Mage::getModel()
 *   Constructor DI removed   → static Mage:: accessors
 *   Named arg sanitize(detail:true) → positional sanitize($order, true)
 */
class Idea89_Assistant_Model_OrderSanitizer
{
    /**
     * Canonical statuses surfaced to the widget. Maps from Magento's
     * many internal status codes down to a small, UI-stable set so the
     * status pill has consistent colour semantics across merchants.
     */
    private const STATUS_MAP = [
        'pending'         => 'pending',
        'pending_payment' => 'pending',
        'payment_review'  => 'holding',
        'holded'          => 'holding',
        'processing'      => 'processing',
        'fraud'           => 'holding',
        'complete'        => 'complete',
        'closed'          => 'refunded',
        'canceled'        => 'cancelled',
    ];

    /**
     * Slim shape (detail=false): increment_id + placed_at + status + status_label +
     * total_formatted + item_count + shipping_method.
     * Detail shape (detail=true): all of the above + items[] + tracking[].
     *
     * @return array<string, mixed>
     */
    public function sanitize(Mage_Sales_Model_Order $order, bool $detail = false): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'name' => (string) $item->getName(),
                'qty'  => (int) $item->getQtyOrdered(),
            ];
        }

        $createdAt = (string) $order->getCreatedAt();
        // Magento stores created_at in store timezone-stamped string format.
        // Force to ISO 8601 UTC so the widget doesn't need to guess.
        $placedAt = $createdAt !== ''
            ? gmdate('c', (int) strtotime($createdAt))
            : null;

        $status = (string) $order->getStatus();

        $base = [
            'increment_id'   => (string) $order->getIncrementId(),
            'placed_at'      => $placedAt,
            'status'         => self::mapStatus($status),
            'status_label'   => (string) ($order->getStatusLabel() ?: ucfirst($status)),
            'total_formatted' => $this->formatTotal($order),
            'item_count'     => count($items),
            'shipping_method' => (string) ($order->getShippingDescription() ?? ''),
        ];

        if (!$detail) {
            // List view — slim shape, no items, no tracking. Status colour
            // pill + identifier is enough for the picker UX.
            return $base;
        }

        // Detail view — add the line items + tracking blob, still no
        // prices, no SKUs, no addresses.
        $base['items']    = $items;
        $base['tracking'] = $this->extractTracking($order);
        return $base;
    }

    /**
     * @return array<int, array{carrier:string,carrier_title:string,number:string,url:string|null}>
     */
    private function extractTracking(Mage_Sales_Model_Order $order): array
    {
        $tracking = [];
        /** @var Idea89_Assistant_Model_TrackingUrlResolver $resolver */
        $resolver = Mage::getModel('idea89_assistant/trackingUrlResolver');

        foreach ($order->getTracksCollection() as $track) {
            $carrier = (string) $track->getCarrierCode();
            $number  = (string) $track->getTrackNumber();
            if ($number === '') {
                continue;
            }
            // M1 track objects use Varien_Object magic; method_exists returns
            // false for magic accessors, so explicitUrl will always be '' here
            // and the resolver path is always taken — which is correct behaviour.
            $explicitUrl = method_exists($track, 'getUrl')
                ? (string) $track->getUrl()
                : '';
            $tracking[] = [
                'carrier'       => strtolower($carrier),
                'carrier_title' => (string) ($track->getTitle() ?: $carrier),
                'number'        => $number,
                'url'           => $explicitUrl !== ''
                    ? $explicitUrl
                    : $resolver->resolve($carrier, $number),
            ];
        }
        return $tracking;
    }

    private function formatTotal(Mage_Sales_Model_Order $order): string
    {
        $grand = $order->getGrandTotal();
        if (!is_numeric($grand)) {
            return '';
        }
        $currencyCode = (string) $order->getOrderCurrencyCode();
        try {
            /** @var Mage_Directory_Model_Currency $currency */
            $currency = Mage::getModel('directory/currency');
            $currency->setData('currency_code', $currencyCode);
            // format($price, $options, $includeContainer=false) → plain string, no <span>
            return (string) $currency->format((float) $grand, [], false);
        } catch (\Throwable $e) {
            // Fallback: bare numeric + code so the widget always gets something
            return $currencyCode . ' ' . number_format((float) $grand, 2);
        }
    }

    /**
     * Map Magento's status code → one of the small set the widget
     * understands. Unknown → 'processing' so the UI always renders.
     */
    public static function mapStatus(string $code): string
    {
        $key = strtolower($code);
        return self::STATUS_MAP[$key] ?? 'processing';
    }
}
