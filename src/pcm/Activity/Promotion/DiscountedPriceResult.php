<?php

namespace PChome24h\PCM\Activity\Promotion;

class DiscountedPriceResult
{
    public $discountedPrice;
    public $isDisplay;

    public function __construct($discountedPrice, $isDisplay)
    {
        $this->discountedPrice = $discountedPrice;
        $this->isDisplay = $isDisplay;
    }

    public function __toString()
    {
        return json_encode($this);
    }
}