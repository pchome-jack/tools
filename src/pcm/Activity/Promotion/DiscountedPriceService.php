<?php

namespace PChome24h\PCM\Activity\Promotion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use PChome24h\PCM\Activity\Promotion\Constant\Promotion;

class DiscountedPriceService
{
    /**
     * 目前僅有mono環境可使用，非mono環境需設定DB及Cache相關連線
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

        $promotions = DB::select(DB::raw('
            SELECT APM.PROMO_ID, APM.PROMO_TYPE, IS_DISPLAY_PRICE
            FROM ECOPER.ACT_PROMO_MAIN APM
            LEFT JOIN ECOPER.ACT_PROMO_ITEM API ON APM.PROMO_ID = API.PROMO_ID
            WHERE API.IT_NO = :IT_NO AND API.STATUS = :STATUS AND APM.STATUS = :STATUS ORDER BY APM.CREDTM DESC'),
            ['IT_NO' => $itemNo, 'STATUS' => '1']
        );

        if (!is_array($promotions) || empty($promotions)) {
            return new DiscountedPriceResult(null, null);
        }

        $promotions = json_decode(json_encode($promotions), true);

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

        if (!is_array($tiers) || empty($tiers)) {
            return new DiscountedPriceResult(null, null);
        }

        $discountedPrice = DiscountedPriceCalculator::calculate($items[0]['IT_PRICE'], $firstPromotionRow, $tiers);

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

                if (!is_array($tiers) || empty($tiers)) {
                    return new DiscountedPriceResult(null, null);
                }

                $overlapDiscountedPrice = DiscountedPriceCalculator::calculate(
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
        $item = DB::select(DB::raw('SELECT IT_PRICE FROM ECOPER.ITEM WHERE IT_NO = :IT_NO AND SIT_NO = :SIT_NO'), [':IT_NO' => $itemNo, ':SIT_NO' => '000']);

        if (!is_array($item) || empty($item)) {
            return new DiscountedPriceResult(null, null);
        }

        $price = $item[0]->IT_PRICE;

        $actData = call_user_func(function($itemNo) {
            $prefix = env('MEMCACHED_PC_PREFIX');

            $cacheKey = $prefix.'MktActV1_'.$itemNo;

            $result = [];

            $cacheData = Cache::get($cacheKey);

            if ($cacheData == false) {
                return false;
            }

            $cacheData = json_decode($cacheData);

            if (empty($cacheData)) {
                return false;
            }

            $prodCache = $cacheData[0];

            $activityCacheKey = $prefix.'MktActInfoV1_'.$prodCache->ActId;

            $cacheData = Cache::get($activityCacheKey);

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

        if($actData === false) {
            return new DiscountedPriceResult(null, null);
        }

        $lowPrice = $price;

        if(in_array($actData->ActType, [1, 7]) && $actData->ActLimitX == 1) {
            // 任選打折、滿件打折
            $lowPrice = $price * ($actData->ActLimitY / 100);
        } else if($actData->ActType == 2 && $actData->ActLimitX == 1) {
            // 任選優惠價
            $lowPrice = $actData->ActLimitY;
        } else if(in_array($actData->ActType, [3, 8]) && $actData->ActLimitX == 1) {
            // 任選折扣、滿件折扣
            $lowPrice = $price - $actData->ActLimitY;
        } else if($actData->ActType == 4 && $price >= $actData->ActLimitX) {
            // 滿額打折
            $lowPrice = $price * ($actData->ActLimitY / 100);

            if (isset($actData->ActTier)) {
                foreach ($actData->ActTier as $objTier) {
                    if ($price >= $objTier->ActLimitX) {
                        $lowPrice = $price * ($objTier->ActLimitY / 100);
                    }
                }
            }
        } else if($actData->ActType == 5 && $price >= $actData->ActLimitX) {
            // 滿額折扣
            $lowPrice = $price - $actData->ActLimitY;

            if (isset($actData->ActTier)) {
                foreach ($actData->ActTier as $objTier) {
                    if ($price >= $objTier->ActLimitX) {
                        $lowPrice = $price - $objTier->ActLimitY;
                    }
                }
            }
        } else if($actData->ActType == 6 && $price >= $actData->ActLimitX) {
            // 滿額折扣無上限
            $lowPrice = $price - floor($price / $actData->ActLimitX) * $actData->ActLimitY;
        }

        return new DiscountedPriceResult(floor($lowPrice), $actData->IsDisplayPrice);
    }
}