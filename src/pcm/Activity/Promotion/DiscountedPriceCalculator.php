<?php

namespace PChome24h\PCM\Activity\Promotion;

use Illuminate\Support\Facades\DB;
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
    public function calculate($originalPrice, $promotion, $promotionTiers)
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
                $discountedPrice = $this->calculateDiscountByPercentage($originalPrice, $promotionTiers);
                break;
            case Promotion::CASH_DISCOUNT_BY_AMOUNT:
                $discountedPrice = $this->calculateDiscountByAmount($originalPrice, $promotionTiers);
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
                $discountedPrice = $this->calculateDiscountByPercentage($originalPrice, $promotionTiers);
                break;
            case Promotion::ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT:
                $discountedPrice = $this->calculateDiscountByAmount($originalPrice, $promotionTiers);
                break;
            default:
        }

        return new DiscountedPriceResult($discountedPrice, $promotion['IS_DISPLAY_PRICE'] == '1');
    }

    private function calculateDiscountByPercentage($price, $promotionTiers)
    {
        foreach (array_reverse($promotionTiers) as $tier) {
            if ($price >= $tier['PT_THRESHOLD']) {
                return floor($price * $tier['PT_DISCOUNT']);
            }
        }

        return null;
    }

    private function calculateDiscountByAmount($price, $promotionTiers)
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


    /**
     * 目前僅有mono環境可使用，非mono環境需設定DB連線
     *
     * @param string $itemNo
     * @return DiscountedPriceResult
     */
    public function getDiscountedPrice($itemNo)
    {
        if (date('Y/m/d') >= Promotion::SWITCH_DATE)
        {
            return $this->getDiscountedPriceFromDB($itemNo);
        }

        return $this->getDiscountedPriceFromCache($itemNo);
    }

    private function getDiscountedPriceFromDB($itemNo)
    {
        $items = DB::select(
            DB::raw('SELECT IT_PRICE FROM ECOPER.ITEM WHERE IT_NO = :IT_NO AND SIT_NO = :SIT_NO'),
            [':IT_NO' => $itemNo, ':SIT_NO' => '000']
        );

        if (!is_array($items) || empty($items)) {
            return new DiscountedPriceResult(null, null);
        }

        $promotions = DB::select(DB::raw('
            SELECT APM.PROMO_ID, APM.PROMO_TYPE, IS_DISPLAY_PRICE
            FROM ECOPER.ACT_PROMO_MAIN APM
            LEFT JOIN ECOPER.ACT_PROMO_ITEM API ON APM.PROMO_ID = API.PROMO_ID
            WHERE API.IT_NO = :IT_NO AND API.STATUS = :STATUS AND APM.STATUS = :STATUS ORDER BY APM.CREDTM DESC'),
            ['IT_NO' => $itemNo, 'STATUS' => '1'],
        );

        if (!is_array($promotions) || empty($promotions)) {
            return new DiscountedPriceResult(null, null);
        }

        $firstPromotionRow = null;
        foreach ($promotions as $promotion) {
            if (!in_array($promotion->PROMO_TYPE, [Promotion::ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT, Promotion::ADDITIONAL_DISCOUNT_BY_AMOUNT])) {
                $firstPromotionRow = $promotion;
                break;
            }
        }

        if ($firstPromotionRow === null) {
            $firstPromotionRow = $promotions[0];
        }

        $tiers = DB::select(DB::raw('SELECT * FROM ECOPER.ACT_PROMO_TIER WHERE PROMO_ID = :PROMO_ID'), [':PROMO_ID' => $firstPromotionRow->PROMO_ID]);

        if (!is_array($tiers) || empty($tiers)) {
            return new DiscountedPriceResult(null, null);
        }

        $discountedPrice = $this->calculate($items[0]->IT_PRICE, $firstPromotionRow, $tiers);

        if ($promotion->PROMO_TYPE == Promotion::DISCOUNT_BY_ITEM && count($tiers) == 1 && $tiers[0]->PT_THRESHOLD == 1) {
            $overlapPromotion = null;

            foreach ($promotions as $promotion) {
                if (in_array($promotion->PROMO_TYPE, [Promotion::ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT, Promotion::ADDITIONAL_DISCOUNT_BY_AMOUNT])) {
                    $overlapPromotion = $promotion;
                    break;
                }
            }

            if ($overlapPromotion !== null) {
                $tiers = DB::select(DB::raw('SELECT * FROM ECOPER.ACT_PROMO_TIER WHERE PROMO_ID = :PROMO_ID'), [':PROMO_ID' => $overlapPromotion->PROMO_ID]);

                if (!is_array($tiers) || empty($tiers)) {
                    return new DiscountedPriceResult(null, null);
                }

                $overlapDiscountedPrice = $this->calculate(
                    $discountedPrice->discountedPrice,
                    $overlapPromotion,
                    $tiers
                );

                if (isset($overlapDiscountedPrice->discountedPrice)) {
                    $discountedPrice = $overlapDiscountedPrice;
                }
            }
        }

        return $discountedPrice;
    }

    private function getDiscountedPriceFromCache($itemNo)
    {
        return new DiscountedPriceResult(null, null);
    }
}