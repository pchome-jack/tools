<?php

namespace PChome24h\PCM\Activity\Promotion;

class DiscountedPrice
{
    public function __construct(
        public int $discountedPrice,
        public bool $isDisplay,
    ) {}
}