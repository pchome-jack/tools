<?php

namespace PChome24h\PCM\Activity\Promotion;

use PChome24h\PCM\Activity\Promotion\Constant\Promotion;

class DiscountedPriceCalculator
{
    /**
     * Calculate discounted price based on the type of promotion.
     *
     * @param int $originalPrice
     * @param array $promotion
     * @param array $promotionTiers
     * @return DiscountedPriceResult
     */
    public static function calculate($originalPrice, $promotion, $promotionTiers)
    {
        usort($promotionTiers, function ($a, $b) {
            return ($a['PT_THRESHOLD'] < $b['PT_THRESHOLD']) ? -1 : (($a['PT_THRESHOLD'] > $b['PT_THRESHOLD']) ? 1 : 0);
        });

        $firstPromotionTier = $promotionTiers[0];

        $discountedPrice = null;

        switch ($promotion['PROMO_TYPE']) {
            case Promotion::DISCOUNT_BY_ITEM:
                $discountedPrice = $firstPromotionTier['PT_THRESHOLD'] > 1 ? null : floor($originalPrice * $firstPromotionTier['PT_DISCOUNT']);
                break;
            case Promotion::CASH_DISCOUNT_BY_ITEM:
                $discountedPrice = $firstPromotionTier['PT_THRESHOLD'] > 1 ? null : $originalPrice - $firstPromotionTier['PT_DISCOUNT'];
                break;
            case Promotion::DISCOUNT_BY_AMOUNT:
                $discountedPrice = self::calculateDiscountByPercentage($originalPrice, $promotionTiers);
                break;
            case Promotion::CASH_DISCOUNT_BY_AMOUNT:
                $discountedPrice = self::calculateDiscountByAmount($originalPrice, $promotionTiers);
                break;
            case Promotion::DISCOUNT_BY_SELECTION:
                $discountedPrice = $firstPromotionTier['PT_THRESHOLD'] > 1 ? null : floor($originalPrice * $firstPromotionTier['PT_DISCOUNT']);
                break;
            case Promotion::CASH_DISCOUNT_BY_SELECTION:
                $discountedPrice = $firstPromotionTier['PT_THRESHOLD'] > 1 ? null : $originalPrice - $firstPromotionTier['PT_DISCOUNT'];
                break;
            case Promotion::DISCOUNTED_PRICE_BY_SELECTION:
                $discountedPrice = $firstPromotionTier['PT_THRESHOLD'] > 1 ? null : $firstPromotionTier['PT_DISCOUNT'];
                break;
            case Promotion::ADDITIONAL_DISCOUNT_BY_AMOUNT:
                $discountedPrice = self::calculateDiscountByPercentage($originalPrice, $promotionTiers);
                break;
            case Promotion::ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT:
                $discountedPrice = self::calculateDiscountByAmount($originalPrice, $promotionTiers);
                break;
            default:
        }

        return new DiscountedPriceResult($discountedPrice, $promotion['IS_DISPLAY_PRICE'] == '1');
    }

    private static function calculateDiscountByPercentage($price, $promotionTiers)
    {
        foreach (array_reverse($promotionTiers) as $tier) {
            if ($price >= $tier['PT_THRESHOLD']) {
                return floor($price * $tier['PT_DISCOUNT']);
            }
        }

        return null;
    }

    private static function calculateDiscountByAmount($price, $promotionTiers)
    {
        foreach (array_reverse($promotionTiers) as $tier) {
            if ($price >= $tier['PT_THRESHOLD']) {
                if ($tier['IS_UNLIMITED'] == 1) {
                    $discountCount = floor($price / $tier['PT_THRESHOLD']);

                    return $price - ($discountCount * $tier['PT_DISCOUNT']);
                }

                return $price - $tier['PT_DISCOUNT'];
            }
        }

        return null;
    }
}