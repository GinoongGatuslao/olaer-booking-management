<?php

namespace App;

enum FacilityCapacityPolicy: string
{
    case Strict = 'strict';
    case RecommendedInformational = 'recommended_informational';
}
