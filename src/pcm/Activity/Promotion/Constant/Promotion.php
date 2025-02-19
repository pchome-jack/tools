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

    // 新舊活動切換日期
    const SWITCH_DATE = '2025/06/01';
}
