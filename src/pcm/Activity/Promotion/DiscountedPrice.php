<?php

namespace PChome24h\PCM\Activity\Promotion;

class DiscountedPrice
{
    public int $discountedPrice;
    public bool $isDisplay;

    public function __construct($discountedPrice, $isDisplay)
    {
        $this->discountedPrice = $discountedPrice;
        $this->isDisplay = $isDisplay;
    }
}