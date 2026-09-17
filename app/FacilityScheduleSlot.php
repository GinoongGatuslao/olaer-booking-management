<?php

namespace App;

enum FacilityScheduleSlot: string
{
    case Overnight = 'overnight';
    case Day = 'day';
    case Night = 'night';
    case WholeDay = 'whole_day';
}
