<?php

namespace PChome24h\PCM\Activity\Promotion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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

        $items = json_decode(json_encode($items), true);
        Log::info($items);

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

        $promotions = json_decode(json_encode($promotions), true);
        Log::info($promotions);

        $firstPromotionRow = null;
        foreach ($promotions as $promotion) {
            if (!in_array($promotion['PROMO_TYPE'], [Promotion::ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT, Promotion::ADDITIONAL_DISCOUNT_BY_AMOUNT])) {
                $firstPromotionRow = $promotion;
                break;
            }
        }

        if ($firstPromotionRow === null) {
            $firstPromotionRow = $promotions[0];
        }

        $tiers = DB::select(DB::raw('SELECT * FROM ECOPER.ACT_PROMO_TIER WHERE PROMO_ID = :PROMO_ID'), [':PROMO_ID' => $firstPromotionRow['PROMO_ID']]);

        $tiers = json_decode(json_encode($tiers), true);
        Log::info($tiers);

        if (!is_array($tiers) || empty($tiers)) {
            return new DiscountedPriceResult(null, null);
        }

        $discountedPrice = $this->calculate($items[0]['IT_PRICE'], $firstPromotionRow, $tiers);
        Log::info($discountedPrice);

        if ($promotion['PROMO_TYPE'] == Promotion::DISCOUNT_BY_ITEM && count($tiers) == 1 && $tiers[0]['PT_THRESHOLD'] == 1) {
            $overlapPromotion = null;

            foreach ($promotions as $promotion) {
                if (in_array($promotion['PROMO_TYPE'], [Promotion::ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT, Promotion::ADDITIONAL_DISCOUNT_BY_AMOUNT])) {
                    $overlapPromotion = $promotion;
                    break;
                }
            }

            if ($overlapPromotion !== null) {
                $tiers = DB::select(DB::raw('SELECT * FROM ECOPER.ACT_PROMO_TIER WHERE PROMO_ID = :PROMO_ID'), [':PROMO_ID' => $overlapPromotion['PROMO_ID']]);

                $tiers = json_decode(json_encode($tiers), true);
                Log::info($tiers);

                if (!is_array($tiers) || empty($tiers)) {
                    return new DiscountedPriceResult(null, null);
                }

                $overlapDiscountedPrice = $this->calculate(
                    $discountedPrice->discountedPrice,
                    $overlapPromotion,
                    $tiers
                );

                Log::info($overlapDiscountedPrice);

                if (isset($overlapDiscountedPrice->discountedPrice)) {
                    $discountedPrice = $overlapDiscountedPrice;
                }
            }
        }

        return $discountedPrice;
    }

    private function getDiscountedPriceFromCache($itemNo)
    {
        $actData = call_user_func(function($itemNo) {
            // $cache = Cache::store('memcached-memstore');

            $prefix = env('MEMCACHED_PC_PREFIX');

            $cacheKey = $prefix.'MktActV1_'.$itemNo;

            Log::info($cacheKey);

            $result = [];

            $cacheData = Cache::get($cacheKey);

            Log::info($cacheData);

            if ($cacheData == false) {
                return false;
            }

            $cacheData = json_decode($cacheData);

            if (empty($cacheData)) {
                return false;
            }

            $prodCache = $cacheData[0];

            $activityCacheKey = $prefix.'MktActInfoV1_'.$prodCache->ActId;

            Log::info($activityCacheKey);
            $cacheData = Cache::get($activityCacheKey);

            Log::info($cacheData);

            if (empty($cacheData)) {
                return false;
            }

            $cacheData = json_decode($cacheData);

            if ($cacheData->StartDtm > date('Y/m/d H:i:s') || $cacheData->EndDtm < date('Y/m/d H:i:s') || $cacheData->Status != 'Progress') {
                $result = [];
            } else {
                $result[] = $cacheData;
            }

            return (count($result) > 0) ?  $result[0] : false;

        }, $itemNo);

        Log::info(json_encode($actData));

        if($actData === false) {
            return new DiscountedPriceResult(null, null);
        }

        if(in_array($actData->ActType, [1, 7]) && $actData->ActLimitX == 1) {
            // 任選打折、滿件打折
            $intLowPrice = $intPrice * ($actData->ActLimitY / 100);
        } else if($actData->ActType == 2 && $actData->ActLimitX == 1) {
            // 任選優惠價
            $intLowPrice = $actData->ActLimitY;
        } else if(in_array($actData->ActType, [3, 8]) && $actData->ActLimitX == 1) {
            // 任選折扣、滿件折扣
            $intLowPrice = $intPrice - $actData->ActLimitY;
        } else if($actData->ActType == 4 && $intPrice >= $actData->ActLimitX) {
            // 滿額打折
            $intLowPrice = $intPrice * ($actData->ActLimitY / 100);

            if (isset($actData->ActTier)) {
                foreach ($actData->ActTier as $objTier) {
                    if ($intPrice >= $objTier->ActLimitX) {
                        $intLowPrice = $intPrice * ($objTier->ActLimitY / 100);
                    }
                }
            }
        } else if($actData->ActType == 5 && $intPrice >= $actData->ActLimitX) {
            // 滿額折扣
            $intLowPrice = $intPrice - $actData->ActLimitY;

            if (isset($actData->ActTier)) {
                foreach ($actData->ActTier as $objTier) {
                    if ($intPrice >= $objTier->ActLimitX) {
                        $intLowPrice = $intPrice - $objTier->ActLimitY;
                    }
                }
            }
        } else if($actData->ActType == 6 && $intPrice >= $actData->ActLimitX) {
            // 滿額折扣無上限
            $intLowPrice = $intPrice - floor($intPrice / $actData->ActLimitX) * $actData->ActLimitY;
        }

        return new DiscountedPriceResult(floor($intLowPrice), $actData->IsDisplayPrice);
    }
}