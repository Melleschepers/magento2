<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\SalesGraphQl\Model\Resolver\CreditMemo;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\SalesGraphQl\Model\SalesItem\ShippingTaxCalculator;
use Magento\Tax\Api\OrderTaxManagementInterface;
use Magento\Tax\Helper\Data as TaxHelper;

/**
 * Resolve credit memo totals information
 */
class CreditMemoTotal implements ResolverInterface
{
    /**
     * @var TaxHelper
     */
    private $taxHelper;

    /**
     * @var OrderTaxManagementInterface
     */
    private $orderTaxManagement;

    /**
     * @var ShippingTaxCalculator
     */
    private $shippingTaxCalculator;
    /**
     * @param OrderTaxManagementInterface $orderTaxManagement
     * @param TaxHelper $taxHelper
     * @param ShippingTaxCalculator $shippingTaxCalculator
     */
    public function __construct(
        OrderTaxManagementInterface $orderTaxManagement,
        TaxHelper $taxHelper,
        ShippingTaxCalculator $shippingTaxCalculator
    ) {
        $this->taxHelper = $taxHelper;
        $this->orderTaxManagement = $orderTaxManagement;
        $this->shippingTaxCalculator = $shippingTaxCalculator;
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!(($value['model'] ?? null) instanceof CreditmemoInterface)) {
            throw new LocalizedException(__('"model" value should be specified'));
        }

        if (!(($value['order'] ?? null) instanceof OrderInterface)) {
            throw new LocalizedException(__('"order" value should be specified'));
        }

        /** @var OrderInterface $orderModel */
        $orderModel = $value['order'];
        /** @var CreditmemoInterface $creditMemo */
        $creditMemo = $value['model'];
        $currency = $orderModel->getOrderCurrencyCode();
        $baseCurrency = $orderModel->getBaseCurrencyCode();
        return [
            'base_grand_total' => ['value' => $creditMemo->getBaseGrandTotal(), 'currency' => $baseCurrency],
            'grand_total' => ['value' =>  $creditMemo->getGrandTotal(), 'currency' => $currency],
            'subtotal' => ['value' =>  $creditMemo->getSubtotal(), 'currency' => $currency],
            'total_tax' => ['value' =>  $creditMemo->getTaxAmount(), 'currency' => $currency],
            'total_shipping' => ['value' => $creditMemo->getShippingAmount(), 'currency' => $currency],
            'discounts' => $this->getDiscountDetails($creditMemo),
            'taxes' => $this->formatTaxes(
                $orderModel,
                $this->taxHelper->getCalculatedTaxes($creditMemo),
            ),
            'shipping_handling' => [
                'amount_excluding_tax' => [
                    'value' => $creditMemo->getShippingAmount() ?? 0,
                    'currency' => $currency
                ],
                'amount_including_tax' => [
                    'value' => $creditMemo->getShippingInclTax() ?? 0,
                    'currency' => $currency
                ],
                'total_amount' => [
                    'value' => $creditMemo->getShippingAmount() ?? 0,
                    'currency' => $currency
                ],
                'discounts' => $this->getShippingDiscountDetails($creditMemo),
                'taxes' => $this->formatTaxes(
                    $orderModel,
                    $this->shippingTaxCalculator->calculateShippingTaxes($orderModel, $creditMemo),
                )
            ],
            'adjustment' => [
                'value' =>  abs($creditMemo->getAdjustment()),
                'currency' => $currency
            ],
        ];
    }

    /**
     * Return information about an applied discount on shipping
     *
     * @param CreditmemoInterface $creditmemo
     * @return array
     */
    private function getShippingDiscountDetails(CreditmemoInterface $creditmemo)
    {
        $shippingDiscounts = [];
        if (!($creditmemo->getDiscountDescription() === null
            && $creditmemo->getShippingDiscountTaxCompensationAmount() == 0)) {
            $shippingDiscounts[] =
                [
                    'label' => $creditmemo->getDiscountDescription() ?? __('Discount'),
                    'amount' => [
                        'value' => abs($creditmemo->getShippingDiscountTaxCompensationAmount()),
                        'currency' => $creditmemo->getOrderCurrencyCode()
                    ]
                ];
        }
        return $shippingDiscounts;
    }

    /**
     * Return information about an applied discount
     *
     * @param CreditmemoInterface $creditmemo
     * @return array
     */
    private function getDiscountDetails(CreditmemoInterface $creditmemo)
    {
        $discounts = [];
        if (!($creditmemo->getDiscountDescription() === null && $creditmemo->getDiscountAmount() == 0)) {
            $discounts[] = [
                'label' => $creditmemo->getDiscountDescription() ?? __('Discount'),
                'amount' => [
                    'value' => abs($creditmemo->getDiscountAmount()),
                    'currency' => $creditmemo->getOrderCurrencyCode()
                ]
            ];
        }
        return $discounts;
    }

    /**
     * Format applied taxes
     *
     * @param OrderInterface $order
     * @param array $appliedTaxes
     * @return array
     */
    private function formatTaxes(OrderInterface $order, array $appliedTaxes)
    {
        $taxes = [];
        foreach ($appliedTaxes as $appliedTax) {
            $appliedTaxesArray = [
                'rate' => $appliedTax['percent'] ?? 0,
                'title' => $appliedTax['title'] ?? null,
                'amount' => [
                    'value' => $appliedTax['tax_amount'] ?? 0,
                    'currency' => $order->getOrderCurrencyCode()
                ]
            ];
            $taxes[] = $appliedTaxesArray;
        }
        return $taxes;
    }
}
