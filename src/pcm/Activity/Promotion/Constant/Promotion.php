<?php

namespace PChome24h\PCM\Activity\Promotion\Constant;

class Promotion
{
    /**
     * 活動類型
     */
    const DISCOUNT_BY_ITEM = '0'; // 滿件打折
    const CASH_DISCOUNT_BY_ITEM = '1'; // 滿件折現
    const DISCOUNT_BY_AMOUNT = '2'; // 滿額打折
    const CASH_DISCOUNT_BY_AMOUNT = '3'; // 滿額折現
    const DISCOUNT_BY_SELECTION = '4'; // 任選打折
    const CASH_DISCOUNT_BY_SELECTION = '5'; // 任選折現
    const DISCOUNTED_PRICE_BY_SELECTION = '6'; // 任選優惠價
    const ADDITIONAL_DISCOUNT_BY_AMOUNT = '7'; // 滿額再打折
    const ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT = '8'; // 滿額再折現

    const BY_ITEM_AND_SELECTION = [
        self::DISCOUNT_BY_ITEM,
        self::CASH_DISCOUNT_BY_ITEM,
        self::DISCOUNT_BY_SELECTION,
        self::CASH_DISCOUNT_BY_SELECTION,
        self::DISCOUNTED_PRICE_BY_SELECTION,
    ];

    const BY_AMOUNT = [
        self::DISCOUNT_BY_AMOUNT,
        self::CASH_DISCOUNT_BY_AMOUNT,
        self::ADDITIONAL_DISCOUNT_BY_AMOUNT,
        self::ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT,
    ];

    const ADDITIONAL = [
        self::ADDITIONAL_DISCOUNT_BY_AMOUNT,
        self::ADDITIONAL_CASH_DISCOUNT_BY_AMOUNT,
    ];
}
