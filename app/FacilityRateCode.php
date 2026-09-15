<?php

namespace App;

enum FacilityRateCode: string
{
    case Day = 'DAY';
    case Night = 'NIGHT';
    case Both = 'BOTH';
    case Overnight = 'OVERNIGHT';
    case WholeDay = 'WHOLE_DAY';
}
